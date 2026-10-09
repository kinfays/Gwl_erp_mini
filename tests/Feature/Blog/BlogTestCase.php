<?php

namespace Tests\Feature\Blog;

use App\Models\BlogPost;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RegionalBlogRolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class BlogTestCase extends TestCase
{
    use RefreshDatabase;

    protected Region $accraWest;

    protected Region $ashanti;

    protected District $sowutuom;

    protected District $odorkor;

    protected District $headOffice;

    protected District $kumasi;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating an Employee triggers EmployeeObserver's user sync + invite mail.
        Notification::fake();
        Storage::fake('local');

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            RegionalBlogRolePermissionSeeder::class,
        ]);

        $this->accraWest = Region::query()->create(['region_name' => 'Accra West']);
        $this->ashanti = Region::query()->create(['region_name' => 'Ashanti']);
        $this->sowutuom = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Sowutuom']);
        $this->odorkor = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Odorkor']);
        $this->headOffice = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Head Office']);
        $this->kumasi = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
    }

    // ---------------------------------------------------------------- people

    protected function employee(string $staffId, ?Region $region = null, ?District $district = null): Employee
    {
        $region ??= $this->accraWest;
        $district ??= $region->is($this->ashanti) ? $this->kumasi : $this->sowutuom;

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Staff '.$staffId,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'General Staff'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Public Relations'])->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-01',
            'present_appointment' => '2020-01-01',
            'is_active' => true,
        ]);
    }

    /** A user (with their employee record in the given place) holding the given roles. */
    protected function userWithRoles(string $staffId, array $roles, ?Region $region = null, ?District $district = null): User
    {
        $user = User::query()->where('staff_id', $staffId)->first();

        if (! $user) {
            $employee = $this->employee($staffId, $region, $district);
            $user = User::query()->where('staff_id', $employee->staff_id)->firstOrFail();
        }

        $user->forceFill(['must_change_password' => false])->save();

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    /** A login with no employee record, so no region. */
    protected function userWithoutEmployee(string $staffId, array $roles): User
    {
        $user = User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => $staffId.'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    protected function reader(string $staffId = '100001', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['employee'], $region, $district);
    }

    protected function prOfficer(string $staffId = '300001', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['employee', 'pr_officer'], $region, $district);
    }

    protected function superAdmin(string $staffId = '900000'): User
    {
        return $this->userWithRoles($staffId, ['super_admin']);
    }

    // ---------------------------------------------------------------- posts

    /** An article written straight to the database (bypassing the service). */
    protected function post(Region $region, array $attributes = []): BlogPost
    {
        return BlogPost::query()->create([
            'region_id' => $region->id,
            'title' => 'Article '.uniqid(),
            'category' => 'activity',
            'body' => 'What happened at the activity.',
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now(),
            ...$attributes,
        ]);
    }

    protected function draft(Region $region, array $attributes = []): BlogPost
    {
        return $this->post($region, ['status' => BlogPost::STATUS_DRAFT, 'published_at' => null, ...$attributes]);
    }
}
