<?php

namespace Tests\Feature\Assets;

use App\Exports\Assets\AssetAuditExport;
use App\Livewire\Assets\Audits\AuditShow;
use App\Livewire\Assets\Audits\AuditsList;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use App\Models\IctAssetAuditLine;
use App\Models\IctAssetTransfer;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetAuditService;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class AssetAuditTest extends TestCase
{
    use RefreshDatabase;

    protected District $accra;

    protected District $tema;

    protected District $kumasi;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $greaterAccra = Region::query()->create(['region_name' => 'Greater Accra']);
        $ashanti = Region::query()->create(['region_name' => 'Ashanti']);
        $this->accra = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $greaterAccra->id]);
        $this->tema = District::query()->create(['district_name' => 'Tema', 'region_id' => $greaterAccra->id]);
        $this->kumasi = District::query()->create(['district_name' => 'Kumasi', 'region_id' => $ashanti->id]);

        $this->admin = $this->user('SA001', 'super_admin');
        $this->actingAs($this->admin);
    }

    // ---- start ------------------------------------------------------------------------------------------------

    public function test_start_with_a_scope_creates_only_matching_lines_with_the_expected_snapshot(): void
    {
        $holder = $this->employee('E1', 'Kwame Mensah');
        $inScope = $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'district_id' => $this->tema->id, 'region_id' => $this->tema->region_id, 'assigned_to_employee_id' => $holder->id, 'status' => IctAsset::STATUS_IN_REPAIR]);
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'district_id' => $this->kumasi->id, 'region_id' => $this->kumasi->region_id]);
        $this->asset(['district_id' => $this->tema->id, 'region_id' => $this->tema->region_id]); // wrong category

        $audit = app(AssetAuditService::class)->start(
            ['device_category' => 'phone', 'region_id' => $this->tema->region_id, 'district_id' => $this->tema->id],
            'Tema phones',
            $this->admin->id,
        );

        $this->assertSame('in_progress', $audit->status);
        $this->assertNotNull($audit->started_at);
        $this->assertSame($this->tema->id, $audit->scope_district_id);

        $line = $audit->lines()->sole();
        $this->assertSame($inScope->id, $line->ict_asset_id);
        $this->assertSame('In Repair', $line->expected_status);
        $this->assertSame($holder->id, $line->expected_assigned_to_employee_id);
        $this->assertSame($this->tema->id, $line->expected_district_id);
        $this->assertNull($line->result);

        // The snapshot does not follow later edits.
        $inScope->update(['status' => IctAsset::STATUS_ACTIVE, 'assigned_to_employee_id' => null]);
        $this->assertSame('In Repair', $line->fresh()->expected_status);
        $this->assertSame($holder->id, $line->fresh()->expected_assigned_to_employee_id);

        $this->assertTrue(AuditLog::query()->where('action', 'create_asset_audit')->exists());
    }

    public function test_start_without_a_scope_includes_every_asset_in_all_categories(): void
    {
        $this->asset();
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph']);
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'RT']);

        $audit = app(AssetAuditService::class)->start([], 'Everything', $this->admin->id);

        $this->assertSame(3, $audit->lines()->count());
        $this->assertSame('All assets', $audit->scopeSummary());
    }

    public function test_start_refuses_an_empty_scope(): void
    {
        $this->asset();

        $this->expectException(ValidationException::class);
        app(AssetAuditService::class)->start(['district_id' => $this->kumasi->id], 'Nothing there', $this->admin->id);
    }

    // ---- recording --------------------------------------------------------------------------------------------

    public function test_mismatch_and_not_found_need_a_reason(): void
    {
        $line = $this->startedLine();
        $service = app(AssetAuditService::class);

        foreach (['mismatch', 'not_found'] as $result) {
            try {
                $service->recordResult($line, $result, ['status' => 'Damaged'], '  ', $this->admin->id);
                $this->fail('A reason should be required for '.$result);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('reason', $e->errors());
            }
        }

        $this->assertNull($line->fresh()->result);

        $service->recordResult($line, 'mismatch', ['status' => 'Damaged'], 'Cracked screen', $this->admin->id);
        $this->assertSame('mismatch', $line->fresh()->result);
        $this->assertSame('Cracked screen', $line->fresh()->mismatch_reason);
    }

    public function test_unknown_result_and_unknown_status_are_rejected(): void
    {
        $line = $this->startedLine();
        $service = app(AssetAuditService::class);

        $this->expectException(ValidationException::class);
        $service->recordResult($line, 'maybe', [], null, $this->admin->id);
    }

    public function test_matched_discards_any_actual_input(): void
    {
        $line = $this->startedLine();

        app(AssetAuditService::class)->recordResult(
            $line,
            'matched',
            ['status' => 'Lost', 'assigned_to_employee_id' => $this->employee('E9', 'Someone')->id, 'district_id' => $this->kumasi->id, 'location' => 'Roof'],
            'ignored reason',
            $this->admin->id,
        );

        $line = $line->fresh();
        $this->assertSame('matched', $line->result);
        $this->assertNull($line->actual_status);
        $this->assertNull($line->actual_assigned_to_employee_id);
        $this->assertNull($line->actual_district_id);
        $this->assertNull($line->actual_location);
        $this->assertNull($line->mismatch_reason);
        $this->assertSame($this->admin->id, $line->verified_by_user_id);
        $this->assertNotNull($line->verified_at);
    }

    // ---- complete ---------------------------------------------------------------------------------------------

    public function test_complete_is_refused_while_a_line_is_pending(): void
    {
        [$audit, $service] = $this->twoLineAudit();
        $service->recordResult($audit->lines()->first(), 'matched', [], null, $this->admin->id);

        try {
            $service->complete($audit);
            $this->fail('Completing with a pending line should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('audit', $e->errors());
        }

        $this->assertSame('in_progress', $audit->fresh()->status);
    }

    public function test_complete_computes_the_reconciliation_rate(): void
    {
        foreach (range(1, 3) as $i) {
            $this->asset();
        }
        $service = app(AssetAuditService::class);
        $audit = $service->start([], 'Thirds', $this->admin->id);
        $lines = $audit->lines()->orderBy('id')->get();

        $service->recordResult($lines[0], 'matched', [], null, $this->admin->id);
        $service->recordResult($lines[1], 'mismatch', [], 'Wrong desk', $this->admin->id);
        $service->recordResult($lines[2], 'not_found', [], 'Not in the office', $this->admin->id);
        $service->complete($audit);

        $audit = $audit->fresh();
        $this->assertSame('completed', $audit->status);
        $this->assertNotNull($audit->completed_at);
        $this->assertSame('33.33', (string) $audit->reconciliation_rate);
        $this->assertSame(['total' => 3, 'verified' => 3, 'pending' => 0, 'matched' => 1, 'mismatch' => 1, 'not_found' => 1], $service->summary($audit));
        $this->assertTrue(AuditLog::query()->where('action', 'complete_asset_audit')->exists());

        // Completed audits are frozen.
        $this->expectException(ValidationException::class);
        $service->recordResult($lines[0], 'matched', [], null, $this->admin->id);
    }

    // ---- correction -------------------------------------------------------------------------------------------

    public function test_applying_a_correction_updates_the_asset_and_logs_audit_and_transfers(): void
    {
        $kwame = $this->employee('E1', 'Kwame Mensah');
        $ama = $this->employee('E2', 'Ama Boateng');
        $asset = $this->asset(['assigned_to_employee_id' => $kwame->id, 'district_id' => $this->accra->id, 'region_id' => $this->accra->region_id]);

        $service = app(AssetAuditService::class);
        $audit = $service->start([], 'Check', $this->admin->id);
        $line = $audit->lines()->sole();

        $service->recordResult($line, 'mismatch', [
            'status' => IctAsset::STATUS_DAMAGED,
            'assigned_to_employee_id' => $ama->id,
            'district_id' => $this->tema->id,
        ], 'Found at Tema office with a broken screen', $this->admin->id);

        $service->applyCorrection($line->fresh(), $this->admin->id);

        $asset = $asset->fresh();
        $this->assertSame('Damaged', $asset->status);
        $this->assertSame('Found at Tema office with a broken screen', $asset->status_reason);
        $this->assertSame($ama->id, $asset->assigned_to_employee_id);
        $this->assertSame($this->tema->id, $asset->district_id);
        $this->assertTrue($line->fresh()->correction_applied);

        // AssetRecordService's own audit snapshot...
        $this->assertTrue(AuditLog::query()->where('action', 'update_asset')->where('target_id', $asset->id)->exists());

        // ...and one transfer row per changed dimension, attributed to the actor.
        $transfers = IctAssetTransfer::query()->where('ict_asset_id', $asset->id)->get()->keyBy('transfer_type');
        $this->assertCount(3, $transfers);
        $this->assertSame($kwame->id, $transfers['assignment_change']->from_employee_id);
        $this->assertSame($ama->id, $transfers['assignment_change']->to_employee_id);
        $this->assertSame('Active', $transfers['status_change']->from_status);
        $this->assertSame('Damaged', $transfers['status_change']->to_status);
        $this->assertSame($this->accra->id, $transfers['district_change']->from_district_id);
        $this->assertSame($this->tema->id, $transfers['district_change']->to_district_id);
        $this->assertSame($this->admin->id, $transfers['status_change']->performed_by_user_id);

        // Not repeatable, and the result is locked afterwards.
        try {
            $service->applyCorrection($line->fresh(), $this->admin->id);
            $this->fail('A correction should only apply once.');
        } catch (ValidationException) {
            $this->assertCount(3, IctAssetTransfer::query()->get());
        }

        $this->expectException(ValidationException::class);
        $service->recordResult($line->fresh(), 'matched', [], null, $this->admin->id);
    }

    public function test_only_a_mismatch_can_be_corrected_and_a_blank_status_is_left_alone(): void
    {
        $asset = $this->asset(['status' => IctAsset::STATUS_IN_REPAIR]);
        $service = app(AssetAuditService::class);
        $line = $service->start([], 'Check', $this->admin->id)->lines()->sole();

        $service->recordResult($line, 'not_found', [], 'Missing', $this->admin->id);
        try {
            $service->applyCorrection($line->fresh(), $this->admin->id);
            $this->fail('not_found cannot be corrected');
        } catch (ValidationException) {
        }

        $service->recordResult($line->fresh(), 'mismatch', ['status' => null, 'location' => 'Back office'], 'Different room', $this->admin->id);
        $service->applyCorrection($line->fresh(), $this->admin->id);

        $this->assertSame('In Repair', $asset->fresh()->status);
        $this->assertSame(0, IctAssetTransfer::query()->count());
    }

    // ---- UI ---------------------------------------------------------------------------------------------------

    public function test_the_audit_screens_run_an_audit_end_to_end(): void
    {
        $this->asset(['asset_name' => 'Boardroom PC']);
        $this->asset(['asset_name' => 'Spare laptop']);

        Livewire::test(AuditsList::class)
            ->call('openCreate')
            ->set('title', 'Stock check')
            ->assertViewHas('lineCount', 2)
            ->set('deviceCategory', 'phone')
            ->assertViewHas('lineCount', 0)
            ->set('deviceCategory', '')
            ->call('create')
            ->assertHasNoErrors();

        $audit = IctAssetAudit::query()->sole();
        $lines = $audit->lines()->orderBy('id')->get();

        $this->get(route('assets.audits'))->assertOk()->assertSee('Stock check');

        Livewire::test(AuditShow::class, ['audit' => $audit])
            ->assertSee('0 of 2')
            ->call('completeAudit')
            ->assertHasErrors(['audit'])
            ->call('markMatched', $lines[0]->id)
            ->call('openRecord', $lines[1]->id, 'not_found')
            ->set('reason', '')
            ->call('saveRecord')
            ->assertHasErrors(['reason'])
            ->set('reason', 'Not at the desk')
            ->call('saveRecord')
            ->assertHasNoErrors()
            ->call('completeAudit')
            ->assertHasNoErrors()
            ->assertSee('50.00%');

        $this->assertSame('50.00', (string) $audit->fresh()->reconciliation_rate);
    }

    // ---- export -----------------------------------------------------------------------------------------------

    public function test_export_only_works_on_a_completed_audit_and_only_returns_its_lines(): void
    {
        $this->asset(['asset_name' => '=HYPERLINK("http://evil","x")']);
        $service = app(AssetAuditService::class);
        $audit = $service->start([], 'First', $this->admin->id);
        $other = $service->start([], 'Second', $this->admin->id);

        $this->get(route('assets.audits.export.excel', $audit))->assertStatus(409);
        $this->get(route('assets.audits.export.pdf', $audit))->assertStatus(409);

        $service->recordResult($audit->lines()->first(), 'mismatch', [], 'Odd one', $this->admin->id);
        $service->complete($audit);

        $rows = $service->exportRows($audit->fresh());
        $this->assertCount(1, $rows);
        $this->assertSame('Mismatch', $rows->first()['result']);
        $this->assertSame('Odd one', $rows->first()['reason']);
        $this->assertSame(0, $service->exportRows($other)->where('result', 'Mismatch')->count());

        $this->get(route('assets.audits.export.excel', $audit))->assertOk();
        $this->get(route('assets.audits.export.pdf', $audit))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(2, AuditLog::query()->whereIn('action', ['export_asset_audit_excel', 'export_asset_audit_pdf'])->count());
    }

    public function test_excel_cells_starting_with_an_equals_sign_stay_text(): void
    {
        $this->asset(['asset_name' => '=1+1']);
        $service = app(AssetAuditService::class);
        $audit = $service->start([], 'Injection', $this->admin->id);

        $export = new AssetAuditExport($service->exportRows($audit));
        $cell = (new Spreadsheet)->getActiveSheet()->getCell('A1');

        $this->assertTrue($export->bindValue($cell, '=1+1'));
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame(count($service->columns()), count($export->headings()));
        $this->assertSame('=1+1', $export->collection()->first()[2]);
    }

    // ---- permissions ------------------------------------------------------------------------------------------

    public function test_the_audit_routes_are_gated_by_their_permissions(): void
    {
        $audit = $this->completedAudit();

        // ict_team gets both by default.
        $this->actingAs($this->headOfficeIct('ICT001'));
        $this->get(route('assets.audits'))->assertOk();
        $this->get(route('assets.audits.export.excel', $audit))->assertOk();

        // Without manage_audits: no list, no show, and the Livewire components refuse too.
        $this->setIctPermission('assets.manage_audits', false);
        $this->actingAs($this->headOfficeIct('ICT002'));
        $this->get(route('assets.audits'))->assertForbidden();
        $this->get(route('assets.audits.show', $audit))->assertForbidden();
        Livewire::test(AuditsList::class)->assertForbidden();
        $this->setIctPermission('assets.manage_audits', true);

        // Without export_audits: can run audits but not export.
        $this->setIctPermission('assets.export_audits', false);
        $this->actingAs($this->headOfficeIct('ICT003'));
        $this->get(route('assets.audits'))->assertOk();
        $this->get(route('assets.audits.export.excel', $audit))->assertForbidden();
        $this->get(route('assets.audits.export.pdf', $audit))->assertForbidden();
    }

    public function test_a_regional_ict_user_only_sees_and_creates_audits_in_their_own_region(): void
    {
        $service = app(AssetAuditService::class);
        $this->asset(['district_id' => $this->tema->id, 'region_id' => $this->tema->region_id]);
        $this->asset(['district_id' => $this->kumasi->id, 'region_id' => $this->kumasi->region_id]);
        $kumasiAudit = $service->start(['region_id' => $this->kumasi->region_id], 'Kumasi check', $this->admin->id);

        $ict = $this->user('ICT001', 'ict_team');
        $this->employee('ICT001', 'Tema ICT', $this->tema);
        $this->actingAs($ict);

        $this->get(route('assets.audits.show', $kumasiAudit))->assertNotFound();

        Livewire::test(AuditsList::class)
            ->call('openCreate')
            ->set('title', 'Mine')
            ->set('regionId', $this->kumasi->region_id) // tampering: forced back to their own region
            ->call('create');

        $mine = IctAssetAudit::query()->where('title', 'Mine')->firstOrFail();
        $this->assertSame($this->tema->region_id, $mine->scope_region_id);
        $this->assertSame(1, $mine->lines()->count());

        $this->get(route('assets.audits.show', $mine))->assertOk();
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    protected function startedLine(): IctAssetAuditLine
    {
        $this->asset();

        return app(AssetAuditService::class)->start([], 'Check', $this->admin->id)->lines()->sole();
    }

    protected function twoLineAudit(): array
    {
        $this->asset();
        $this->asset();
        $service = app(AssetAuditService::class);

        return [$service->start([], 'Pair', $this->admin->id), $service];
    }

    protected function completedAudit(): IctAssetAudit
    {
        $this->asset();
        $service = app(AssetAuditService::class);
        $audit = $service->start([], 'Done', $this->admin->id);
        $service->recordResult($audit->lines()->sole(), 'matched', [], null, $this->admin->id);
        $service->complete($audit);

        return $audit->fresh();
    }

    protected function setIctPermission(string $permission, bool $granted): void
    {
        $role = Role::query()->where('name', 'ict_team')->firstOrFail();
        $id = \App\Models\Permission::query()->where('name', $permission)->value('id');

        $granted ? $role->permissions()->syncWithoutDetaching([$id]) : $role->permissions()->detach($id);
    }

    protected function headOfficeIct(string $staffId): User
    {
        $user = $this->user($staffId, 'ict_team');
        // location_type follows the district's name, so Head Office staff need the Head Office district.
        $headOffice = District::query()->firstOrCreate(['district_name' => 'Head Office'], ['region_id' => $this->accra->region_id]);
        $this->employee($staffId, 'HO '.$staffId, $headOffice);

        return $user;
    }

    protected function user(string $staffId, string $role): User
    {
        $user = User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    protected function employee(string $staffId, string $name, ?District $district = null): Employee
    {
        $district ??= $this->accra;

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Administration'])->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-01-01',
            'present_appointment' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    protected function asset(array $overrides = []): IctAsset
    {
        static $n = 0;
        $n++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$n,
            'serial_number' => 'AU-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
