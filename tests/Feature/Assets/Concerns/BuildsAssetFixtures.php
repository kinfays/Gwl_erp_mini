<?php

namespace Tests\Feature\Assets\Concerns;

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
use Illuminate\Support\Facades\Hash;

/** Inline fixtures for the Assets feature tests (the older modules have no factories). */
trait BuildsAssetFixtures
{
    protected function seedCoreAssetsAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            AssetsRolePermissionSeeder::class,
        ]);
    }

    protected function makeUser(string $staffId, string $role, ?District $home = null): User
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

        if ($home) {
            $this->makeEmployee($staffId, 'User '.$staffId, $home);
        }

        return $user;
    }

    protected function superAdmin(?District $home = null): User
    {
        return $this->makeUser('SA001', 'super_admin', $home);
    }

    /** A regional ICT user: reads and writes only their own region. */
    protected function ictUser(District $home, string $staffId = 'ICT001'): User
    {
        return $this->makeUser($staffId, 'ict_team', $home);
    }

    protected function makeDistrict(string $districtName, string $regionName): District
    {
        $region = Region::query()->firstOrCreate(['region_name' => $regionName]);

        return District::query()->firstOrCreate(['district_name' => $districtName], ['region_id' => $region->id]);
    }

    protected function makeEmployee(string $staffId, string $fullName, District $district): Employee
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

    protected function makeModel(string $name = 'HP EliteDesk 800 G6', string $category = 'PC'): IctAssetModel
    {
        return IctAssetModel::query()->firstOrCreate(['name' => $name], ['category' => $category, 'is_active' => true]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeAsset(District $district, array $overrides = []): IctAsset
    {
        static $counter = 0;
        $counter++;

        return IctAsset::query()->create([
            'asset_name' => 'Asset '.$counter,
            'serial_number' => 'SN-'.str_pad((string) $counter, 5, '0', STR_PAD_LEFT).'-'.uniqid(),
            'asset_type' => 'Laptop',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            ...$overrides,
        ]);
    }
}
