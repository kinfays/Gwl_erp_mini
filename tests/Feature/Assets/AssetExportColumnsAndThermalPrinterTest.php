<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\IctIssueCategory;
use App\Services\Assets\AssetDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/** The Thermal Printer type, and the Manufacturer / Department / Asset Tag / Actual Location columns of the Assets export. */
class AssetExportColumnsAndThermalPrinterTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    // ---------------------------------------------------------------- Thermal Printer

    public function test_thermal_printer_is_an_asset_type_that_is_shared_equipment(): void
    {
        $this->assertSame('Thermal Printer', IctAsset::ASSET_TYPES['asset']['TPR']);

        // Shared equipment: nobody is expected to hold it, and it is not on the building UPS or given a processor.
        $this->assertFalse(IctAsset::isPersonallyAssignedType('TPR'));
        $this->assertNotContains('TPR', IctAsset::UPS_ASSET_TYPES);
        $this->assertNotContains('TPR', IctAsset::PROCESSOR_ASSET_TYPES);
        $this->assertTrue(IctAsset::isPersonallyAssignedType('PRT'));

        // It rolls up into the Printers card and the printer issue category, with no second list to forget.
        $cards = (new \ReflectionClass(AssetDashboardService::class))->getReflectionConstant('CARD_TYPES')->getValue();
        $this->assertContains('TPR', $cards['printers']);
        $this->assertContains('TPR', IctIssueCategory::ASSET_TYPE_NARROWING['Printer/Photocopier']);
    }

    public function test_the_add_asset_form_offers_and_saves_a_thermal_printer_without_a_holder_or_warning(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $maker = IctAssetManufacturer::query()->create(['name' => 'Epson']);
        $model = IctAssetModel::query()->create(['name' => 'TM-T20', 'category' => 'TPR', 'ict_asset_manufacturer_id' => $maker->id, 'is_active' => true]);
        $department = Department::query()->create(['department_name' => 'Revenue']);

        Livewire::test(AssetsList::class)->call('openCreate')->assertSee('Thermal Printer')
            ->set('form.asset_name', 'Counter Receipt Printer')
            ->set('form.serial_number', 'TPR-1')
            ->set('form.asset_type', 'TPR')
            ->set('form.ict_asset_model_id', $model->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', false);

        $asset = IctAsset::query()->where('serial_number', 'TPR-1')->firstOrFail();
        $this->assertSame('TPR', $asset->asset_type);

        // Nobody holds it, and it is not "Unassigned" on the dashboard.
        $this->assertSame(0, IctAsset::query()->needsAssignee()->count());

        // It is a choice in the list's type filter and the Models screen.
        Livewire::test(AssetsList::class)->assertSee('Thermal Printer');
        $this->get(route('assets.settings.models'))->assertOk()->assertSee('Thermal Printer');
    }

    // ---------------------------------------------------------------- Export columns

    protected function exportAsset(): IctAsset
    {
        $district = $this->makeDistrict('Accra Central', 'Greater Accra');
        $maker = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $model = IctAssetModel::query()->create(['name' => 'Latitude 5420', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $maker->id, 'is_active' => true]);
        $department = Department::query()->create(['department_name' => 'Finance']);

        return $this->makeAsset($district, [
            'asset_name' => 'Cashier Laptop',
            'serial_number' => 'EXP-1',
            'tag_no' => 'GWCL/FOE/2026/009',
            'asset_type' => 'Laptop',
            'ict_asset_model_id' => $model->id,
            'department_id' => $department->id,
            'actual_location' => 'Cash office, counter 3',
        ]);
    }

    public function test_the_assets_excel_has_manufacturer_department_asset_tag_and_actual_location(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin($this->makeDistrict('Accra Central', 'Greater Accra')));
        $this->exportAsset();

        $response = $this->get(route('assets.assets.export.excel'))->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);

        $this->assertSame(
            ['Asset Name', 'Serial Number', 'Asset Tag', 'Type', 'Manufacturer', 'Model', 'Processor', 'Department', 'District', 'Region', 'Actual Location', 'Assigned To', 'Status', 'On UPS', 'UPS Condition'],
            $sheet[0]
        );

        $row = array_combine($sheet[0], $sheet[1]);
        $this->assertSame('Cashier Laptop', $row['Asset Name']);
        $this->assertSame('GWCL/FOE/2026/009', $row['Asset Tag']);
        $this->assertSame('Dell', $row['Manufacturer']);
        $this->assertSame('Latitude 5420', $row['Model']);
        $this->assertSame('Finance', $row['Department']);
        $this->assertSame('Accra Central', $row['District']);
        // The asset's own physical location, not the assignee's work location.
        $this->assertSame('Cash office, counter 3', $row['Actual Location']);
    }

    public function test_the_new_columns_follow_the_selection_the_same_scope_and_stay_text_and_are_audited(): void
    {
        $this->seedCoreAssetsAccess();
        $accra = $this->makeDistrict('Accra Central', 'Greater Accra');
        $kumasi = $this->makeDistrict('Kumasi Central', 'Ashanti');
        $ict = $this->ictUser($accra);
        $this->actingAs($ict);
        $this->exportAsset();
        $this->makeAsset($kumasi, ['asset_name' => 'Other Region Laptop', 'tag_no' => '=EVIL()', 'serial_number' => 'EXP-2']);
        $this->makeAsset($accra, ['asset_name' => 'Formula Laptop', 'serial_number' => 'EXP-3', 'tag_no' => '=1+1', 'actual_location' => '@SUM(1,2)']);

        // Only the columns asked for, and a regional ICT user's region.
        $response = $this->get(route('assets.assets.export.excel', ['cols' => 'department,location']))->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $grid = $sheet->toArray(null, false, false, false);

        $this->assertSame(['Asset Name', 'Serial Number', 'Asset Tag', 'Department', 'District', 'Region', 'Actual Location'], $grid[0]);
        $this->assertCount(3, $grid, 'one header and the two Accra assets');
        $this->assertNotContains('Other Region Laptop', array_column($grid, 0));

        // Values starting with = or @ stay text.
        foreach ($grid as $r => $line) {
            if (($line[0] ?? null) === 'Formula Laptop') {
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C'.($r + 1))->getDataType());
                $this->assertSame('=1+1', $sheet->getCell('C'.($r + 1))->getValue());
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('G'.($r + 1))->getDataType());
                $this->assertSame('@SUM(1,2)', $sheet->getCell('G'.($r + 1))->getValue());
            }
        }

        // The PDF carries the same headings and rows, and every export writes one audit row.
        $data = null;
        View::composer('exports.list-pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });
        $this->get(route('assets.assets.export.pdf', ['cols' => 'type_model']))->assertOk();
        $this->assertSame(['Asset Name', 'Serial Number', 'Asset Tag', 'Type', 'Manufacturer', 'Model'], $data['headings']);

        $audit = AuditLog::query()->where('action', 'export_assets_excel')->firstOrFail();
        $this->assertSame(['asset', 'department', 'location'], $audit->new_values['columns']);
        $this->assertSame(2, $audit->new_values['rows']);
    }

    public function test_the_row_cap_still_applies(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->makeAsset($district);
        $this->makeAsset($district);
        config(['gwl.assets_list_export_max_rows' => 1]);

        $this->from(route('assets.assets'))->get(route('assets.assets.export.excel'))->assertRedirect(route('assets.assets'))->assertSessionHas('error');
    }

    // ---------------------------------------------------------------- On screen

    public function test_the_list_and_detail_show_the_tag_manufacturer_and_department(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin($this->makeDistrict('Accra Central', 'Greater Accra')));
        $asset = $this->exportAsset();

        Livewire::test(AssetsList::class)
            ->assertSee('Tag: GWCL/FOE/2026/009')
            ->assertSee('Dell Latitude 5420')
            ->assertSee('Department')
            ->assertSee('Finance');

        $this->get(route('assets.show', $asset))->assertOk()->assertSee('Asset tag')->assertSee('GWCL/FOE/2026/009')->assertSee('Cash office, counter 3');
    }
}
