<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\ApplyForm;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Services\Leave\AnnualEntitlementService;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;
use Database\Seeders\LeaveApprovalRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Leave\Concerns\BuildsLeaveOrg;
use Tests\TestCase;

/**
 * Grade-based Annual entitlements: what is stored per employee and year, how it is generated and kept up to date, and
 * how it reaches the leave balance. The figures themselves are pinned by tests/Unit/LeaveEntitlementCalculatorTest.
 */
class AnnualEntitlementTest extends TestCase
{
    use BuildsLeaveOrg;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        config(['gwl.carry_over_expiry_days' => 90]);
        $this->travelTo(Carbon::parse('2026-06-01'));

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, LeaveApprovalRolePermissionSeeder::class]);
        $this->buildOrg();
    }

    protected function service(): AnnualEntitlementService
    {
        return app(AnnualEntitlementService::class);
    }

    protected function entitlement(Employee $employee, int $year = 2026): LeaveEntitlement
    {
        return LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $year)->sole();
    }

    public function test_generation_stores_each_active_eligible_employees_figures_for_the_year(): void
    {
        $junior = $this->staff('JNR001', $this->temaDistrict, $this->operations, grade: 'Junior Gd. Level 2', joined: '2016-02-01');
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 3', joined: '2010-03-01');
        $charwoman = $this->staff('CHW001', $this->headOffice, $this->finance, grade: 'Charwoman');
        $gone = $this->staff('OLD001', $this->temaDistrict, $this->operations, grade: 'Snr. Gd. Level 1', employeeActive: false);

        $counts = $this->service()->generate(2026);

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1], $counts);

        $row = $this->entitlement($junior);
        $this->assertSame('Junior Gd. Level 2', $row->grade_snapshot);
        $this->assertSame(9, $row->tenure_years);
        $this->assertSame([26, 0, 26], [$row->gross_days, $row->compulsory_days, $row->net_days]);

        // Senior at Head Office: 36 gross, the default 11 compulsory days (no record for the year yet), 25 to take.
        $row = $this->entitlement($senior);
        $this->assertSame([36, 11, 25], [$row->gross_days, $row->compulsory_days, $row->net_days]);

        $this->assertDatabaseMissing('leave_entitlements', ['employee_id' => $charwoman->id]);
        $this->assertDatabaseMissing('leave_entitlements', ['employee_id' => $gone->id]);
    }

    public function test_generation_is_idempotent_and_never_duplicates_a_row(): void
    {
        $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 3');

        $this->service()->generate(2026);
        $again = $this->service()->generate(2026);

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'skipped' => 0], $again);
        $this->assertSame(1, LeaveEntitlement::query()->count());
    }

    public function test_the_artisan_command_generates_a_year_and_is_safe_to_run_again(): void
    {
        $this->staff('SNR001', $this->temaDistrict, $this->finance, grade: 'Snr. Gd. Level 1');
        $this->staff('JNR001', $this->temaDistrict, $this->finance, grade: 'Junior Gd. Level 5');

        $this->artisan('leave:generate-entitlements', ['year' => 2027])
            ->expectsOutputToContain('2027: 2 created, 0 updated, 0 unchanged, 0 skipped')
            ->assertSuccessful();
        $this->artisan('leave:generate-entitlements', ['year' => 2027])
            ->expectsOutputToContain('2027: 0 created, 0 updated, 2 unchanged, 0 skipped')
            ->assertSuccessful();

        $this->assertSame(2, LeaveEntitlement::query()->where('year', 2027)->count());
        $this->artisan('leave:generate-entitlements', ['year' => 1999])->assertFailed();
    }

    public function test_the_year_a_balance_is_first_opened_is_generated_from_the_entitlement(): void
    {
        $junior = $this->staff('JNR001', $this->temaDistrict, $this->operations, grade: 'Junior Gd. Level 1', joined: '2016-02-01');

        $balance = app(LeaveBalanceService::class)->getOrCreateForApproval($junior, 'Annual', 2026);

        $this->assertSame(26, $balance->entitle_days);
        $this->assertSame(26, $balance->remaining_days);
        $this->assertSame(26, $this->entitlement($junior)->net_days);
    }

    public function test_the_apply_form_balance_follows_the_grade_without_any_stored_row(): void
    {
        $junior = $this->staff('JNR001', $this->temaDistrict, $this->operations, grade: 'Junior Gd. Level 6');
        $senior = $this->staff('SNR001', $this->temaDistrict, $this->operations, grade: 'Mgt. Gd. Level 2');

        $this->assertSame(31, app(LeaveBalanceService::class)->getVirtualRemaining($junior, 'Annual', 2026));
        $this->assertSame(36, app(LeaveBalanceService::class)->getVirtualRemaining($senior, 'Annual', 2026));
    }

    public function test_staff_with_no_grade_keep_the_flat_entitlement(): void
    {
        $legacy = $this->staff('OLD001', $this->headOffice, $this->finance);

        $this->assertNull($legacy->grade);
        $this->assertSame(31, app(LeaveBalanceService::class)->getVirtualRemaining($legacy, 'Annual', 2026));
        $this->assertSame(31, $legacy->annual_leave_days);
        // Even at Head Office, where a graded colleague would lose the compulsory days.
        $this->assertSame(['gross' => 31, 'compulsory' => 0, 'net' => 31], $this->service()->figuresFor($legacy, 2026));
    }

    public function test_changing_the_grade_recalculates_the_stored_entitlement_and_audits_it(): void
    {
        $employee = $this->staff('JNR001', $this->temaDistrict, $this->operations, grade: 'Junior Gd. Level 1', joined: '2016-02-01');
        $this->service()->generate(2026);
        $this->assertSame(26, $this->entitlement($employee)->net_days);

        Employee::query()->findOrFail($employee->id)->update(['grade' => 'Snr. Gd. Level 1']);

        $row = $this->entitlement($employee);
        $this->assertSame('Snr. Gd. Level 1', $row->grade_snapshot);
        $this->assertSame([36, 0, 36], [$row->gross_days, $row->compulsory_days, $row->net_days]);

        $audit = AuditLog::query()->where('action', 'leave_entitlement_recalculated')->latest('id')->firstOrFail();
        $this->assertSame(26, $audit->old_values['net_days']);
        $this->assertSame(36, $audit->new_values['net_days']);
        $this->assertSame(2026, $audit->metadata['year']);
        $this->assertStringContainsString('grade', $audit->metadata['reason']);

        $grade = AuditLog::query()->where('action', 'employee_grade_changed')->latest('id')->firstOrFail();
        $this->assertSame('Junior Gd. Level 1', $grade->old_values['grade']);
        $this->assertSame('Snr. Gd. Level 1', $grade->new_values['grade']);
        $this->assertSame('Senior Staff', $grade->new_values['category']);
    }

    public function test_changing_the_hire_date_recalculates_across_the_ten_year_line(): void
    {
        $employee = $this->staff('JNR001', $this->temaDistrict, $this->operations, grade: 'Junior Gd. Level 3', joined: '2016-02-01');
        $this->service()->generate(2026);
        $this->assertSame(26, $this->entitlement($employee)->net_days);

        Employee::query()->findOrFail($employee->id)->update(['date_joined' => '2015-12-31']);

        $this->assertSame(31, $this->entitlement($employee)->net_days);
        $this->assertSame(10, $this->entitlement($employee)->tenure_years);
    }

    public function test_a_recalculation_only_moves_the_remaining_days_when_leave_was_already_taken(): void
    {
        $employee = $this->staff('JNR001', $this->temaDistrict, $this->operations, grade: 'Junior Gd. Level 1', joined: '2016-02-01');
        $balances = app(LeaveBalanceService::class);
        $balances->deduct($balances->getOrCreateForApproval($employee, 'Annual', 2026), 10);

        $this->assertSame(16, $this->balance($employee)->remaining_days);

        Employee::query()->findOrFail($employee->id)->update(['grade' => 'Mgt. Gd. Level 1']);

        $balance = $this->balance($employee);
        $this->assertSame(36, $balance->entitle_days);
        $this->assertSame(10, $balance->used_days);
        $this->assertSame(26, $balance->remaining_days);
    }

    public function test_the_entitlement_never_drops_below_the_days_already_used(): void
    {
        $employee = $this->staff('SNR001', $this->temaDistrict, $this->finance, grade: 'Snr. Gd. Level 1');
        $balances = app(LeaveBalanceService::class);
        $balances->deduct($balances->getOrCreateForApproval($employee, 'Annual', 2026), 33);

        Employee::query()->findOrFail($employee->id)->update(['grade' => 'Junior Gd. Level 5']);

        $row = $this->entitlement($employee);
        $balance = $this->balance($employee);

        $this->assertSame(31, $row->net_days);
        $this->assertSame(33, $balance->entitle_days);
        $this->assertSame(33, $balance->used_days);
        $this->assertSame(0, $balance->remaining_days);

        $audit = AuditLog::query()->where('action', 'leave_entitlement_recalculated')->latest('id')->firstOrFail();
        $this->assertTrue($audit->metadata['balance']['floored']);
    }

    public function test_contract_staff_cannot_plan_or_submit_leave(): void
    {
        $charwoman = $this->staff('CHW001', $this->temaDistrict, $this->operations, grade: 'Charwoman');

        foreach (['submit', 'savePlanned'] as $method) {
            try {
                $this->workflow()->{$method}($charwoman, $this->leaveData());
                $this->fail("{$method} should be refused for contract staff.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Contract staff are not eligible for leave', $exception->getMessage());
            }
        }

        $this->assertSame(0, LeaveRequest::query()->where('requester_id', $charwoman->id)->count());
    }

    public function test_the_apply_form_tells_contract_staff_why_they_cannot_apply(): void
    {
        $charwoman = $this->staff('CHW001', $this->temaDistrict, $this->operations, grade: 'Charwoman');

        Livewire::actingAs($this->userOf($charwoman))->test(ApplyForm::class)
            ->set('start_date', '2026-10-05')
            ->set('end_date', '2026-10-07')
            ->call('submit')
            ->assertHasErrors('leave_type');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    protected function balance(Employee $employee, int $year = 2026): LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', 'Annual')
            ->where('current_year', $year)
            ->sole();
    }
}
