<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\HrDashboard;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class HrDashboardZoneStaffCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_region_zone_staff_count_is_scoped_to_actor_region_and_active_staff(): void
    {
        $hrRegionRole = $this->createRole('hr_region');
        $regionA = Region::query()->create(['region_name' => 'Greater Accra']);
        $regionB = Region::query()->create(['region_name' => 'Ashanti']);

        $actorEmployee = $this->createEmployee($regionA, 'HR001', true);
        $actor = $this->createUserForEmployee($actorEmployee);
        $actor->roles()->attach($hrRegionRole);

        $this->createEmployee($regionA, 'EMP002', true);
        $this->createEmployee($regionA, 'EMP003', true);
        $this->createEmployee($regionA, 'EMP004', false);
        $this->createEmployee($regionB, 'EMP005', true);

        $this->actingAs($actor);

        Livewire::test(HrDashboard::class)
            ->assertSet('zoneStaffCount', 3);
    }

    public function test_hr_headoffice_zone_staff_count_includes_all_active_staff(): void
    {
        $hrHeadOfficeRole = $this->createRole('hr_headoffice');
        $regionA = Region::query()->create(['region_name' => 'Greater Accra']);
        $regionB = Region::query()->create(['region_name' => 'Ashanti']);

        $actorEmployee = $this->createEmployee($regionA, 'HO001', true);
        $actor = $this->createUserForEmployee($actorEmployee);
        $actor->roles()->attach($hrHeadOfficeRole);

        $this->createEmployee($regionA, 'EMP101', true);
        $this->createEmployee($regionB, 'EMP102', true);
        $this->createEmployee($regionB, 'EMP103', false);

        $this->actingAs($actor);

        Livewire::test(HrDashboard::class)
            ->assertSet('zoneStaffCount', 3);
    }

    public function test_hr_dashboard_counts_pending_requests_separately_from_on_leave_now(): void
    {
        $hrHeadOfficeRole = $this->createRole('hr_headoffice');
        $region = Region::query()->create(['region_name' => 'Greater Accra']);

        $actorEmployee = $this->createEmployee($region, 'HO201', true);
        $actor = $this->createUserForEmployee($actorEmployee);
        $actor->roles()->attach($hrHeadOfficeRole);

        $requester = $this->createEmployee($region, 'EMP201', true);

        $this->createLeaveRequest(
            requester: $requester,
            manager: $actorEmployee,
            status: 'Pending Approval',
            startDate: today()->toDateString(),
            endDate: today()->addDays(2)->toDateString(),
            managerRecommendation: 'Pending'
        );

        $this->createLeaveRequest(
            requester: $requester,
            manager: $actorEmployee,
            status: 'Approved',
            startDate: today()->subDays(5)->toDateString(),
            endDate: today()->subDay()->toDateString()
        );

        $this->actingAs($actor);

        Livewire::test(HrDashboard::class)
            ->assertSet('onLeaveNowCount', 0)
            ->assertSet('pendingCount', 1);
    }

    public function test_hr_dashboard_on_leave_now_counts_only_current_approved_leave(): void
    {
        $hrHeadOfficeRole = $this->createRole('hr_headoffice');
        $region = Region::query()->create(['region_name' => 'Greater Accra']);

        $actorEmployee = $this->createEmployee($region, 'HO301', true);
        $actor = $this->createUserForEmployee($actorEmployee);
        $actor->roles()->attach($hrHeadOfficeRole);

        $currentlyOnLeave = $this->createEmployee($region, 'EMP301', true);
        $backFromLeave = $this->createEmployee($region, 'EMP302', true);
        $futureLeave = $this->createEmployee($region, 'EMP303', true);

        $this->createLeaveRequest(
            requester: $currentlyOnLeave,
            manager: $actorEmployee,
            status: 'Approved',
            startDate: today()->subDay()->toDateString(),
            endDate: today()->addDay()->toDateString()
        );

        $this->createLeaveRequest(
            requester: $backFromLeave,
            manager: $actorEmployee,
            status: 'Approved',
            startDate: today()->subDays(5)->toDateString(),
            endDate: today()->subDay()->toDateString()
        );

        $this->createLeaveRequest(
            requester: $futureLeave,
            manager: $actorEmployee,
            status: 'Approved',
            startDate: today()->addDay()->toDateString(),
            endDate: today()->addDays(3)->toDateString()
        );

        $this->actingAs($actor);

        Livewire::test(HrDashboard::class)
            ->assertSet('onLeaveNowCount', 1)
            ->assertSet('pendingCount', 0);
    }

    protected function createRole(string $name): Role
    {
        return Role::query()->create([
            'name' => $name,
            'display_name' => str($name)->replace('_', ' ')->title()->toString(),
            'is_system' => true,
        ]);
    }

    protected function createUserForEmployee(Employee $employee): User
    {
        return User::query()->create([
            'staff_id' => $employee->staff_id,
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    protected function createLeaveRequest(
        Employee $requester,
        Employee $manager,
        string $status,
        string $startDate,
        string $endDate,
        string $managerRecommendation = 'Recommended'
    ): LeaveRequest {
        return LeaveRequest::query()->create([
            'requester_id' => $requester->id,
            'leave_type' => 'Annual',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days_applied' => 1,
            'leave_details' => null,
            'manager_id' => $manager->id,
            'manager_comments' => null,
            'manager_recommendation' => $managerRecommendation,
            'leave_status' => $status,
            'approved_by_id' => $status === 'Approved' ? $manager->id : null,
            'chiefManager_comments' => null,
            'request_year' => (int) substr($startDate, 0, 4),
            'department_id' => $requester->department_id,
            'region_id' => $requester->region_id,
            'file_attachment' => null,
        ]);
    }

    protected function createEmployee(Region $region, string $staffId, bool $isActive): Employee
    {
        return Employee::withoutEvents(function () use ($region, $staffId, $isActive) {
            $district = District::query()->create([
                'district_name' => $staffId.' District',
                'region_id' => $region->id,
            ]);
            $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
            $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

            return Employee::query()->create([
                'staff_id' => $staffId,
                'full_name' => 'Employee '.$staffId,
                'gender' => 'Male',
                'category' => 'Senior Staff',
                'email' => strtolower($staffId).'@example.com',
                'job_title_id' => $jobTitle->id,
                'department_id' => $department->id,
                'region_id' => $region->id,
                'district_id' => $district->id,
                'location_type' => 'District',
                'date_of_birth' => '1990-01-01',
                'date_joined' => '2026-05-04',
                'is_active' => $isActive,
            ]);
        });
    }
}
