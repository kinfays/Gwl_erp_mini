<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BatchShow;
use App\Livewire\Commercial\Batches;
use App\Models\AuditLog;
use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\CommercialLocationAlias;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

class BillingImportTest extends CommercialTestCase
{
    public function test_a_valid_billing_report_is_imported_into_routes_and_bands(): void
    {
        $this->actingAs($this->officer());

        $batch = $this->importFile($this->billingFile());

        $this->assertSame(CommercialImportBatch::TYPE_BILLING_SUMMARY, $batch->report_type);
        $this->assertSame($this->accraWest->id, $batch->region_id);
        $this->assertSame('2026-06-01', $batch->period_from->toDateString());
        $this->assertSame('2026-08-31', $batch->period_to->toDateString());
        $this->assertSame(CommercialImportBatch::GRANULARITY_MULTI_MONTH, $batch->granularity);
        $this->assertSame('New Service Customers Only', $batch->billing_status_raw);
        $this->assertSame(CommercialImportBatch::SEGMENT_NEW_SERVICE, $batch->customer_segment);
        $this->assertSame(4, $batch->row_count);
        $this->assertSame(4, $batch->matched_count);
        $this->assertTrue($batch->reconciliation_passed);

        $route = $batch->routes()->where('route_code', 'SOWUTUOM 4602')->firstOrFail();
        $this->assertSame($this->sowutuom->id, $route->district_id);
        $this->assertSame('SOWUTUOM', $route->district_label_raw);
        $this->assertSame('12.00', $route->volume_total);
        $this->assertSame('-100.00', $route->opening_balance);
        $this->assertSame('250.50', $route->billing_for_period);
        $this->assertSame('150.50', $route->total_receivable);
        $this->assertSame('160.25', $route->total_payments);
        $this->assertSame('-9.75', $route->closing_balance);
        $this->assertSame(9, $route->billed_total);
        $this->assertSame(2, $route->unbilled_total);

        $zero = $batch->routes()->where('route_code', 'ODORKOR 4701')->firstOrFail();
        $this->assertSame('0.00', $zero->billing_for_period, 'the #VALUE! collection ratio is not an error and is not stored');

        $this->assertSame(
            [['<=5', 80, '410.50', '3450.25'], ['>5', 20, '300.75', '3460.00']],
            $batch->bands()->orderBy('id')->get()->map(fn (CommercialBillingBand $band) => [$band->band, $band->customers, $band->volume, $band->amount])->all()
        );
        $this->assertSame('611', $batch->bands()->first()->category_code);
    }

    public function test_a_report_spanning_several_months_warns_that_it_cannot_feed_a_monthly_trend(): void
    {
        $this->actingAs($this->officer());

        $preview = $this->previewOf($this->billingFile());

        $this->assertFalse($preview['blocked']);
        $this->assertStringContainsString('not a monthly trend', collect($preview['warnings'])->pluck('message')->implode(' '));

        $single = $this->previewOf($this->billingFile(['period' => 'June-2026']));
        $this->assertSame('2026-06-30', $single['period']['to']);
        $this->assertStringNotContainsString('monthly trend', collect($single['warnings'])->pluck('message')->implode(' '));
    }

    public function test_a_route_that_does_not_balance_blocks_the_file(): void
    {
        $this->actingAs($this->officer());

        $districts = ReportWorkbooks::defaultDistricts();
        $districts['SOWUTUOM'][0]['closing'] = 999.0;

        $preview = $this->previewOf($this->billingFile(['districts' => $districts]));

        $this->assertTrue($preview['blocked']);
        $messages = collect($preview['errors'])->pluck('message')->implode(' ');
        $this->assertStringContainsString('SOWUTUOM 4601', $messages);
        $this->assertStringContainsString('Closing Balance 999.00 is not Total Receivable - Total Payments', $messages);
    }

    public function test_every_route_identity_is_checked(): void
    {
        $this->actingAs($this->officer());

        foreach ([
            ['receivable' => 1.0, 'Total Receivable'],
            ['payments' => 1.0, 'Total Payments'],
            ['billed_total' => 99, 'Total Billed'],
            ['unbilled_total' => 99, 'Total Unbilled'],
        ] as $broken) {
            $label = array_pop($broken);
            $districts = ReportWorkbooks::defaultDistricts();
            $districts['ODORKOR'][1] = [...$districts['ODORKOR'][1], ...$broken];

            $preview = $this->previewOf($this->billingFile(['districts' => $districts]));

            $this->assertTrue($preview['blocked'], "{$label} should block");
            $this->assertStringContainsString($label, collect($preview['errors'])->pluck('message')->implode(' '));
        }
    }

    public function test_routes_that_do_not_add_up_to_their_district_or_to_report_totals_block_the_file(): void
    {
        $this->actingAs($this->officer());

        $district = $this->previewOf($this->billingFile(['district_totals' => ['SOWUTUOM' => ['billing' => 5.0]]]));
        $this->assertTrue($district['blocked']);
        $this->assertStringContainsString('Billing For Period: the routes add up to 350.50 but the SOWUTUOM totals row shows 355.50', collect($district['errors'])->pluck('message')->implode(' '));

        $report = $this->previewOf($this->billingFile(['report_totals' => ['payments' => -3.0]]));
        $this->assertTrue($report['blocked']);
        $this->assertStringContainsString('REPORT TOTALS shows', collect($report['errors'])->pluck('message')->implode(' '));
    }

    public function test_a_missing_column_header_is_a_layout_error_not_a_silent_zero(): void
    {
        $this->actingAs($this->officer());

        $preview = $this->previewOf($this->billingFile(['blank_header' => 'Prev Month Payment']));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('Could not find the column Prev Month Payment', collect($preview['errors'])->pluck('message')->implode(' '));
    }

    public function test_separate_filter_cells_are_read_too(): void
    {
        $this->actingAs($this->officer());

        $preview = $this->previewOf($this->billingFile(['filters' => 'cells']));

        $this->assertFalse($preview['blocked'], collect($preview['errors'])->pluck('message')->implode(' | '));
        $this->assertSame('new_service', $preview['segment']);
        $this->assertSame('Accra West', $preview['region']['name']);
    }

    public function test_a_report_with_no_billing_status_line_is_stored_as_the_all_segment(): void
    {
        $this->actingAs($this->officer());

        $this->assertSame('all', $this->previewOf($this->billingFile(['status' => null]))['segment']);
    }

    public function test_the_district_name_may_come_from_the_totals_row_when_the_header_just_says_district(): void
    {
        $this->actingAs($this->officer());

        $batch = $this->importFile($this->billingFile(['district_header' => 'District']));

        $this->assertEqualsCanonicalizing(['SOWUTUOM', 'ODORKOR'], $batch->routes()->pluck('district_label_raw')->unique()->values()->all());
        $this->assertSame(4, $batch->matched_count);
    }

    public function test_the_same_billing_file_twice_is_refused(): void
    {
        $this->actingAs($this->officer());
        $file = $this->billingFile();

        $first = $this->importFile($file);

        $again = Livewire::test(Batches::class)->call('openUpload')->set('file', $file)->call('previewFile');

        $this->assertTrue($again->get('preview')['blocked']);
        $this->assertStringContainsString("already uploaded as batch #{$first->id}", collect($again->get('preview')['errors'])->pluck('message')->implode(' '));
        $again->call('runImport')->assertHasErrors('file');
        $this->assertSame(1, CommercialImportBatch::query()->count());
    }

    public function test_an_unmatched_district_imports_with_a_warning_and_resolving_it_saves_an_alias_and_re_matches(): void
    {
        $this->actingAs($this->officer());

        $districts = ReportWorkbooks::defaultDistricts();
        $districts['SOWUTUOM CENTRAL'] = $districts['SOWUTUOM'];
        unset($districts['SOWUTUOM']);

        $preview = $this->previewOf($this->billingFile(['districts' => $districts]));
        $this->assertFalse($preview['blocked']);
        $this->assertSame(1, $preview['unmatched_count']);
        $this->assertStringContainsString("District 'SOWUTUOM CENTRAL' (2 routes) does not match any district in Accra West", collect($preview['warnings'])->pluck('message')->implode(' '));

        $batch = $this->importFile($this->billingFile(['districts' => $districts]));
        $this->assertSame(2, $batch->routes()->whereNull('district_id')->count());
        $this->assertSame(2, $batch->matched_count);

        Livewire::test(BatchShow::class, ['batch' => $batch])
            ->assertSee('Districts not matched')
            ->call('startResolvingDistrict', 'SOWUTUOM CENTRAL')
            ->set('resolveDistrictId', $this->sowutuom->id)
            ->call('saveDistrictResolution')
            ->assertHasNoErrors();

        $this->assertSame(0, $batch->routes()->whereNull('district_id')->count());
        $this->assertSame($this->sowutuom->id, $batch->routes()->where('district_label_raw', 'SOWUTUOM CENTRAL')->value('district_id'));
        $this->assertSame(4, $batch->fresh()->matched_count);

        $alias = CommercialLocationAlias::query()->where('kind', 'district')->sole();
        $this->assertSame('SOWUTUOM CENTRAL', $alias->alias_normalized);
        $this->assertSame($this->sowutuom->id, $alias->district_id);
        $this->assertNotNull(AuditLog::query()->where('action', 'commercial.alias_saved')->first());

        // The alias is reused by the next upload (another period, so a different file).
        $next = $this->previewOf($this->billingFile(['districts' => $districts, 'period' => 'September-2026']));
        $this->assertSame(0, $next['unmatched_count']);
        $this->assertSame(4, $next['matched_count']);
    }

    public function test_a_district_from_another_region_cannot_be_used_to_resolve_a_batch(): void
    {
        $this->actingAs($this->officer());
        $kumasi = \App\Models\District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi Central']);

        $districts = ['MYSTERY' => ReportWorkbooks::defaultDistricts()['SOWUTUOM']];
        $batch = $this->importFile($this->billingFile(['districts' => $districts]));

        Livewire::test(BatchShow::class, ['batch' => $batch])
            ->call('startResolvingDistrict', 'MYSTERY')
            ->set('resolveDistrictId', $kumasi->id)
            ->call('saveDistrictResolution')
            ->assertHasErrors('resolveDistrictId');

        $this->assertSame(0, CommercialLocationAlias::query()->count());
    }

    public function test_the_billing_import_is_audit_logged(): void
    {
        $this->actingAs($this->officer());

        $batch = $this->importFile($this->billingFile());

        $log = AuditLog::query()->where('action', 'commercial.batch_imported')->latest('id')->firstOrFail();

        $this->assertSame($batch->id, $log->target_id);
        $this->assertSame(4, $log->metadata['rows']);
        $this->assertSame('new_service', $log->metadata['customer_segment']);
        $this->assertArrayHasKey('billing_for_period', $log->metadata['control_totals']['report_totals']);
        $this->assertSame(4, CommercialBillingRoute::query()->where('batch_id', $batch->id)->count());
    }
}
