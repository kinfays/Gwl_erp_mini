<?php

namespace Tests\Feature\Leave;

use App\Exports\Staff\EmployeesExport;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Carry-over into a year (default expiry 1 April with GWL_CARRY_OVER_EXPIRY_DAYS=90) is used first by
 * Annual leave dated before the expiry; whatever is still unused then is forfeited.
 */
class AnnualCarryOverForfeitureTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['gwl.carry_over_expiry_days' => 90]);

        $this->employee = $this->createEmployee();
    }

    public function test_balance_row_created_before_expiry_matches_the_apply_form_after_it(): void
    {
        $this->closeYear(2025, remaining: 10);

        $this->travelTo(Carbon::parse('2026-02-09'));
        $this->approveAnnualLeave('2026-02-16', '2026-02-18'); // 3 days, before the 1 April expiry

        $this->assertSame(38, $this->balance(2026)->remaining_days);
        $this->assertSame(38, $this->virtualAnnual(2026));

        $this->travelTo(Carbon::parse('2026-06-01'));

        // The apply form stops counting the 7 carry-over days that were never used...
        $this->assertSame(31, $this->virtualAnnual(2026));

        $this->artisan('leave:forfeit-expired-carry-over')
            ->expectsOutput('Forfeited 7 day(s) of expired carry-over on 1 annual balance(s).')
            ->assertSuccessful();

        // ...and the stored row read by the Staff screens, the export and the approval email now agrees.
        $balance = $this->balance(2026);

        $this->assertSame(31, $balance->remaining_days);
        $this->assertSame(7, $balance->carry_over_forfeited_days);
        $this->assertNotNull($balance->carry_over_forfeited_at);
        $this->assertSame(31, $this->virtualAnnual(2026));
        $this->assertSame(31, $this->exportedAnnualBalance());

        $audit = AuditLog::query()->where('action', 'leave_carry_over_forfeited')->sole();

        $this->assertSame($balance->id, $audit->target_id);
        $this->assertSame(['remaining_days' => 38, 'carry_over_forfeited_days' => 0], $audit->old_values);
        $this->assertSame(['remaining_days' => 31, 'carry_over_forfeited_days' => 7], $audit->new_values);
    }

    public function test_leave_taken_before_expiry_uses_carry_over_first(): void
    {
        $this->closeYear(2025, remaining: 10);

        $this->travelTo(Carbon::parse('2026-02-09'));
        $this->approveAnnualLeave('2026-02-16', '2026-02-27'); // 10 days
        $this->approveAnnualLeave('2026-03-09', '2026-03-10'); // 2 days

        $this->travelTo(Carbon::parse('2026-06-01'));
        $this->artisan('leave:forfeit-expired-carry-over')->assertSuccessful();

        // The 12 days used all 10 carry-over days plus 2 of the entitlement, so nothing is lost.
        $this->assertSame(0, $this->balance(2026)->carry_over_forfeited_days);
        $this->assertSame(29, $this->balance(2026)->remaining_days);
        $this->assertSame(29, $this->virtualAnnual(2026));
    }

    public function test_request_spanning_the_expiry_only_uses_carry_over_for_days_before_it(): void
    {
        $this->closeYear(2025, remaining: 10);

        $this->travelTo(Carbon::parse('2026-03-20'));
        $this->approveAnnualLeave('2026-03-30', '2026-04-03'); // Mon-Fri: 2 days before 1 April, 3 after

        $this->travelTo(Carbon::parse('2026-04-06'));
        $this->artisan('leave:forfeit-expired-carry-over')->assertSuccessful();

        // 2 carry-over days used in time, 8 forfeited: 31 + 10 - 5 - 8.
        $this->assertSame(8, $this->balance(2026)->carry_over_forfeited_days);
        $this->assertSame(28, $this->balance(2026)->remaining_days);
        $this->assertSame(28, $this->virtualAnnual(2026));
    }

    public function test_expired_carry_over_is_not_carried_into_the_following_year(): void
    {
        $this->closeYear(2024, remaining: 10);

        $this->travelTo(Carbon::parse('2025-02-07'));
        $this->approveAnnualLeave('2025-02-10', '2025-02-14'); // 5 days before the 1 April 2025 expiry

        $this->travelTo(Carbon::parse('2025-06-01'));
        $this->approveAnnualLeave('2025-06-02', '2025-06-20'); // 15 days after it

        // 5 of the 10 carry-over days were used in time and the other 5 lapsed: 31 + 10 - 20 - 5.
        $this->assertSame(5, $this->balance(2025)->carry_over_forfeited_days);
        $this->assertSame(16, $this->balance(2025)->remaining_days);

        $this->travelTo(Carbon::parse('2026-01-15'));

        // 16 days carry into 2026. Before this fix the lapsed 2024 days came back too (31 + 21).
        $this->assertSame(47, $this->virtualAnnual(2026));
    }

    public function test_carry_over_into_the_next_year_is_right_even_before_the_forfeiture_job_runs(): void
    {
        $this->closeYear(2024, remaining: 10);

        $this->travelTo(Carbon::parse('2025-02-07'));
        $this->approveAnnualLeave('2025-02-10', '2025-02-14'); // 5 days, all taken from carry-over

        $this->travelTo(Carbon::parse('2026-01-15'));

        // The 2025 row was never re-evaluated after its expiry, yet only its 31 unused days carry.
        $this->assertSame(36, $this->balance(2025)->remaining_days);
        $this->assertSame(62, $this->virtualAnnual(2026));
    }

    public function test_leave_approved_late_for_dates_before_expiry_gives_back_forfeited_carry_over(): void
    {
        $this->closeYear(2025, remaining: 10);

        $this->travelTo(Carbon::parse('2026-02-09'));
        $this->approveAnnualLeave('2026-02-16', '2026-02-16'); // 1 day

        $this->travelTo(Carbon::parse('2026-04-02'));
        $this->artisan('leave:forfeit-expired-carry-over')->assertSuccessful();

        $this->assertSame(9, $this->balance(2026)->carry_over_forfeited_days);
        $this->assertSame(31, $this->balance(2026)->remaining_days);

        // Leave taken in March but only approved in April still comes out of carry-over first.
        $this->approveAnnualLeave('2026-03-23', '2026-03-26'); // 4 days

        $this->assertSame(5, $this->balance(2026)->carry_over_forfeited_days);
        $this->assertSame(31, $this->balance(2026)->remaining_days);
        $this->assertSame(31, $this->virtualAnnual(2026));
        $this->assertSame(2, AuditLog::query()->where('action', 'leave_carry_over_forfeited')->count());
    }

    public function test_forfeiture_waits_for_the_expiry_date_and_only_applies_once(): void
    {
        $this->closeYear(2025, remaining: 10);

        $this->travelTo(Carbon::parse('2026-02-09'));
        $this->approveAnnualLeave('2026-02-16', '2026-02-16'); // 1 day

        $this->travelTo(Carbon::parse('2026-03-31 23:00'));
        $this->artisan('leave:forfeit-expired-carry-over')
            ->expectsOutput('Forfeited 0 day(s) of expired carry-over on 0 annual balance(s).')
            ->assertSuccessful();

        $this->assertNull($this->balance(2026)->carry_over_forfeited_at);
        $this->assertSame(40, $this->balance(2026)->remaining_days);

        $this->travelTo(Carbon::parse('2026-04-01 00:30'));
        $this->artisan('leave:forfeit-expired-carry-over', ['--dry-run' => true])
            ->expectsOutput('Would forfeit 9 day(s) of expired carry-over on 1 annual balance(s).')
            ->assertSuccessful();

        $this->assertSame(40, $this->balance(2026)->remaining_days);

        $this->artisan('leave:forfeit-expired-carry-over')
            ->expectsOutput('Forfeited 9 day(s) of expired carry-over on 1 annual balance(s).')
            ->assertSuccessful();
        $this->artisan('leave:forfeit-expired-carry-over')
            ->expectsOutput('Forfeited 0 day(s) of expired carry-over on 0 annual balance(s).')
            ->assertSuccessful();

        $this->assertSame(31, $this->balance(2026)->remaining_days);
        $this->assertSame(1, AuditLog::query()->where('action', 'leave_carry_over_forfeited')->count());
    }

    public function test_forfeiture_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('leave:forfeit-expired-carry-over')
            ->assertSuccessful();
    }

    /**
     * Mirrors LeaveWorkflowService::finalDecision(): the request is saved as Approved, then the
     * balance row is opened if needed and the days are deducted.
     */
    protected function approveAnnualLeave(string $start, string $end): LeaveRequest
    {
        $startDate = Carbon::parse($start);
        $days = app(WorkingDaysCalculator::class)->workingDays($startDate, Carbon::parse($end));

        $request = LeaveRequest::query()->create([
            'requester_id' => $this->employee->id,
            'leave_type' => 'Annual',
            'start_date' => $start,
            'end_date' => $end,
            'total_days_applied' => $days,
            'manager_id' => $this->employee->id,
            'manager_recommendation' => 'Recommended',
            'leave_status' => 'Approved',
            'request_year' => $startDate->year,
            'department_id' => $this->employee->department_id,
            'region_id' => $this->employee->region_id,
        ]);

        $balances = app(LeaveBalanceService::class);
        $balances->deduct($balances->getOrCreateForApproval($this->employee, 'Annual', $startDate->year), $days);

        return $request;
    }

    protected function closeYear(int $year, int $remaining): void
    {
        LeaveBalance::query()->create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'Annual',
            'entitle_days' => 31,
            'used_days' => 31 - $remaining,
            'remaining_days' => $remaining,
            'carry_over_days' => 0,
            'current_year' => $year,
            'region_id' => $this->employee->region_id,
            'district_id' => $this->employee->district_id,
        ]);
    }

    protected function balance(int $year): LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type', 'Annual')
            ->where('current_year', $year)
            ->sole();
    }

    protected function virtualAnnual(int $year): int
    {
        return app(LeaveBalanceService::class)->getVirtualRemaining($this->employee, 'Annual', $year);
    }

    protected function exportedAnnualBalance(): mixed
    {
        // Same eager load the Staff list and export get from EmployeeDirectory::queryFor().
        $employee = Employee::query()
            ->with(['leaveBalances' => fn ($query) => $query->where('leave_type', 'Annual')->where('current_year', now()->year)])
            ->findOrFail($this->employee->id);

        return (new EmployeesExport(collect([$employee])))->map($employee)[8];
    }

    protected function createEmployee(): Employee
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create([
            'district_name' => 'Accra Central District',
            'region_id' => $region->id,
        ]);

        return Employee::query()->create([
            'staff_id' => 'EMP001',
            'full_name' => 'Ama Mensah',
            'gender' => 'Female',
            'category' => 'Senior Staff',
            'email' => 'ama.mensah@example.com',
            'job_title_id' => JobTitle::query()->create(['job_title_name' => 'HR Officer'])->id,
            'department_id' => Department::query()->create(['department_name' => 'Administration'])->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'date_of_birth' => '1990-01-01',
        ]);
    }
}
