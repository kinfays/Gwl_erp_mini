<?php

namespace Tests\Feature\Assets;

use App\Exports\Assets\AssetSummaryExport;
use App\Exports\Assets\Sheets\SummarySheet;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\IctAssetTransfer;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetAuditService;
use App\Services\Assets\AssetSummaryService;
use App\Services\Assets\AssetTransferService;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class AssetDetailAndSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    protected District $accra;

    protected District $kumasi;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $this->accra = District::query()->create(['district_name' => 'Accra Central', 'region_id' => Region::query()->create(['region_name' => 'Greater Accra'])->id]);
        $this->kumasi = District::query()->create(['district_name' => 'Kumasi', 'region_id' => Region::query()->create(['region_name' => 'Ashanti'])->id]);

        $this->admin = $this->user('SA001', 'super_admin');
        $this->actingAs($this->admin);
    }

    // ---- detail page --------------------------------------------------------------------------------------------

    public function test_the_detail_page_shows_the_whole_story_of_one_asset(): void
    {
        $maker = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $model = IctAssetModel::query()->create(['name' => 'Latitude 5420', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $maker->id, 'is_active' => true]);
        $kwame = $this->employee('E1', 'Kwame Mensah');
        $ama = $this->employee('E2', 'Ama Boateng');

        $asset = $this->asset([
            'asset_name' => 'Finance laptop', 'serial_number' => 'LT-100', 'asset_type' => 'Laptop',
            'ict_asset_model_id' => $model->id, 'assigned_to_employee_id' => $kwame->id, 'previous_assigned_to_employee_id' => $ama->id,
            'district_id' => $this->accra->id, 'region_id' => $this->accra->region_id,
            'status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Faulty power button', 'condition' => IctAsset::CONDITION_POOR,
            'purchased_at' => '2021-03-01', 'warranty_expires_at' => '2024-03-01', 'hostname' => 'FIN-LT-01',
        ]);
        IctAssetMaintenance::query()->create(['ict_asset_id' => $asset->id, 'maintenance_type' => 'Screen repair', 'status' => 'Completed', 'technician' => 'Yaw']);
        IctAssetIssueReport::query()->create(['title' => 'Will not boot', 'issue_type' => 'Hardware Fault', 'status' => 'Open', 'linked_asset_id' => $asset->id]);
        app(AssetTransferService::class)->log($asset, IctAssetTransfer::TYPE_ASSIGNMENT_CHANGE, ['from_employee_id' => $ama->id, 'to_employee_id' => $kwame->id], null);

        $service = app(AssetAuditService::class);
        $audit = $service->start([], 'Q4 check', $this->admin->id);
        $service->recordResult($audit->lines()->sole(), 'mismatch', [], 'Screen cracked', $this->admin->id);

        $this->get(route('assets.show', $asset))
            ->assertOk()
            ->assertSee('Finance laptop')
            ->assertSee('LT-100')
            ->assertSee('Dell Latitude 5420')
            ->assertSee('Faulty power button')
            ->assertSee('Poor')
            ->assertSee('Kwame Mensah')
            ->assertSee(route('assets.employee', $kwame), false)
            ->assertSee('Ama Boateng')
            ->assertSee('Accra Central')
            ->assertSee('01 Mar 2021')
            ->assertSee('5 years old')
            ->assertSee('Expired')
            ->assertSee('01 Mar 2025')            // 4-year default policy
            ->assertSee('overdue')
            ->assertSee('FIN-LT-01')
            ->assertSee('Screen repair')
            ->assertSee('Will not boot')
            ->assertSee('Reassigned from Ama Boateng to Kwame Mensah')
            ->assertSee('Q4 check')
            ->assertSee('Screen cracked');
    }

    public function test_the_detail_page_adapts_to_phones_and_never_shows_network_passwords(): void
    {
        $phone = $this->asset(['asset_name' => 'Field phone', 'asset_type' => 'Ph', 'device_category' => 'phone', 'imei' => '356938035643809', 'device_phone_number' => '0200000009']);
        $router = $this->asset([
            'asset_name' => 'Office router', 'asset_type' => 'RT', 'device_category' => 'network',
            'device_ip' => '10.0.0.1', 'ssid' => 'GWL-OFFICE', 'ssid_password' => 'wifi-secret-123', 'login_password' => 'router-secret-456',
        ]);

        $this->get(route('assets.show', $phone))->assertOk()->assertSee('Phone details')->assertSee('356938035643809')->assertSee('0200000009');

        $this->get(route('assets.show', $router))->assertOk()
            ->assertSee('Network details')->assertSee('GWL-OFFICE')->assertSee('10.0.0.1')
            ->assertDontSee('wifi-secret-123')->assertDontSee('router-secret-456');
    }

    public function test_the_detail_page_handles_an_asset_with_no_dates_or_history(): void
    {
        $asset = $this->asset(['asset_name' => 'Bare asset']);

        $this->get(route('assets.show', $asset))->assertOk()
            ->assertSee('Needs a purchase date')
            ->assertSee('No changes recorded yet.')
            ->assertSee('Never audited.')
            ->assertSee('No maintenance tickets.')
            ->assertSee('Unassigned');
    }

    public function test_the_detail_page_is_scoped_and_permission_gated(): void
    {
        $far = $this->asset(['district_id' => $this->kumasi->id, 'region_id' => $this->kumasi->region_id]);
        $near = $this->asset(['district_id' => $this->accra->id, 'region_id' => $this->accra->region_id]);

        $ict = $this->user('ICT1', 'ict_team');
        $this->employee('ICT1', 'Accra ICT', $this->accra);
        $this->actingAs($ict);

        $this->get(route('assets.show', $near))->assertOk();
        $this->get(route('assets.show', $far))->assertNotFound();
        $this->get(route('assets.show', 999999))->assertNotFound();

        $this->actingAs($this->user('HR1', 'hr_headoffice'));
        $this->assertDenied($this->get(route('assets.show', $near)));
    }

    public function test_asset_names_in_the_lists_and_rollups_link_to_the_detail_page(): void
    {
        $holder = $this->employee('E1', 'Kwame Mensah');
        $asset = $this->asset(['asset_name' => 'Linked PC', 'assigned_to_employee_id' => $holder->id]);

        $this->get(route('assets.assets'))->assertOk()->assertSee(route('assets.show', $asset), false);
        $this->get(route('assets.employee', $holder))->assertOk()->assertSee(route('assets.show', $asset), false);
    }

    // ---- summary export -----------------------------------------------------------------------------------------

    public function test_the_summary_excel_export_downloads_and_is_audit_logged(): void
    {
        $this->asset(['purchased_at' => '2019-01-01']);

        $response = $this->get(route('assets.summary.export.excel', ['district' => (string) $this->accra->id]));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('asset_summary_2026_10_02.xlsx', (string) $response->headers->get('content-disposition'));

        $log = AuditLog::query()->where('action', 'export_asset_summary_excel')->firstOrFail();
        $this->assertSame($this->accra->district_name, $log->new_values['district']);
    }

    public function test_the_summary_pdf_export_downloads(): void
    {
        $this->asset(['asset_name' => 'Pdf PC']);

        $this->get(route('assets.summary.export.pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertTrue(AuditLog::query()->where('action', 'export_asset_summary_pdf')->exists());
    }

    public function test_the_export_has_one_sheet_per_tab_with_the_pages_numbers(): void
    {
        $maker = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $model = IctAssetModel::query()->create(['name' => 'Latitude', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $maker->id, 'is_active' => true]);
        $holder = $this->employee('E1', 'Kwame Mensah');
        $a = $this->asset(['asset_name' => 'Poor PC', 'ict_asset_model_id' => $model->id, 'assigned_to_employee_id' => $holder->id, 'condition' => 'Poor', 'purchased_at' => '2019-01-01']);
        $this->asset(['asset_name' => 'Spare phone', 'asset_type' => 'Ph', 'device_category' => 'phone']);
        $ticket = new IctAssetMaintenance(['ict_asset_id' => $a->id, 'maintenance_type' => 'Repair', 'status' => 'Completed', 'completion_date' => '2026-09-11']);
        $ticket->created_at = '2026-09-01';
        $ticket->save();
        IctAssetIssueReport::query()->create(['title' => 'x', 'issue_type' => 'Network', 'status' => 'Open']);

        $report = $this->report();
        $export = new AssetSummaryExport($report);
        $sheets = collect($export->sheets())->keyBy(fn (SummarySheet $s) => $s->title());

        $this->assertSame(
            ['Needs Attention', 'Lifecycle', 'Assignment', 'Manufacturers and Models', 'Maintenance', 'Reported Issues'],
            $sheets->keys()->all()
        );

        $this->assertSame([['Poor PC', 'asset', 'Poor condition']], collect($sheets['Needs Attention']->array())->map(fn ($r) => [$r[0], $r[2], $r[4]])->all());

        $lifecycle = collect($sheets['Lifecycle']->array());
        $this->assertSame(['Age', '5+ years', 1, 0, 0, 1], $lifecycle->first(fn ($r) => $r[0] === 'Age' && $r[1] === '5+ years'));
        $this->assertSame(['Warranty', 'Unknown', 1, 1, 0, 2], $lifecycle->first(fn ($r) => $r[0] === 'Warranty' && $r[1] === 'Unknown'));

        $this->assertSame(['Unassigned devices', 1], $sheets['Assignment']->array()[0]);
        $this->assertSame(['Kwame Mensah', 1], $sheets['Assignment']->array()[1]);

        $this->assertContains(['Assets', 'Dell', '', 1, '100%'], $sheets['Manufacturers and Models']->array());
        $this->assertContains(['Assets', 'Dell', 'Latitude', 1, '100%'], $sheets['Manufacturers and Models']->array());

        $maintenance = $sheets['Maintenance']->array();
        $this->assertSame(['Tickets', 1, ''], $maintenance[0]);
        $this->assertSame(['Average turnaround (days)', 10.0, '1 completed tickets counted'], $maintenance[1]);

        $this->assertSame(['Type', 1, 'Network'], collect($sheets['Reported Issues']->array())->first(fn ($r) => $r[0] === 'Type' && $r[2] === 'Network'));
    }

    public function test_export_cells_starting_with_equals_stay_text(): void
    {
        $this->asset(['asset_name' => '=HYPERLINK("http://evil","x")', 'condition' => 'Poor']);

        $sheet = collect((new AssetSummaryExport($this->report()))->sheets())->first();
        $cell = (new Spreadsheet)->getActiveSheet()->getCell('A1');

        $this->assertSame('=HYPERLINK("http://evil","x")', $sheet->array()[0][0]);
        $this->assertTrue($sheet->bindValue($cell, $sheet->array()[0][0]));
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }

    public function test_the_export_honours_the_district_filter_and_regional_scope(): void
    {
        $this->asset(['condition' => 'Poor', 'district_id' => $this->accra->id, 'region_id' => $this->accra->region_id]);
        $this->asset(['condition' => 'Poor', 'district_id' => $this->kumasi->id, 'region_id' => $this->kumasi->region_id]);

        $this->assertCount(1, $this->report((int) $this->kumasi->id)['needsAttention']);
        $this->assertCount(2, $this->report()['needsAttention']);

        $ict = $this->user('ICT1', 'ict_team');
        $this->employee('ICT1', 'Accra ICT', $this->accra);
        $this->actingAs($ict);

        $this->get(route('assets.summary.export.excel'))->assertOk();
        $this->get(route('assets.summary.export.pdf', ['district' => (string) $this->kumasi->id]))->assertOk();

        // The scoped user's export built through the controller path only holds their region.
        Excel::fake();
        $this->get(route('assets.summary.export.excel'));
        Excel::assertDownloaded('asset_summary_2026_10_02.xlsx', function (AssetSummaryExport $export) {
            $needs = collect($export->sheets())->first()->array();

            return count($needs) === 1;
        });
    }

    public function test_the_summary_export_needs_the_dashboard_permission(): void
    {
        $this->actingAs($this->user('HR1', 'hr_headoffice'));

        $this->assertDenied($this->get(route('assets.summary.export.excel')));
        $this->assertDenied($this->get(route('assets.summary.export.pdf')));
    }

    /** The role middleware answers a user outside Assets with a 403 or a redirect, never the page. */
    protected function assertDenied($response): void
    {
        $this->assertContains($response->getStatusCode(), [302, 403]);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    protected function report(?int $districtId = null): array
    {
        return app(AssetSummaryService::class)->report(IctAsset::query(), IctAssetIssueReport::query(), $districtId, null, null);
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
            'serial_number' => 'DX-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
