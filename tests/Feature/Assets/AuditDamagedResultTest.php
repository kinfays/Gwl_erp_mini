<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Audits\AuditShow;
use App\Livewire\Assets\Audits\AuditsList;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use App\Models\IctAssetAuditLine;
use App\Models\IctAssetTransfer;
use App\Services\Assets\AssetAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class AuditDamagedResultTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_damaged_is_one_of_the_results_with_its_own_label_and_a_colour_of_its_own(): void
    {
        $this->assertContains('damaged', IctAssetAuditLine::RESULTS);
        $this->assertSame('Damaged', IctAssetAuditLine::labelFor('damaged'));
        $this->assertSame('Pending', IctAssetAuditLine::labelFor(null));

        // Damaged is an alarm colour (danger), like Not found; matched and mismatch keep theirs.
        $this->assertSame('danger', IctAssetAuditLine::toneFor('damaged'));
        $this->assertSame('success', IctAssetAuditLine::toneFor('matched'));
        $this->assertSame('warning', IctAssetAuditLine::toneFor('mismatch'));
    }

    public function test_marking_damaged_needs_a_reason_and_records_what_was_found(): void
    {
        [$audit, $lines, $assets] = $this->startAudit(1);
        $service = app(AssetAuditService::class);

        try {
            $service->recordResult($lines[0], 'damaged', [], null, auth()->id());
            $this->fail('A damaged result without a reason was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        // Nothing was written by the refused attempt.
        $this->assertNull($lines[0]->fresh()->result);
        $this->assertSame(IctAsset::STATUS_ACTIVE, $assets[0]->fresh()->status);

        // Holder, district and status sent along are ignored: the line records "found, damaged".
        $service->recordResult($lines[0], 'damaged', ['status' => 'Active', 'assigned_to_employee_id' => 999, 'district_id' => 999, 'location' => 'Server room'], 'Cracked screen', auth()->id());

        $line = $lines[0]->fresh();
        $this->assertSame('damaged', $line->result);
        $this->assertSame(IctAsset::STATUS_DAMAGED, $line->actual_status);
        $this->assertNull($line->actual_assigned_to_employee_id);
        $this->assertNull($line->actual_district_id);
        $this->assertSame('Server room', $line->actual_location);
        $this->assertSame('Cracked screen', $line->mismatch_reason);
    }

    public function test_marking_damaged_sets_the_assets_own_status_with_history_and_locks_the_line(): void
    {
        [$audit, $lines, $assets] = $this->startAudit(2);
        $service = app(AssetAuditService::class);

        $service->recordResult($lines[0], 'damaged', [], 'Cracked screen', auth()->id());

        $asset = $assets[0]->fresh();
        $this->assertSame(IctAsset::STATUS_DAMAGED, $asset->status);
        $this->assertSame('Cracked screen', $asset->status_reason);

        // The same trail as editing the status: a transfer row and an audit snapshot.
        $this->assertTrue(IctAssetTransfer::query()->where('ict_asset_id', $asset->id)->where('from_status', 'Active')->where('to_status', 'Damaged')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'update_asset')->where('target_id', $asset->id)->exists());

        // The line is locked like an applied correction, so the asset cannot be left Damaged by a changed result.
        $this->assertTrue($lines[0]->fresh()->correction_applied);
        try {
            $service->recordResult($lines[0]->fresh(), 'matched', [], null, auth()->id());
            $this->fail('A locked line was changed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('result', $e->errors());
        }

        // The other asset is untouched.
        $this->assertSame(IctAsset::STATUS_ACTIVE, $assets[1]->fresh()->status);
    }

    public function test_an_asset_that_is_already_damaged_is_left_alone_and_the_line_stays_editable(): void
    {
        [$audit, $lines, $assets] = $this->startAudit(1);
        $assets[0]->update(['status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Dropped last week']);
        $service = app(AssetAuditService::class);

        $service->recordResult($lines[0], 'damaged', [], 'Still cracked', auth()->id());

        $this->assertSame('Dropped last week', $assets[0]->fresh()->status_reason);
        $this->assertFalse($lines[0]->fresh()->correction_applied);
        $this->assertSame(0, IctAssetTransfer::query()->where('ict_asset_id', $assets[0]->id)->count());

        $service->recordResult($lines[0]->fresh(), 'matched', [], null, auth()->id());
        $this->assertSame('matched', $lines[0]->fresh()->result);
    }

    public function test_a_head_office_user_who_reads_every_region_cannot_mark_another_regions_asset_damaged(): void
    {
        [$audit, $lines, $assets] = $this->startAudit(1);
        $home = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $headOffice = $this->ictUser($home, 'ICTHO');
        Employee::query()->where('staff_id', 'ICTHO')->update(['location_type' => 'HeadOffice']);

        // Reading is wider than writing: they can open the audit but not change the asset through it.
        Livewire::actingAs($headOffice->fresh())->test(AuditShow::class, ['audit' => $audit])
            ->call('openRecord', $lines[0]->id, 'damaged')
            ->set('reason', 'Cracked')
            ->call('saveRecord')
            ->assertForbidden();

        $this->assertSame(IctAsset::STATUS_ACTIVE, $assets[0]->fresh()->status);
        $this->assertNull($lines[0]->fresh()->result);
    }

    public function test_a_damaged_line_cannot_be_corrected_onto_the_asset(): void
    {
        [$audit, $lines] = $this->startAudit(1);
        $service = app(AssetAuditService::class);
        $service->recordResult($lines[0], 'damaged', [], 'Water damage', auth()->id());

        $this->expectException(ValidationException::class);

        $service->applyCorrection($lines[0]->fresh(), auth()->id());
    }

    public function test_the_five_categories_add_up_to_the_number_of_assets_audited(): void
    {
        [$audit, $lines] = $this->startAudit(6);
        $service = app(AssetAuditService::class);

        $service->recordResult($lines[0], 'matched', [], null, auth()->id());
        $service->recordResult($lines[1], 'matched', [], null, auth()->id());
        $service->recordResult($lines[2], 'mismatch', ['status' => 'Lost'], 'Elsewhere', auth()->id());
        $service->recordResult($lines[3], 'not_found', [], 'Missing', auth()->id());
        $service->recordResult($lines[4], 'damaged', [], 'Broken hinge', auth()->id());
        // lines[5] stays pending

        $summary = $service->summary($audit);

        $this->assertSame(['total' => 6, 'verified' => 5, 'pending' => 1, 'matched' => 2, 'mismatch' => 1, 'not_found' => 1, 'damaged' => 1], $summary);
        $this->assertSame($summary['total'], $summary['pending'] + $summary['matched'] + $summary['mismatch'] + $summary['not_found'] + $summary['damaged']);

        $service->recordResult($lines[5], 'damaged', [], 'Dropped', auth()->id());
        $service->complete($audit);

        // Damaged is not matched: 2 of 6 reconcile.
        $this->assertSame('33.33', (string) $audit->fresh()->reconciliation_rate);
        $this->assertSame(2, $service->summary($audit)['damaged']);
    }

    public function test_the_exports_name_the_result_damaged(): void
    {
        [$audit, $lines] = $this->startAudit(2);
        $service = app(AssetAuditService::class);
        $service->recordResult($lines[0], 'matched', [], null, auth()->id());
        $service->recordResult($lines[1], 'damaged', [], 'Cracked screen', auth()->id());
        $service->complete($audit);

        $rows = $service->exportRows($audit->fresh());

        $this->assertSame(['Matched', 'Damaged'], $rows->pluck('result')->all());
        $this->assertSame('Damaged', $rows[1]['actual_status']);
        $this->assertSame('Cracked screen', $rows[1]['reason']);

        // The PDF is built from the same summary and rows (it prints the damaged count).
        $response = $this->get(route('assets.audits.export.pdf', $audit))->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->get(route('assets.audits.export.excel', $audit))->assertOk();
    }

    public function test_the_audit_screen_offers_damaged_filters_by_it_and_shows_its_tile(): void
    {
        [$audit, $lines] = $this->startAudit(3);

        $component = Livewire::test(AuditShow::class, ['audit' => $audit])
            ->assertSee('Damaged')
            ->call('markMatched', $lines[0]->id)
            ->call('openRecord', $lines[1]->id, 'damaged')
            ->assertSee('Asset damaged')
            ->set('reason', '')
            ->call('saveRecord')
            ->assertHasErrors(['reason'])
            ->set('reason', 'Keys missing')
            ->set('actualLocation', 'Reception')
            ->call('saveRecord')
            ->assertHasNoErrors()
            ->assertSee('Keys missing')
            ->set('resultFilter', 'damaged');

        $this->assertSame([$lines[1]->id], $component->viewData('lines')->pluck('id')->all());
        $this->assertSame(1, $component->viewData('summary')['damaged']);

        $component->set('resultFilter', '')
            ->call('openRecord', $lines[2]->id, 'mismatch')
            ->set('reason', 'Wrong desk')
            ->call('saveRecord')
            ->call('completeAudit')
            ->assertHasNoErrors()
            ->assertSee('Damaged');

        // Other result values are still refused.
        Livewire::test(AuditShow::class, ['audit' => $audit])->call('openRecord', $lines[0]->id, 'broken')->assertStatus(422);
    }

    public function test_the_asset_page_shows_the_damaged_result(): void
    {
        [$audit, $lines, $assets] = $this->startAudit(1);
        app(AssetAuditService::class)->recordResult($lines[0], 'damaged', [], 'Cracked', auth()->id());

        $this->get(route('assets.show', $assets[0]))->assertOk()->assertSee('Damaged')->assertSee('Cracked');
    }

    /** @return array{0: IctAssetAudit, 1: \Illuminate\Support\Collection, 2: array<int, IctAsset>} */
    protected function startAudit(int $count): array
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $assets = [];
        foreach (range(1, $count) as $i) {
            $assets[] = $this->makeAsset($district, ['asset_name' => 'Audited asset '.$i]);
        }

        Livewire::test(AuditsList::class)->call('openCreate')->set('title', 'Stock check')->call('create')->assertHasNoErrors();
        $audit = IctAssetAudit::query()->sole();

        return [$audit, $audit->lines()->orderBy('id')->get(), $assets];
    }
}
