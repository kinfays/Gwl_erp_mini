<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\LeaveReports;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StaffRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class StaffLeaveReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_leave_report_page_is_available_to_hr_and_not_managers(): void
    {
        $this->seedStaffAccess();

        $hr = $this->user('HR001');
        $hr->roles()->attach(Role::query()->where('name', 'hr_headoffice')->firstOrFail());

        $manager = $this->user('MGR001');
        $manager->roles()->attach(Role::query()->where('name', 'manager')->firstOrFail());

        $this->actingAs($hr)
            ->get(route('staff.reports'))
            ->assertOk()
            ->assertSee('Staff Leave Reports')
            ->assertSee('Male Vs Female Total');

        $this->actingAs($manager)
            ->get(route('staff.reports'))
            ->assertForbidden();
    }

    public function test_hr_region_report_counts_only_the_actor_region(): void
    {
        $this->seedStaffAccess();

        $regionA = Region::query()->create(['region_name' => 'Greater Accra']);
        $regionB = Region::query()->create(['region_name' => 'Ashanti']);
        $districtA = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $regionA->id]);
        $districtB = District::query()->create(['district_name' => 'Kumasi Metro', 'region_id' => $regionB->id]);

        $actorEmployee = $this->employee('HRR001', $regionA, $districtA, 'Female');
        $actor = $this->user('HRR001', [
            'employee_id' => $actorEmployee->id,
            'full_name' => $actorEmployee->full_name,
            'email' => $actorEmployee->email,
        ]);
        $actor->roles()->attach(Role::query()->where('name', 'hr_region')->firstOrFail());

        $this->employee('EMP001', $regionA, $districtA, 'Male');
        $onLeave = $this->employee('EMP002', $regionA, $districtA, 'Female');
        $this->employee('EMP003', $regionB, $districtB, 'Male');

        $this->leaveRequest($onLeave, 'Approved', today()->subDay()->toDateString(), today()->addDays(2)->toDateString());

        $this->actingAs($actor);

        Livewire::test(LeaveReports::class)
            ->assertSet('payload.statCards.0.value', '3')
            ->assertSet('payload.statCards.1.value', '2')
            ->assertSet('payload.statCards.2.value', '1')
            ->assertSet('payload.statCards.3.value', '1')
            ->assertSee('Greater Accra')
            ->assertDontSee('Kumasi Metro');
    }

    public function test_hr_region_report_requires_an_employee_region_profile(): void
    {
        $this->seedStaffAccess();

        $hr = $this->user('HRNOREG');
        $hr->roles()->attach(Role::query()->where('name', 'hr_region')->firstOrFail());

        $this->actingAs($hr)
            ->get(route('staff.reports'))
            ->assertForbidden();
    }

    protected function seedStaffAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            StaffRolePermissionSeeder::class,
        ]);

        $this->assertTrue(Permission::query()->where('name', 'staff.view_reports')->exists());
        $this->assertTrue(ModuleAccess::query()->where('module', Permission::MODULE_STAFF)->where('can_access', true)->exists());
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

    protected function employee(string $staffId, Region $region, District $district, string $gender): Employee
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

        return Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Employee '.$staffId,
            'gender' => $gender,
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
            'is_active' => true,
        ]));
    }

    protected function leaveRequest(Employee $employee, string $status, string $startDate, string $endDate): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'requester_id' => $employee->id,
            'leave_type' => 'Annual',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days_applied' => 3,
            'leave_details' => null,
            'manager_id' => $employee->id,
            'manager_comments' => null,
            'manager_recommendation' => 'Recommended',
            'leave_status' => $status,
            'approved_by_id' => $status === 'Approved' ? $employee->id : null,
            'chiefManager_comments' => null,
            'request_year' => (int) substr($startDate, 0, 4),
            'department_id' => $employee->department_id,
            'region_id' => $employee->region_id,
            'file_attachment' => null,
        ]);
    }
}
