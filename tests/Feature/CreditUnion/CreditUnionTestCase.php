<?php

namespace Tests\Feature\CreditUnion;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CreditUnionRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

abstract class CreditUnionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating an Employee triggers EmployeeObserver's user sync + invite mail.
        Notification::fake();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            CreditUnionRolePermissionSeeder::class,
        ]);
    }

    protected function officer(string $staffId = 'CUO001'): User
    {
        return $this->userWithRole($staffId, 'credit_union_officer');
    }

    protected function committeeMember(string $staffId = 'CUC001'): User
    {
        return $this->userWithRole($staffId, 'credit_union_committee');
    }

    /**
     * EmployeeObserver already creates the matching user when the employee is created,
     * so this only tops it up with the baseline employee role.
     */
    protected function employeeUser(Employee $employee): User
    {
        $user = User::query()->where('staff_id', $employee->staff_id)->first()
            ?? $this->user($employee->staff_id, [
                'full_name' => $employee->full_name,
                'employee_id' => $employee->id,
            ]);

        $user->forceFill(['must_change_password' => false])->save();

        $user->roles()->syncWithoutDetaching(
            Role::query()->where('name', 'employee')->firstOrFail()
        );

        return $user->fresh();
    }

    protected function userWithRole(string $staffId, string $role): User
    {
        $user = $this->user($staffId);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user->fresh();
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
