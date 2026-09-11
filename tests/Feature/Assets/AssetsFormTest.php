<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
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
use Tests\TestCase;

class AssetsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_asset_requires_serial_number_and_department(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(AssetsList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Front Desk PC')
            ->set('form.asset_type', 'PC')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.serial_number', 'form.department_id']);
    }

    public function test_creating_an_asset_persists_it_under_the_asset_category(): void
    {
        $this->seedCoreAssetsAccess();
        $department = Department::query()->create(['department_name' => 'ICT']);
        $this->actingAs($this->superAdmin());

        Livewire::test(AssetsList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Front Desk PC')
            ->set('form.serial_number', 'PC-0001')
            ->set('form.asset_type', 'PC')
            ->set('form.department_id', $department->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'PC-0001')->firstOrFail();
        $this->assertSame(IctAsset::DEVICE_CATEGORY_ASSET, $asset->device_category);
        $this->assertSame($department->id, $asset->department_id);
    }

    public function test_reassigning_an_asset_auto_tracks_the_previous_employee(): void
    {
        $this->seedCoreAssetsAccess();
        $department = Department::query()->create(['department_name' => 'ICT']);
        $employeeOne = $this->createEmployee('100001', 'First Employee');
        $employeeTwo = $this->createEmployee('100002', 'Second Employee');

        $asset = IctAsset::query()->create([
            'asset_name' => 'Laptop A',
            'serial_number' => 'LP-0001',
            'asset_type' => 'Laptop',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'department_id' => $department->id,
            'assigned_to_employee_id' => $employeeOne->id,
        ]);

        $this->actingAs($this->superAdmin());

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

    protected function seedCoreAssetsAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            AssetsRolePermissionSeeder::class,
        ]);
    }

    protected function superAdmin(): User
    {
        $user = $this->user('SA001');
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

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

    protected function createEmployee(string $staffId, string $fullName): Employee
    {
        $region = Region::query()->firstOrCreate(['region_name' => 'Greater Accra']);
        $district = District::query()->firstOrCreate(
            ['district_name' => 'Accra Central District'],
            ['region_id' => $region->id]
        );
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
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-01-01',
            'present_appointment' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
