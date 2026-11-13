<?php

namespace Tests\Feature\Assets;

use App\Exports\ListExport;
use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\PhonesList;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\IctAsset;
use App\Models\IctAssetTransfer;
use App\Services\Assets\AssetListColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class GeneralUseAssetTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_the_column_exists_defaults_to_false_and_existing_assets_stay_false(): void
    {
        $this->assertTrue(Schema::hasColumn('ict_assets', 'is_general_use'));

        $asset = $this->makeAsset($this->makeDistrict('Accra Central District', 'Greater Accra'));

        $this->assertFalse($asset->fresh()->is_general_use);
    }

    public function test_the_form_has_the_checkbox_and_ticking_it_hides_and_clears_assigned_to(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('40001', 'Some Holder', $district);
        $this->actingAs($this->superAdmin($district));

        Livewire::test(AssetsList::class)->call('openCreate')
            ->assertSee('General use (not assigned to a person)')
            ->assertSeeHtml('searchEmployees')
            ->set('form.assigned_to_employee_id', $holder->id)
            ->set('form.is_general_use', true)
            ->assertSet('form.assigned_to_employee_id', null)
            ->assertDontSeeHtml('searchEmployees')
            ->set('form.is_general_use', false)
            ->assertSeeHtml('searchEmployees');
    }

    public function test_a_general_use_asset_is_saved_without_a_holder_and_without_a_warning_but_a_normal_one_is_warned(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Shared Printer', 'PRT')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', true);
        $this->assertSame(0, IctAsset::query()->where('asset_name', 'Shared Printer')->count());

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Shared Printer', 'PRT')
            ->set('form.is_general_use', true)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Shared Printer')->firstOrFail();
        $this->assertTrue($asset->is_general_use);
        $this->assertNull($asset->assigned_to_employee_id);
    }

    public function test_an_assignee_sent_for_a_general_use_asset_is_ignored_by_the_server(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('40002', 'Sent Anyway', $district);
        $this->actingAs($this->superAdmin($district));

        // Hand the component a holder AFTER the checkbox is ticked (a tampered request); the save still drops it.
        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Shared PC', 'PC')
            ->set('form.is_general_use', true)
            ->set('form.assigned_to_employee_id', $holder->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(IctAsset::query()->where('asset_name', 'Shared PC')->value('assigned_to_employee_id'));
    }

    public function test_ticking_general_use_on_an_assigned_asset_clears_the_assignment_with_history_and_audit(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('40003', 'Current Holder', $district);
        $this->actingAs($this->superAdmin($district));
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);
        $asset = $this->makeAsset($district, [
            'asset_name' => 'Team PC',
            'asset_type' => 'PC',
            'assigned_to_employee_id' => $holder->id,
            'ict_asset_model_id' => $this->makeModel()->id,
            'department_id' => $department->id,
        ]);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->assertSet('form.assigned_to_employee_id', $holder->id)
            ->set('form.is_general_use', true)
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertTrue($asset->is_general_use);
        $this->assertNull($asset->assigned_to_employee_id);
        $this->assertSame($holder->id, $asset->previousHolder()?->id);

        // The same trail as the normal unassign: a transfer row and an audit snapshot.
        $this->assertTrue(IctAssetTransfer::query()->where('ict_asset_id', $asset->id)->where('from_employee_id', $holder->id)->whereNull('to_employee_id')->exists());
        $log = AuditLog::query()->where('action', 'update_asset')->where('target_id', $asset->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('is_general_use', json_encode($log->toArray()));

        // Unticking brings the "nobody assigned" warning back (and nothing is saved until it is confirmed).
        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->assertSet('form.is_general_use', true)
            ->set('form.is_general_use', false)
            ->call('save')
            ->assertSet('confirmNoAssignee', true);
        $this->assertTrue($asset->fresh()->is_general_use);
    }

    public function test_a_general_use_asset_is_never_unassigned_but_an_ordinary_one_is(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $shared = $this->makeAsset($district, ['asset_type' => 'Laptop', 'is_general_use' => true]);
        $loose = $this->makeAsset($district, ['asset_type' => 'Laptop']);

        $this->assertFalse($shared->requiresAssignee());
        $this->assertTrue($loose->requiresAssignee());
        $this->assertSame([$loose->id], IctAsset::query()->needsAssignee()->pluck('id')->all());
    }

    public function test_the_lists_the_detail_page_and_the_exports_show_general_use_in_place_of_the_holder(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $shared = $this->makeAsset($district, ['asset_name' => 'Lobby PC', 'asset_type' => 'PC', 'is_general_use' => true]);
        $this->makeAsset($district, ['asset_name' => 'Pool phone', 'asset_type' => 'Ph', 'device_category' => 'phone', 'is_general_use' => true]);
        $this->makeAsset($district, ['asset_name' => 'Loose laptop', 'asset_type' => 'Laptop']);

        Livewire::test(AssetsList::class)->assertSee('General use')->assertSee('Unassigned');
        Livewire::test(PhonesList::class)->assertSee('General use');
        $this->get(route('assets.show', $shared))->assertOk()->assertSee('General use');

        $this->assertSame('General use', AssetListColumns::exportRow(AssetListColumns::ASSETS, ['assigned_to'], $shared->load('assignedTo'))[0]);

        Excel::fake();
        $this->get(route('assets.assets.export.excel', ['cols' => 'asset,assigned_to']))->assertOk();
        Excel::assertDownloaded('assets_'.now()->format('Y_m_d').'.xlsx', function (ListExport $export) {
            $cells = $export->collection()->pluck(3, 0)->all(); // asset name => assigned to
            $this->assertSame('General use', $cells['Lobby PC']);
            $this->assertSame('Unassigned', $cells['Loose laptop']);

            return true;
        });
    }

    public function test_the_phones_form_has_the_same_checkbox_and_behaviour(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('40004', 'Phone Holder', $district);
        $this->actingAs($this->superAdmin($district));

        Livewire::test(PhonesList::class)->call('openCreate')
            ->assertSee('General use (not assigned to a person)')
            ->set('form.asset_name', 'Shared POS')
            ->set('form.serial_number', 'POS-1')
            ->set('form.asset_type', 'POS')
            ->set('form.ict_asset_model_id', $this->makeModel('Verifone', 'POS')->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->set('form.district_id', $district->id)
            ->set('form.assigned_to_employee_id', $holder->id)
            ->set('form.is_general_use', true)
            ->assertSet('form.assigned_to_employee_id', null)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Shared POS')->firstOrFail();
        $this->assertTrue($asset->is_general_use);
        $this->assertNull($asset->assigned_to_employee_id);
    }

    public function test_choosing_the_pos_type_on_a_new_phone_form_ticks_general_use_and_it_can_be_unticked(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin($this->makeDistrict('Accra Central District', 'Greater Accra')));

        Livewire::test(PhonesList::class)->call('openCreate')
            ->assertSet('form.is_general_use', false)
            ->set('form.asset_type', 'Ph')
            ->assertSet('form.is_general_use', false)
            ->set('form.asset_type', 'POS')
            ->assertSet('form.is_general_use', true)
            ->set('form.is_general_use', false)
            ->assertSet('form.is_general_use', false);
    }

    public function test_the_migration_marks_only_unheld_pos_terminals_as_general_use(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('40005', 'POS Holder', $district);
        $loosePos = $this->makeAsset($district, ['asset_type' => 'POS', 'device_category' => 'phone']);
        $heldPos = $this->makeAsset($district, ['asset_type' => 'POS', 'device_category' => 'phone', 'assigned_to_employee_id' => $holder->id]);
        $phone = $this->makeAsset($district, ['asset_type' => 'Ph', 'device_category' => 'phone']);

        (require base_path('database/migrations/2026_11_06_000002_flag_unheld_pos_terminals_as_general_use.php'))->up();

        $this->assertTrue($loosePos->fresh()->is_general_use);
        $this->assertFalse($heldPos->fresh()->is_general_use);
        $this->assertSame($holder->id, $heldPos->fresh()->assigned_to_employee_id, 'nothing is unassigned by the migration');
        $this->assertFalse($phone->fresh()->is_general_use);
    }

    public function test_the_flag_never_applies_to_network_devices(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $router = app(\App\Services\Assets\AssetRecordService::class)->save([
            'asset_name' => 'Edge router',
            'serial_number' => 'RT-77',
            'asset_type' => 'RT',
            'status' => 'Active',
            'is_general_use' => true,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
        ], IctAsset::DEVICE_CATEGORY_NETWORK);

        $this->assertFalse($router->fresh()->is_general_use);
    }

    protected function fillForm($component, $district, string $name, string $type)
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);
        $holder = \App\Models\Employee::query()->where('staff_id', '40099')->first() ?? $this->makeEmployee('40099', 'Form Holder', $district);

        return $component
            ->set('form.asset_name', $name)
            ->set('form.serial_number', 'GU-'.uniqid())
            ->set('form.asset_type', $type)
            ->set('form.ict_asset_model_id', $this->makeModel('Any model '.$type, $type)->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }
}
