<?php

namespace Tests\Feature;

use App\Livewire\Leave\Approvals;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Visitor;
use App\Services\HomeSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The landing page summary shows the same numbers as the screens it links to.
 */
class HomeSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Region $region;

    protected District $district;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra East', 'region_id' => $this->region->id]);
    }

    public function test_approvals_count_matches_the_approvals_queue_for_managers_and_chiefs(): void
    {
        [$manager, $managerUser] = $this->person('MGR001', 'district_manager');
        [$chief, $chiefUser] = $this->person('CHF001', 'regional_chief_manager');
        [$requesterA] = $this->person('EMP001');
        [$requesterB] = $this->person('EMP002');

        // Waiting on the district manager's recommendation.
        $this->leaveRequest($requesterA, $manager, 'Pending Approval', 'Pending');
        // Recommended, so waiting on the regional chief.
        $this->leaveRequest($requesterB, $manager, 'Pending Approval', 'Recommended');
        // Already decided — in nobody's queue.
        $this->leaveRequest($requesterA, $manager, 'Approved', 'Recommended');

        $service = app(HomeSummaryService::class);

        $this->assertSame(['count' => 1, 'actionable' => true], $service->approvals($managerUser, $manager));
        $this->assertSame(['count' => 1, 'actionable' => true], $service->approvals($chiefUser, $chief));

        foreach ([$managerUser, $chiefUser] as $user) {
            $this->actingAs($user);
            $queue = Livewire::test(Approvals::class)->viewData('requests');
            $this->assertSame(1, $queue->total(), 'approvals queue size for '.$user->staff_id);
        }
    }

    public function test_hr_sees_a_read_only_pending_count_and_other_staff_see_none(): void
    {
        [$manager] = $this->person('MGR010', 'district_manager');
        [$requester, $requesterUser] = $this->person('EMP010');
        [$hr, $hrUser] = $this->person('HR010', 'hr_headoffice');

        $this->leaveRequest($requester, $manager, 'Pending Approval', 'Pending');
        $this->leaveRequest($requester, $manager, 'Pending Approval', 'Recommended');

        $service = app(HomeSummaryService::class);

        $this->assertSame(['count' => 2, 'actionable' => false], $service->approvals($hrUser, $hr));
        $this->assertNull($service->approvals($requesterUser, $requester));
    }

    public function test_annual_leave_and_visitor_figures(): void
    {
        [$employee] = $this->person('EMP020');
        [$manager] = $this->person('MGR020', 'district_manager');
        $this->leaveRequest($employee, $manager, 'Approved', 'Recommended', days: 3);

        $leave = app(HomeSummaryService::class)->annualLeave($employee);
        $this->assertSame(3, $leave['used']);
        $this->assertSame((int) now()->format('Y'), $leave['year']);
        $this->assertGreaterThanOrEqual($leave['remaining'] + $leave['used'], $leave['total']);

        $this->visitor($employee, checkedOut: false);
        $this->visitor($employee, checkedOut: true);
        $this->visitor($employee, checkedOut: false, checkIn: now()->subDay());

        $this->assertSame(['today' => 2, 'on_site' => 1], app(HomeSummaryService::class)->visitorsToday());
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    protected function person(string $staffId, ?string $role = null): array
    {
        $employee = Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Person '.$staffId,
            'gender' => 'Female',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Administration'])->id,
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-06',
            'annual_leave_days' => 30,
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

    protected function leaveRequest(Employee $requester, Employee $manager, string $status, string $recommendation, int $days = 1): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'requester_id' => $requester->id,
            'leave_type' => 'Annual',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays($days - 1)->toDateString(),
            'total_days_applied' => $days,
            'manager_id' => $manager->id,
            'manager_recommendation' => $recommendation,
            'leave_status' => $status,
            'approved_by_id' => $status === 'Approved' ? $manager->id : null,
            'request_year' => (int) now()->format('Y'),
            'department_id' => $requester->department_id,
            'region_id' => $requester->region_id,
        ]);
    }

    protected function visitor(Employee $host, bool $checkedOut, $checkIn = null): Visitor
    {
        $checkIn ??= now()->startOfDay()->addHours(9);

        return Visitor::query()->create([
            'visitor_name' => 'Visitor '.uniqid(),
            'phone' => '0240000000',
            'staff_id' => $host->id,
            'signature' => 'data:image/png;base64,in',
            'purpose' => 'Meeting',
            'checkout_code' => (string) random_int(100, 999),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkedOut ? $checkIn->copy()->addHour() : null,
            'checked_out_by' => $checkedOut ? 'self' : null,
        ]);
    }
}
