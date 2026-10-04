<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\IctAssetTransfer;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetTransferService;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AssetTransferHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected District $district;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $region->id]);

        $this->actor = User::query()->create([
            'staff_id' => 'SA001',
            'full_name' => 'Super Admin',
            'email' => 'sa001@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->actor->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());
        $this->employee('SA001', 'Super Admin');

        $this->actingAs($this->actor);
    }

    // ---- Damaged status ----------------------------------------------------------------------------------------

    public function test_damaged_requires_a_reason_on_every_form(): void
    {
        $this->assertContains(IctAsset::STATUS_DAMAGED, IctAsset::STATUSES);
        $this->assertNotContains('Available', IctAsset::STATUSES);
        $this->assertNotContains('Disposed', IctAsset::STATUSES);

        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_name', 'PC')->set('form.serial_number', 'D-1')->set('form.asset_type', 'PC')
            ->set('form.status', IctAsset::STATUS_DAMAGED)->set('form.status_reason', '')
            ->call('save')->assertHasErrors(['form.status_reason' => 'required']);

        Livewire::test(PhonesList::class)->call('openCreate')
            ->set('form.asset_name', 'Phone')->set('form.asset_type', 'Ph')
            ->set('form.status', IctAsset::STATUS_DAMAGED)->set('form.status_reason', '')
            ->call('save')->assertHasErrors(['form.status_reason' => 'required']);

        Livewire::test(NetworkList::class)->call('openCreate')
            ->set('form.asset_name', 'Router')->set('form.asset_type', 'RT')
            ->set('form.status', IctAsset::STATUS_DAMAGED)->set('form.status_reason', '')
            ->call('save')->assertHasErrors(['form.status_reason' => 'required']);

        $this->assertSame(0, IctAsset::query()->count());

        Livewire::test(PhonesList::class)->call('openCreate')
            ->set('form.asset_name', 'Phone')->set('form.asset_type', 'Ph')
            ->set('form.status', IctAsset::STATUS_DAMAGED)->set('form.status_reason', 'Cracked screen')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Cracked screen', IctAsset::query()->firstOrFail()->status_reason);
    }

    public function test_reason_is_optional_for_other_statuses(): void
    {
        Livewire::test(NetworkList::class)->call('openCreate')
            ->set('form.asset_name', 'Router')->set('form.asset_type', 'RT')
            ->set('form.status', IctAsset::STATUS_LOST)->set('form.status_reason', '')
            ->call('save')->assertHasNoErrors();

        $this->assertNull(IctAsset::query()->firstOrFail()->status_reason);
    }

    public function test_every_status_dropdown_and_filter_offers_damaged(): void
    {
        foreach ([AssetsList::class, PhonesList::class, NetworkList::class] as $component) {
            Livewire::test($component)
                ->assertViewHas('statusOptions', fn ($options) => $options === IctAsset::STATUSES && in_array('Damaged', $options, true))
                ->assertSeeHtml('<option value="Damaged">Damaged</option>');

            // The filter narrows to Damaged assets.
            Livewire::test($component)->set('status', 'Damaged')->assertSet('status', 'Damaged');
        }
    }

    public function test_status_filter_returns_only_damaged_assets(): void
    {
        $this->asset(['asset_name' => 'Broken', 'status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Dropped']);
        $this->asset(['asset_name' => 'Fine']);

        Livewire::test(AssetsList::class)->set('status', 'Damaged')
            ->assertViewHas('assets', fn ($assets) => $assets->pluck('asset_name')->all() === ['Broken']);
    }

    public function test_status_pill_gives_damaged_a_tone_distinct_from_in_repair(): void
    {
        $damaged = Blade::render('<x-ui.status-pill domain="asset" status="Damaged" />');
        $repair = Blade::render('<x-ui.status-pill domain="asset" status="In Repair" />');

        $this->assertStringContainsString('ui-pill-danger', $damaged);
        $this->assertStringContainsString('ui-pill-warning', $repair);
        $this->assertStringNotContainsString('ui-pill-warning', $damaged);
        $this->assertStringContainsString('Damaged', $damaged);
    }

    // ---- Transfer logging ---------------------------------------------------------------------------------------

    public function test_creating_an_asset_logs_no_transfer(): void
    {
        $holder = $this->employee('E1', 'Kwame Mensah');
        $model = $this->model();

        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_name', 'PC')->set('form.serial_number', 'T-1')->set('form.asset_type', 'PC')
            ->set('form.ict_asset_model_id', $model->id)
            ->set('form.assigned_to_employee_id', $holder->id)
            ->set('form.department_id', Department::query()->value('id'))
            ->set('form.district_id', $this->district->id)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, IctAsset::query()->count());
        $this->assertSame(0, IctAssetTransfer::query()->count());
    }

    public function test_each_changed_field_logs_exactly_one_transfer_with_from_and_to(): void
    {
        $kwame = $this->employee('E1', 'Kwame Mensah');
        $ama = $this->employee('E2', 'Ama Boateng');
        $tema = District::query()->create(['district_name' => 'Tema', 'region_id' => $this->district->region_id]);
        $asset = $this->asset([
            'assigned_to_employee_id' => $kwame->id,
            'district_id' => $this->district->id,
            'region_id' => $this->district->region_id,
            'ict_asset_model_id' => $this->model()->id,
            'department_id' => Department::query()->value('id'),
        ]);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', $ama->id)
            ->set('form.status', IctAsset::STATUS_DAMAGED)
            ->set('form.status_reason', 'faulty power button')
            ->set('form.district_id', $tema->id)
            ->call('save')->assertHasNoErrors();

        $rows = IctAssetTransfer::query()->where('ict_asset_id', $asset->id)->get()->keyBy('transfer_type');
        $this->assertCount(3, $rows);

        $this->assertSame($kwame->id, $rows['assignment_change']->from_employee_id);
        $this->assertSame($ama->id, $rows['assignment_change']->to_employee_id);
        $this->assertSame('Active', $rows['status_change']->from_status);
        $this->assertSame('Damaged', $rows['status_change']->to_status);
        $this->assertSame('faulty power button', $rows['status_change']->reason);
        $this->assertSame($this->district->id, $rows['district_change']->from_district_id);
        $this->assertSame($tema->id, $rows['district_change']->to_district_id);
        $this->assertSame($this->actor->id, $rows['status_change']->performed_by_user_id);

        // Saving again with nothing changed logs nothing more.
        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)->call('save')->assertHasNoErrors();
        $this->assertSame(3, IctAssetTransfer::query()->count());
    }

    public function test_changing_only_the_status_logs_only_a_status_change(): void
    {
        $asset = $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'region_id' => $this->district->region_id]);

        Livewire::test(PhonesList::class)->call('openEdit', $asset->id)
            ->set('form.status', IctAsset::STATUS_IN_REPAIR)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['status_change'], IctAssetTransfer::query()->pluck('transfer_type')->all());
    }

    public function test_unassigning_logs_an_assignment_change_with_no_target(): void
    {
        $kwame = $this->employee('E1', 'Kwame Mensah');
        $asset = $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'assigned_to_employee_id' => $kwame->id, 'region_id' => $this->district->region_id]);

        Livewire::test(PhonesList::class)->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', null)
            ->call('save')->assertHasNoErrors();

        $row = IctAssetTransfer::query()->firstOrFail();
        $this->assertSame($kwame->id, $row->from_employee_id);
        $this->assertNull($row->to_employee_id);
        $this->assertSame('Unassigned from Kwame Mensah', $row->summary());
    }

    public function test_service_can_log_a_replacement_pair_for_the_future_replace_device_flow(): void
    {
        $old = $this->asset(['asset_name' => 'Old phone']);
        $new = $this->asset(['asset_name' => 'New phone']);
        $service = app(AssetTransferService::class);

        $service->log($new, IctAssetTransfer::TYPE_REPLACEMENT, ['related_asset_id' => $old->id], $this->actor->id);
        $service->log($old, IctAssetTransfer::TYPE_REPLACEMENT, ['related_asset_id' => $new->id], $this->actor->id);

        $this->assertSame($old->id, $new->transfers()->firstOrFail()->related_asset_id);
        $this->assertSame($new->id, $old->transfers()->firstOrFail()->related_asset_id);

        $this->expectException(\InvalidArgumentException::class);
        $service->log($new, 'teleport', [], null);
    }

    // ---- History display ----------------------------------------------------------------------------------------

    public function test_history_lists_the_assets_transfers_newest_first_in_plain_language(): void
    {
        $kwame = $this->employee('E1', 'Kwame Mensah');
        $ama = $this->employee('E2', 'Ama Boateng');
        $asset = $this->asset(['region_id' => $this->district->region_id]);
        $other = $this->asset();
        $service = app(AssetTransferService::class);

        $service->log($asset, IctAssetTransfer::TYPE_ASSIGNMENT_CHANGE, ['from_employee_id' => $kwame->id, 'to_employee_id' => $ama->id, 'occurred_at' => now()->subDays(5)], null);
        $service->log($asset, IctAssetTransfer::TYPE_STATUS_CHANGE, ['from_status' => 'Active', 'to_status' => 'Damaged', 'reason' => 'faulty power button', 'occurred_at' => now()->subDay()], null);
        $service->log($other, IctAssetTransfer::TYPE_STATUS_CHANGE, ['from_status' => 'Active', 'to_status' => 'Lost'], null);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->assertViewHas('history', fn ($history) => $history->pluck('transfer_type')->all() === ['status_change', 'assignment_change'])
            ->assertSeeInOrder([
                'Status changed: Active -&gt; Damaged (faulty power button)',
                'Reassigned from Kwame Mensah to Ama Boateng',
            ], false)
            ->assertDontSee('Active -&gt; Lost', false);

        Livewire::test(AssetsList::class)->call('openEdit', $other->id)->assertSet('editingAssetId', $other->id);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    protected function asset(array $overrides = []): IctAsset
    {
        static $n = 0;
        $n++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$n,
            'serial_number' => 'TR-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }

    protected function model(): IctAssetModel
    {
        return IctAssetModel::query()->firstOrCreate(['name' => 'ThinkPad'], ['category' => 'PC', 'is_active' => true]);
    }

    protected function employee(string $staffId, string $name): Employee
    {
        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Administration'])->id,
            'region_id' => $this->district->region_id,
            'district_id' => $this->district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-01-01',
            'present_appointment' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
