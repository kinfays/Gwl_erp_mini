<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Aggregates behind the HR leave dashboard's charts, scoped like the rest of that dashboard.
 */
class LeaveDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_regional_hr_only_counts_its_own_region_and_head_office_counts_all(): void
    {
        $accra = Region::query()->create(['region_name' => 'Greater Accra']);
        $ashanti = Region::query()->create(['region_name' => 'Ashanti']);

        [$regionalHr, $regionalHrUser] = $this->person($accra, 'HR100', 'hr_region');
        [$headOfficeHr, $headOfficeHrUser] = $this->person($accra, 'HO100', 'hr_headoffice');
        [$accraStaff] = $this->person($accra, 'EMP100');
        [$ashantiStaff] = $this->person($ashanti, 'EMP101');

        $year = (int) now()->format('Y');
        $this->leave($accraStaff, 'Approved', "{$year}-01-10", 3);
        $this->leave($ashantiStaff, 'Approved', "{$year}-01-12", 5);
        $this->leave($accraStaff, 'Pending Approval', "{$year}-01-20", 2);

        $service = app(LeaveDashboardService::class);

        $regional = $service->approvedDaysByMonth($regionalHrUser, $regionalHr, $year);
        $this->assertSame('Jan', $regional['labels'][0]);
        $this->assertSame(3, $regional['data'][0]);
        $this->assertCount((int) now()->format('n'), $regional['data']);

        $all = $service->approvedDaysByMonth($headOfficeHrUser, $headOfficeHr, $year);
        $this->assertSame(8, $all['data'][0]);

        $byStatus = $service->requestsByStatus($regionalHrUser, $regionalHr, $year);
        $this->assertSame(LeaveDashboardService::STATUSES, $byStatus['labels']);
        $this->assertSame([1, 1, 0, 0], $byStatus['data']);
    }

    public function test_upcoming_absences_are_approved_leave_starting_in_the_next_two_weeks(): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        [$hr, $hrUser] = $this->person($region, 'HO200', 'hr_headoffice');
        [$staff] = $this->person($region, 'EMP200');

        $soon = $this->leave($staff, 'Approved', today()->addDays(3)->toDateString(), 2);
        $this->leave($staff, 'Approved', today()->toDateString(), 2);
        $this->leave($staff, 'Approved', today()->addDays(30)->toDateString(), 2);
        $this->leave($staff, 'Pending Approval', today()->addDays(5)->toDateString(), 2);

        $upcoming = app(LeaveDashboardService::class)->upcomingAbsences($hrUser, $hr);

        $this->assertSame([$soon->id], $upcoming->pluck('id')->all());
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    protected function person(Region $region, string $staffId, ?string $role = null): array
    {
        $employee = Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Person '.$staffId,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Administration'])->id,
            'region_id' => $region->id,
            'district_id' => District::query()->create(['district_name' => $staffId.' District', 'region_id' => $region->id])->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-06',
            'is_active' => true,
        ]));

        $user = User::query()->create([
            'staff_id' => $staffId,
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        if ($role) {
            $user->roles()->attach(Role::query()->firstOrCreate(
                ['name' => $role],
                ['display_name' => str($role)->headline()->toString(), 'is_system' => true],
            ));
        }

        return [$employee, $user];
    }

    protected function leave(Employee $requester, string $status, string $startDate, int $days): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'requester_id' => $requester->id,
            'leave_type' => 'Annual',
            'start_date' => $startDate,
            'end_date' => \Illuminate\Support\Carbon::parse($startDate)->addDays($days - 1)->toDateString(),
            'total_days_applied' => $days,
            'manager_id' => $requester->id,
            'manager_recommendation' => $status === 'Approved' ? 'Recommended' : 'Pending',
            'leave_status' => $status,
            'request_year' => (int) substr($startDate, 0, 4),
            'department_id' => $requester->department_id,
            'region_id' => $requester->region_id,
        ]);
    }
}
