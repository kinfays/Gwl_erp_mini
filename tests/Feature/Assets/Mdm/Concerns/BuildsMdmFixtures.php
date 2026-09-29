<?php

namespace Tests\Feature\Assets\Mdm\Concerns;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\Mdm\AndroidManagementGateway;
use App\Services\Assets\Mdm\IdTokenVerifier;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Mdm\FakeAndroidManagementGateway;
use Tests\Support\Mdm\FakeIdTokenVerifier;

/**
 * Shared setup for the MDM tests: seeded roles/permissions, a fake Google gateway and token verifier bound into the
 * container (so nothing can reach the network), deterministic MDM config, and small builders for regions, staff
 * and phones. Call setUpMdm() from setUp().
 */
trait BuildsMdmFixtures
{
    protected FakeAndroidManagementGateway $gateway;

    protected FakeIdTokenVerifier $tokens;

    protected function setUpMdm(): void
    {
        Notification::fake();

        $this->gateway = new FakeAndroidManagementGateway;
        $this->tokens = new FakeIdTokenVerifier;
        $this->app->instance(AndroidManagementGateway::class, $this->gateway);
        $this->app->instance(IdTokenVerifier::class, $this->tokens);

        // Independent of whatever the developer has in .env.
        config([
            'gwl.mdm_enabled' => true,
            'gwl.mdm_google_project_id' => 'gwl-test-project',
            'gwl.mdm_enterprise_name' => 'enterprises/LC0test',
            'gwl.mdm_credentials_path' => null,
            'gwl.mdm_enrollment_token_minutes' => 60,
            'gwl.mdm_lost_mode_message' => 'Property of GWL. Please call the number shown.',
            'gwl.mdm_lost_mode_phone' => '0302000000',
            'gwl.mdm_lost_mode_address' => null,
            'gwl.mdm_pubsub_topic' => 'projects/gwl-test-project/topics/amapi',
            'gwl.mdm_pubsub_subscription' => 'projects/gwl-test-project/subscriptions/amapi-pull',
            'gwl.mdm_pubsub_mode' => 'pull',
            'gwl.mdm_pubsub_push_audience' => 'https://erp.example.test/webhooks/android-management',
            'gwl.mdm_pubsub_push_service_account' => 'pubsub-push@gwl-test-project.iam.gserviceaccount.com',
            'gwl.mdm_pubsub_push_token' => 'test-push-secret',
            'gwl.mdm_command_rate_per_minute' => 50,
        ]);

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            AssetsRolePermissionSeeder::class,
        ]);
    }

    protected function district(string $districtName = 'Accra Central District', string $regionName = 'Greater Accra'): District
    {
        $region = Region::query()->firstOrCreate(['region_name' => $regionName]);

        return District::query()->firstOrCreate(['district_name' => $districtName], ['region_id' => $region->id]);
    }

    protected function employeeIn(District $district, string $staffId, string $fullName): Employee
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

    /** A login with one role; give a district to also give them an employee record (and so a region). */
    protected function userWithRole(string $role, string $staffId, ?District $district = null): User
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

        if ($district) {
            $this->employeeIn($district, $staffId, 'User '.$staffId);
        }

        return $user->fresh();
    }

    protected function superAdmin(?District $district = null): User
    {
        return User::query()->where('staff_id', 'SA001')->first() ?? $this->userWithRole('super_admin', 'SA001', $district);
    }

    protected function admin(): User
    {
        return User::query()->where('staff_id', 'AD001')->first() ?? $this->userWithRole('admin', 'AD001');
    }

    protected function ictIn(District $district, string $staffId = 'ICT001'): User
    {
        return $this->userWithRole('ict_team', $staffId, $district);
    }

    /** An enrollable phone (type Ph, Active, serial + IMEI) in the district's region. */
    protected function phone(?District $district = null, array $overrides = []): IctAsset
    {
        $district ??= $this->district();

        return IctAsset::factory()->phone()->create(array_merge([
            'asset_type' => 'Ph',
            'asset_name' => 'Field Phone '.fake()->unique()->numerify('###'),
            'status' => IctAsset::STATUS_ACTIVE,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
        ], $overrides));
    }

    /** A Device resource as Google would send it for an ENROLLMENT / STATUS_REPORT notification. */
    protected function deviceResource(string $name, array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => $name,
            'managementMode' => 'DEVICE_OWNER',
            'state' => 'ACTIVE',
            'appliedState' => 'ACTIVE',
            'policyCompliant' => true,
            'enrollmentTime' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
            'lastStatusReportTime' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'hardwareInfo' => ['serialNumber' => 'SN-DEVICE-1', 'manufacturer' => 'Samsung', 'model' => 'SM-A155F'],
            'networkInfo' => ['imei' => '356938035643809'],
            'softwareInfo' => ['androidVersion' => '14', 'securityPatchLevel' => '2026-08-05'],
        ], $overrides);
    }
}
