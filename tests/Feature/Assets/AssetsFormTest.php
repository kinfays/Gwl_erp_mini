<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_an_empty_asset_form_rejects_every_mandatory_field(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin($this->district('Accra Central District', 'Greater Accra')));

        Livewire::test(AssetsList::class)
            ->call('openCreate')
            ->call('save')
            ->assertHasErrors([
                'form.asset_name' => 'required',
                'form.serial_number' => 'required',
                'form.asset_type' => 'required',
                'form.ict_asset_model_id' => 'required',
                'form.assigned_to_employee_id' => 'required',
                'form.district_id' => 'required',
                'form.department_id' => 'required',
            ]);

        $this->assertSame(0, IctAsset::query()->count());
    }

    #[DataProvider('mandatoryAssetFields')]
    public function test_each_mandatory_asset_field_is_rejected_when_left_blank(string $field): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->district('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $this->fillValidAssetForm(Livewire::test(AssetsList::class)->call('openCreate'), $district)
            ->set("form.{$field}", '')
            ->call('save')
            ->assertHasErrors(["form.{$field}" => 'required']);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public static function mandatoryAssetFields(): array
    {
        return [
            'asset name' => ['asset_name'],
            'serial number' => ['serial_number'],
            'type' => ['asset_type'],
            'model' => ['ict_asset_model_id'],
            'assigned to' => ['assigned_to_employee_id'],
            'location' => ['district_id'],
        ];
    }

    public function test_creating_an_asset_persists_it_under_the_asset_category_in_the_actor_region(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->district('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $this->fillValidAssetForm(Livewire::test(AssetsList::class)->call('openCreate'), $district)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'PC-0001')->firstOrFail();
        $this->assertSame(IctAsset::DEVICE_CATEGORY_ASSET, $asset->device_category);
        $this->assertSame($district->id, $asset->district_id);
        $this->assertSame($district->region_id, $asset->region_id);
        $this->assertNotNull($asset->ict_asset_model_id);
        $this->assertNotNull($asset->assigned_to_employee_id);
    }

    public function test_region_is_forced_to_the_actor_region_and_never_accepted_from_form_input(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        $component = Livewire::test(AssetsList::class)
            ->assertSet('form.region_id', $homeDistrict->region_id)
            ->call('openCreate')
            ->assertSet('form.region_id', $homeDistrict->region_id);

        $this->fillValidAssetForm($component, $homeDistrict)
            ->set('form.region_id', $otherDistrict->region_id)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'PC-0001')->firstOrFail();
        $this->assertSame($homeDistrict->region_id, $asset->region_id);
    }

    public function test_editing_an_asset_also_stamps_the_actor_region(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $legacyDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $department = Department::query()->create(['department_name' => 'ICT']);
        $assignee = $this->createEmployee('100001', 'Assigned Employee', $homeDistrict);

        $asset = IctAsset::query()->create([
            'asset_name' => 'Laptop A',
            'serial_number' => 'LP-0001',
            'asset_type' => 'Laptop',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'ict_asset_model_id' => $this->assetModel()->id,
            'assigned_to_employee_id' => $assignee->id,
            'department_id' => $department->id,
            'region_id' => $legacyDistrict->region_id,
            'district_id' => $legacyDistrict->id,
        ]);

        $this->actingAs($this->superAdmin($homeDistrict));

        Livewire::test(AssetsList::class)
            ->call('openEdit', $asset->id)
            ->assertSet('form.region_id', $homeDistrict->region_id)
            ->call('save')
            ->assertHasErrors(['form.district_id' => 'exists'])
            ->set('form.district_id', $homeDistrict->id)
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertSame($homeDistrict->region_id, $asset->region_id);
        $this->assertSame($homeDistrict->id, $asset->district_id);
    }

    public function test_location_outside_the_actor_region_is_rejected(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        $this->fillValidAssetForm(Livewire::test(AssetsList::class)->call('openCreate'), $homeDistrict)
            ->set('form.district_id', $otherDistrict->id)
            ->call('save')
            ->assertHasErrors(['form.district_id' => 'exists']);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_location_dropdown_only_offers_districts_in_the_actor_region(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        $formDistricts = Livewire::test(AssetsList::class)->viewData('formDistricts');

        $this->assertSame([$homeDistrict->id], $formDistricts->pluck('id')->all());
    }

    public function test_actor_without_a_region_on_file_cannot_save_an_asset(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->district('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin());

        $this->fillValidAssetForm(Livewire::test(AssetsList::class)->call('openCreate'), $district)
            ->call('save')
            ->assertHasErrors(['form.region_id'])
            ->assertSee('Your account has no region assigned');

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_reassigning_an_asset_auto_tracks_the_previous_employee(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->district('Accra Central District', 'Greater Accra');
        $department = Department::query()->create(['department_name' => 'ICT']);
        $employeeOne = $this->createEmployee('100001', 'First Employee', $district);
        $employeeTwo = $this->createEmployee('100002', 'Second Employee', $district);

        $asset = IctAsset::query()->create([
            'asset_name' => 'Laptop A',
            'serial_number' => 'LP-0001',
            'asset_type' => 'Laptop',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'ict_asset_model_id' => $this->assetModel()->id,
            'department_id' => $department->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'assigned_to_employee_id' => $employeeOne->id,
        ]);

        $this->actingAs($this->superAdmin($district));

        Livewire::test(AssetsList::class)
            ->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', $employeeTwo->id)
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertSame($employeeTwo->id, $asset->assigned_to_employee_id);
        $this->assertSame($employeeOne->id, $asset->previous_assigned_to_employee_id);
    }

    public function test_asset_type_dropdown_is_scoped_to_the_asset_category_vocabulary(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        $assetTypes = Livewire::test(AssetsList::class)->viewData('assetTypes');

        $this->assertArrayHasKey('PC', $assetTypes);
        $this->assertArrayNotHasKey('RT', $assetTypes);
        $this->assertArrayNotHasKey('POS', $assetTypes);
    }

    protected function fillValidAssetForm($component, District $district)
    {
        $assignee = Employee::query()->where('staff_id', '100001')->first()
            ?? $this->createEmployee('100001', 'Assigned Employee', $district);
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);

        return $component
            ->set('form.asset_name', 'Front Desk PC')
            ->set('form.serial_number', 'PC-0001')
            ->set('form.asset_type', 'PC')
            ->set('form.ict_asset_model_id', $this->assetModel()->id)
            ->set('form.assigned_to_employee_id', $assignee->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }

    protected function seedCoreAssetsAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            AssetsRolePermissionSeeder::class,
        ]);
    }

    /**
     * Region is derived from the actor's employee record, so pass a district
     * to give the super admin one; omit it for an account with no region.
     */
    protected function superAdmin(?District $homeDistrict = null): User
    {
        $user = $this->user('SA001');
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        if ($homeDistrict) {
            $this->createEmployee('SA001', 'Super Admin', $homeDistrict);
        }

        return $user;
    }

    protected function user(string $staffId, array $overrides = []): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            ...$overrides,
        ]);
    }

    protected function district(string $districtName, string $regionName): District
    {
        $region = Region::query()->firstOrCreate(['region_name' => $regionName]);

        return District::query()->firstOrCreate(
            ['district_name' => $districtName],
            ['region_id' => $region->id]
        );
    }

    protected function assetModel(): IctAssetModel
    {
        return IctAssetModel::query()->firstOrCreate(
            ['name' => 'HP EliteDesk 800 G6'],
            ['category' => 'PC', 'is_active' => true]
        );
    }

    protected function createEmployee(string $staffId, string $fullName, District $district): Employee
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $fullName,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-01-01',
            'present_appointment' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
