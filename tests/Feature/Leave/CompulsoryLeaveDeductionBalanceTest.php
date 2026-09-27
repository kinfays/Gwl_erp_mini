<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\CompulsoryDeductions;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveWorkflowService;
use App\Services\Leave\WorkingDaysCalculator;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Compulsory deductions charge Annual days straight to leave_balances without a leave request, so the
 * balance the apply form and leave dashboard show (getVirtualRemaining) must count them too.
 */
class CompulsoryLeaveDeductionBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected Region $region;

    protected District $district;

    protected Employee $staff;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['gwl.carry_over_expiry_days' => 90]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class]);

        $this->region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra Central District', 'region_id' => $this->region->id]);
        Department::query()->create(['department_name' => 'Operations']);
        JobTitle::query()->create(['job_title_name' => 'Officer']);

        $this->staff = $this->createEmployee('EMP001', 'Ama Mensah', 'Senior Staff');
        $this->hr = $this->createEmployee('HR001', 'Kofi Boateng', 'Management', 'hr_headoffice')->user;

        Role::query()->where('name', 'hr_headoffice')->firstOrFail()->permissions()->attach(
            Permission::query()->where('name', 'leave.manage_compulsory')->firstOrFail()
        );
    }

    public function test_compulsory_deduction_lowers_the_apply_form_balance_like_the_stored_row(): void
    {
        $this->closeYear(2025, remaining: 0);

        $this->travelTo(Carbon::parse('2026-06-01'));
        $this->approveAnnualLeave('2026-06-08', '2026-06-19'); // 10 days

        $this->assertSame(21, $this->balance()->remaining_days);
        $this->assertSame(21, $this->virtualAnnual());

        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->applyCompulsoryDeduction('2026-12-21', '2027-01-01'); // 10 working days

        $this->assertSame(20, $this->balance()->used_days);
        $this->assertSame(11, $this->balance()->remaining_days);
        $this->assertSame(11, $this->virtualAnnual());
    }

    public function test_compulsory_deduction_counts_for_someone_without_approved_leave_that_year(): void
    {
        $this->closeYear(2025, remaining: 0);

        // No 2026 leave yet, so the deduction itself opens the 2026 balance row.
        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->applyCompulsoryDeduction('2026-12-21', '2027-01-01');

        $this->assertSame(21, $this->balance()->remaining_days);
        $this->assertSame(21, $this->virtualAnnual());
    }

    public function test_casual_leave_rule_counts_compulsory_deduction_days(): void
    {
        $this->closeYear(2025, remaining: 0);
        $this->createApprovalChain();
        $workflow = app(LeaveWorkflowService::class);

        $this->travelTo(Carbon::parse('2026-06-01'));
        $this->approveAnnualLeave('2026-06-01', '2026-06-29'); // 21 days

        // 10 Annual days are still left, so Casual leave is refused.
        $this->travelTo(Carbon::parse('2026-11-02'));

        try {
            $workflow->submit($this->staff, $this->casualLeave('2026-11-09'));
            $this->fail('Casual leave should be refused while Annual days remain.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Casual leave is not allowed while Annual leave balance is greater than 0.', $exception->getMessage());
        }

        // The compulsory shutdown uses up the last 10 Annual days, so Casual leave is now allowed.
        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->applyCompulsoryDeduction('2026-12-21', '2027-01-01');

        $this->assertSame(0, $this->virtualAnnual());

        $request = $workflow->submit($this->staff, $this->casualLeave('2026-12-07'));

        $this->assertSame('Casual', $request->leave_type);
        $this->assertSame('Pending Approval', $request->leave_status);
    }

    public function test_compulsory_days_never_use_carry_over(): void
    {
        $this->closeYear(2025, remaining: 10);

        $this->travelTo(Carbon::parse('2026-02-09'));
        $this->approveAnnualLeave('2026-02-16', '2026-02-17'); // 2 days of carry-over used before 1 April

        $this->travelTo(Carbon::parse('2026-06-01'));
        $this->artisan('leave:forfeit-expired-carry-over')->assertSuccessful();

        $this->assertSame(8, $this->balance()->carry_over_forfeited_days);
        $this->assertSame(31, $this->balance()->remaining_days);

        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->applyCompulsoryDeduction('2026-12-21', '2027-01-01');

        // The December shutdown comes out of the entitlement: forfeiture is unchanged, 31 - 10 remain.
        $this->assertSame(8, $this->balance()->carry_over_forfeited_days);
        $this->assertSame(21, $this->balance()->remaining_days);
        $this->assertSame(21, $this->virtualAnnual());
        $this->assertSame(1, AuditLog::query()->where('action', 'leave_carry_over_forfeited')->count());
    }

    protected function applyCompulsoryDeduction(string $start, string $end): void
    {
        $this->actingAs($this->hr);

        // '' is what the screen's "None" option sends, so district staff are included.
        Livewire::test(CompulsoryDeductions::class)
            ->set('excludeLocationType', '')
            ->set('categories', ['Senior Staff'])
            ->set('startDate', $start)
            ->set('endDate', $end)
            ->call('apply')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('compulsory_leave_deductions', [
            'year' => Carbon::parse($start)->year,
            'deduction_days' => 10,
            'excludes_location_type' => null,
        ]);
    }

    /**
     * Mirrors LeaveWorkflowService::finalDecision(): the request is saved as Approved, then the
     * balance row is opened if needed and the days are deducted.
     */
    protected function approveAnnualLeave(string $start, string $end): void
    {
        $startDate = Carbon::parse($start);
        $days = app(WorkingDaysCalculator::class)->workingDays($startDate, Carbon::parse($end));

        LeaveRequest::query()->create([
            'requester_id' => $this->staff->id,
            'leave_type' => 'Annual',
            'start_date' => $start,
            'end_date' => $end,
            'total_days_applied' => $days,
            'manager_id' => $this->staff->id,
            'manager_recommendation' => 'Recommended',
            'leave_status' => 'Approved',
            'request_year' => $startDate->year,
            'department_id' => $this->staff->department_id,
            'region_id' => $this->staff->region_id,
        ]);

        $balances = app(LeaveBalanceService::class);
        $balances->deduct($balances->getOrCreateForApproval($this->staff, 'Annual', $startDate->year), $days);
    }

    protected function casualLeave(string $date): array
    {
        return ['leave_type' => 'Casual', 'start_date' => $date, 'end_date' => $date, 'leave_details' => 'Family matter'];
    }

    protected function createApprovalChain(): void
    {
        // A district employee is recommended by the district manager and approved by the regional chief.
        $this->createEmployee('DM001', 'Yaw Darko', 'Management', 'district_manager');
        $this->createEmployee('RC001', 'Efua Sarpong', 'Management', 'regional_chief_manager');
    }

    protected function closeYear(int $year, int $remaining): void
    {
        LeaveBalance::query()->create([
            'employee_id' => $this->staff->id,
            'leave_type' => 'Annual',
            'entitle_days' => 31,
            'used_days' => 31 - $remaining,
            'remaining_days' => $remaining,
            'carry_over_days' => 0,
            'current_year' => $year,
            'region_id' => $this->staff->region_id,
            'district_id' => $this->staff->district_id,
        ]);
    }

    protected function balance(int $year = 2026): LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $this->staff->id)
            ->where('leave_type', 'Annual')
            ->where('current_year', $year)
            ->sole();
    }

    protected function virtualAnnual(int $year = 2026): int
    {
        return app(LeaveBalanceService::class)->getVirtualRemaining($this->staff, 'Annual', $year);
    }

    protected function createEmployee(string $staffId, string $name, string $category, ?string $role = null): Employee
    {
        $employee = Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => 'Female',
            'category' => $category,
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->value('id'),
            'department_id' => Department::query()->value('id'),
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
            'date_of_birth' => '1990-01-01',
        ]);

        // EmployeeObserver creates the linked login account.
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        $user->update(['must_change_password' => false]);

        if ($role) {
            $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        }

        return $employee->load('user');
    }
}
