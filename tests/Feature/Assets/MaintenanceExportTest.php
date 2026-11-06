<?php

namespace Tests\Feature\Assets;

use App\Exports\Assets\MaintenanceExport;
use App\Livewire\Assets\MaintenanceLog;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class MaintenanceExportTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_excel_export_uses_the_screens_filters_and_the_expected_columns(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $laptop = $this->makeAsset($district, ['asset_name' => 'Dell Latitude', 'serial_number' => 'DL-1', 'asset_type' => 'Laptop']);
        $this->record($laptop, ['status' => 'Completed', 'maintenance_type' => 'Repair', 'technician' => 'Kofi', 'reported_date' => '2026-10-01', 'completion_date' => '2026-10-05']);
        $this->record($laptop, ['status' => 'Open', 'maintenance_type' => 'Upgrade']);

        Excel::fake();

        $this->get(route('assets.maintenance.export.excel', ['status' => 'Completed']))->assertOk();

        Excel::assertDownloaded('maintenance_log_'.now()->format('Y_m_d').'.xlsx', function (MaintenanceExport $export) {
            $this->assertSame(
                ['Asset name', 'Asset type', 'Serial number', 'Maintenance type', 'Status', 'Reported date', 'Completed date', 'Technician', 'Location', 'Notes'],
                $export->headings()
            );
            $rows = $export->collection();
            $this->assertCount(1, $rows);
            $this->assertSame(
                ['Dell Latitude', 'Laptop', 'DL-1', 'Repair', 'Completed', '01 Oct 2026', '05 Oct 2026', 'Kofi', 'Accra Central District, Greater Accra', ''],
                $rows->first()
            );

            return true;
        });
    }

    public function test_a_regional_user_can_only_export_their_own_regions_records(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $this->record($this->makeAsset($home, ['asset_name' => 'Mine']), []);
        $this->record($this->makeAsset($other, ['asset_name' => 'Theirs']), []);
        $this->actingAs($this->ictUser($home));

        Excel::fake();
        $this->get(route('assets.maintenance.export.excel'))->assertOk();

        Excel::assertDownloaded('maintenance_log_'.now()->format('Y_m_d').'.xlsx', function (MaintenanceExport $export) {
            $this->assertSame(['Mine'], $export->collection()->pluck(0)->all());

            return true;
        });
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->record($this->makeAsset($district), []);

        $response = $this->get(route('assets.maintenance.export.pdf'))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_users_without_the_export_permission_are_refused(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $user = $this->ictUser($district);
        $user->roles()->first()->permissions()->detach(
            \App\Models\Permission::query()->where('name', 'assets.export_maintenance')->value('id')
        );
        $this->actingAs($user->fresh());

        $this->get(route('assets.maintenance.export.excel'))->assertForbidden();
        $this->get(route('assets.maintenance.export.pdf'))->assertForbidden();

        // ... and the buttons are not drawn for them either.
        Livewire::test(MaintenanceLog::class)->assertDontSee('Export Excel');
    }

    public function test_the_buttons_show_for_holders_of_the_permission_and_carry_the_active_filters(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(MaintenanceLog::class)
            ->set('status', 'Open')
            ->set('dueOnly', true)
            ->assertSee('Export Excel')
            ->assertSee('Export PDF')
            ->assertSeeHtml('maintenance/export/excel?status=Open&amp;due=1');
    }

    public function test_an_export_over_the_row_cap_is_refused_with_a_message(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district);
        $this->record($asset, []);
        $this->record($asset, []);
        config(['gwl.assets_maintenance_export_max_rows' => 1]);

        $this->from(route('assets.maintenance'))
            ->get(route('assets.maintenance.export.excel'))
            ->assertRedirect(route('assets.maintenance'))
            ->assertSessionHas('error');

        $this->assertSame(0, AuditLog::query()->where('action', 'export_maintenance_excel')->count());
    }

    public function test_each_export_writes_one_audit_row_without_the_search_text(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->record($this->makeAsset($district, ['asset_name' => 'Zebra Printer']), ['technician' => 'Zebra Tech']);

        Excel::fake();
        $this->get(route('assets.maintenance.export.excel', ['q' => 'Zebra']))->assertOk();

        $log = AuditLog::query()->where('action', 'export_maintenance_excel')->get();
        $this->assertCount(1, $log);
        $this->assertStringNotContainsString('Zebra', json_encode($log->first()->toArray()));
        $this->assertStringContainsString('"rows":1', json_encode($log->first()->toArray()));
    }

    public function test_cells_that_look_like_formulas_stay_text(): void
    {
        $export = new MaintenanceExport(['A'], collect([['=1+1']]));
        $sheet = (new Spreadsheet)->getActiveSheet();

        foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd'] as $value) {
            $cell = $sheet->getCell('A1');
            $export->bindValue($cell, $value);
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $value);
            $this->assertSame($value, $cell->getValue());
        }
    }

    /** @param  array<string, mixed>  $attributes */
    protected function record(IctAsset $asset, array $attributes): IctAssetMaintenance
    {
        return IctAssetMaintenance::query()->create([
            'ict_asset_id' => $asset->id,
            'maintenance_type' => 'Repair',
            'status' => 'Open',
            'reported_date' => today()->toDateString(),
            ...$attributes,
        ]);
    }
}
