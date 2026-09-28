<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\HrDashboard;
use App\Livewire\Leave\ManagerDashboard;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The approval-turnaround figures come from the stage timestamps (submitted_at, recommended_at,
 * decided_at). A stage whose times are unknown is left out of its average rather than counted
 * as zero, and nothing measured shows "No data yet".
 */
class LeaveSlaStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_dashboard_times_each_approval_stage_and_finds_breaches(): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = $this->createDistrict($region, 'Accra Central');
        $hrEmployee = $this->createEmployee($district, 'HO401');
        $hr = $this->createUserForEmployee($hrEmployee);
        $hr->roles()->attach($this->createRole('hr_headoffice'));

        $requester = $this->createEmployee($district, 'EMP401');
        $approver = $this->createEmployee($district, 'CHF401');

        // Manager 20h, approver 64h, 84h in all: the one SLA breach.
        $slow = $this->stagedRequest($requester, '2026-09-01 08:00', recommendedAfter: 20, decidedAfter: 84, status: 'Approved', approver: $approver);
        // Manager 4h, approver 6h.
        $this->stagedRequest($requester, '2026-09-08 08:00', recommendedAfter: 4, decidedAfter: 10, status: 'Approved', approver: $approver);
        // Rejected by the manager after 30h: that is also the decision, and it never reached the approver.
        $this->stagedRequest($requester, '2026-09-15 08:00', recommendedAfter: 30, decidedAfter: 30, status: 'Denied', recommendation: 'Rejected');
        // A manager's own request is recommended on submission: no manager time, 12h with the approver.
        $this->stagedRequest($requester, '2026-09-20 08:00', recommendedAfter: 0, decidedAfter: 12, status: 'Approved', approver: $approver);
        // Recommended after 8h and still with the approver.
        $this->stagedRequest($requester, '2026-09-24 08:00', recommendedAfter: 8, status: 'Pending Approval');
        // Decided before these columns existed: only the whole cycle (50h) is known.
        $this->stagedRequest($requester, '2026-09-22 08:00', decidedAfter: 50, status: 'Approved', approver: $approver);
        // Planned, never submitted.
        $this->createLeaveRequest($requester, ['leave_status' => 'Planned']);

        $this->actingAs($hr);

        $component = Livewire::test(HrDashboard::class)
            // (20 + 4 + 30 + 8) / 4 = 15.5
            ->assertSet('slaStats.avg_manager_hours', 16)
            // (64 + 6 + 12) / 3 = 27.3
            ->assertSet('slaStats.avg_final_hours', 27)
            // (84 + 10 + 30 + 12 + 50) / 5 = 37.2
            ->assertSet('slaStats.avg_total_hours', 37)
            ->assertSee('84h');

        $breaches = $component->get('slowestApprovals');

        $this->assertCount(1, $breaches);
        $this->assertSame($slow->id, $breaches->first()->id);
    }

    public function test_hr_dashboard_says_no_data_instead_of_zero_hours(): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = $this->createDistrict($region, 'Accra Central');
        $hrEmployee = $this->createEmployee($district, 'HO402');
        $hr = $this->createUserForEmployee($hrEmployee);
        $hr->roles()->attach($this->createRole('hr_headoffice'));

        // Waiting on the manager: no stage has finished yet.
        $this->stagedRequest($this->createEmployee($district, 'EMP402'), '2026-09-01 08:00', status: 'Pending Approval');

        $this->actingAs($hr);

        Livewire::test(HrDashboard::class)
            ->assertSet('slaStats.avg_manager_hours', null)
            ->assertSet('slaStats.avg_final_hours', null)
            ->assertSet('slaStats.avg_total_hours', null)
            ->assertSee('No data yet · target ≤ 48h')
            ->assertSee('No data yet · target ≤ 24h')
            ->assertSee('No data yet · target ≤ 72h')
            ->assertDontSee('<b>0h</b>', false);
    }

    public function test_manager_dashboard_average_cycle_is_for_the_team_and_skips_unknown_cycles(): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $homeDistrict = $this->createDistrict($region, 'Accra Central');
        $otherDistrict = $this->createDistrict($region, 'Tema');

        $managerEmployee = $this->createEmployee($homeDistrict, 'MGR401');
        $manager = $this->createUserForEmployee($managerEmployee);
        $manager->roles()->attach($this->createRole('district_manager'));

        $teamMember = $this->createEmployee($homeDistrict, 'EMP411');
        $outsider = $this->createEmployee($otherDistrict, 'EMP412');

        $this->stagedRequest($teamMember, '2026-09-01 08:00', recommendedAfter: 2, decidedAfter: 30, status: 'Approved', approver: $managerEmployee);
        $this->stagedRequest($teamMember, '2026-09-08 08:00', recommendedAfter: 12, decidedAfter: 12, status: 'Denied', recommendation: 'Rejected');
        // No submission time on record: left out, not counted as zero.
        $this->createLeaveRequest($teamMember, ['leave_status' => 'Approved', 'approved_by_id' => $managerEmployee->id, 'decided_at' => '2026-09-10 08:00']);
        // Another district's request must not pull the team's average up.
        $this->stagedRequest($outsider, '2026-09-15 08:00', recommendedAfter: 100, decidedAfter: 200, status: 'Approved', approver: $managerEmployee);

        $this->actingAs($manager);

        Livewire::test(ManagerDashboard::class)
            ->assertSet('slaStats.avg_cycle_hours', 21)
            ->assertSee('<b>21h</b>', false);
    }

    /**
     * A request submitted at $submittedAt whose stages finished the given number of hours later
     * (null = that stage hasn't happened, or its time is unknown).
     */
    protected function stagedRequest(
        Employee $requester,
        string $submittedAt,
        ?int $recommendedAfter = null,
        ?int $decidedAfter = null,
        string $status = 'Approved',
        string $recommendation = 'Recommended',
        ?Employee $approver = null
    ): LeaveRequest {
        $submitted = Carbon::parse($submittedAt);

        return $this->createLeaveRequest($requester, [
            'leave_status' => $status,
            'manager_recommendation' => $recommendedAfter === null && $status === 'Pending Approval' ? 'Pending' : $recommendation,
            'approved_by_id' => $approver?->id,
            'submitted_at' => $submitted,
            'recommended_at' => $recommendedAfter === null ? null : $submitted->copy()->addHours($recommendedAfter),
            'decided_at' => $decidedAfter === null ? null : $submitted->copy()->addHours($decidedAfter),
        ]);
    }

    protected function createLeaveRequest(Employee $requester, array $attributes): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'requester_id' => $requester->id,
            'leave_type' => 'Annual',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-07',
            'total_days_applied' => 3,
            'leave_details' => null,
            'manager_id' => $requester->id,
            'manager_comments' => null,
            'manager_recommendation' => 'Pending',
            'approved_by_id' => null,
            'chiefManager_comments' => null,
            'request_year' => 2026,
            'department_id' => $requester->department_id,
            'region_id' => $requester->region_id,
            'file_attachment' => null,
            ...$attributes,
        ]);
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

    protected function createDistrict(Region $region, string $name): District
    {
        return District::query()->create([
            'district_name' => $name,
            'region_id' => $region->id,
        ]);
    }

    protected function createEmployee(District $district, string $staffId): Employee
    {
        return Employee::withoutEvents(function () use ($district, $staffId) {
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
                'region_id' => $district->region_id,
                'district_id' => $district->id,
                'location_type' => 'District',
                'date_of_birth' => '1990-01-01',
                'date_joined' => '2026-05-04',
                'is_active' => true,
            ]);
        });
    }
}
