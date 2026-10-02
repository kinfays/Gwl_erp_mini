<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\ApplyForm;
use App\Livewire\Leave\CompulsoryLeave;
use App\Models\AuditLog;
use App\Models\CompulsoryLeaveDeduction;
use App\Models\CompulsoryLeavePeriod;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Leave\AnnualEntitlementService;
use App\Services\Leave\CompulsoryLeaveService;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;
use Database\Seeders\LeaveApprovalRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Leave\Concerns\BuildsLeaveOrg;
use Tests\TestCase;

/**
 * Compulsory leave: a number of days (default 11) taken off the gross Annual entitlement of Head Office and regional
 * office staff, managed by Head Office HR and Global Admin. It is not a leave request and is never deducted twice.
 */
class CompulsoryLeaveTest extends TestCase
{
    use BuildsLeaveOrg;
    use RefreshDatabase;

    protected Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        config(['gwl.carry_over_expiry_days' => 90]);
        $this->travelTo(Carbon::parse('2026-06-01'));

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, LeaveApprovalRolePermissionSeeder::class]);
        $this->buildOrg();

        $this->hr = $this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']);
    }

    protected function save(int $days, int $year = 2026, array $extra = []): array
    {
        return app(CompulsoryLeaveService::class)->save($this->userOf($this->hr), $year, ['days' => $days] + $extra);
    }

    protected function figures(Employee $employee, int $year = 2026): array
    {
        return app(AnnualEntitlementService::class)->figuresFor($employee, $year);
    }

    // ------------------------------------------------------------------ the deduction

    public function test_senior_staff_at_head_office_have_36_less_11_compulsory_days_to_take(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');

        $this->assertSame(['gross' => 36, 'compulsory' => 11, 'net' => 25], $this->figures($senior));
        $this->assertSame(25, app(LeaveBalanceService::class)->getVirtualRemaining($senior, 'Annual', 2026));
        $this->assertSame(25, $senior->annual_leave_days);
    }

    public function test_regional_office_staff_are_deducted_too(): void
    {
        $regional = $this->staff('SNR002', $this->accraOffice, $this->finance, grade: 'Mgt. Gd. Level 1');

        $this->assertSame(['gross' => 36, 'compulsory' => 11, 'net' => 25], $this->figures($regional));
    }

    public function test_district_staff_are_not_deducted(): void
    {
        $district = $this->staff('SNR003', $this->temaDistrict, $this->finance, grade: 'Snr. Gd. Level 2');

        $this->assertSame(['gross' => 36, 'compulsory' => 0, 'net' => 36], $this->figures($district));
    }

    public function test_charwoman_is_not_deducted_and_has_no_entitlement(): void
    {
        $charwoman = $this->staff('CHW001', $this->headOffice, $this->finance, grade: 'Charwoman');

        $this->assertSame(['gross' => 0, 'compulsory' => 0, 'net' => 0], $this->figures($charwoman));
    }

    public function test_the_year_without_a_record_uses_the_configured_default(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        config(['gwl.leave_compulsory_default_days' => 8]);
        CompulsoryLeavePeriod::forgetYear(2026);

        $this->assertSame(8, $this->figures($senior)['compulsory']);
        $this->assertSame('default', app(CompulsoryLeaveService::class)->statusFor(2026)['source']);
    }

    public function test_saving_the_year_sets_the_days_the_dates_and_recalculates_the_entitlements(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        app(AnnualEntitlementService::class)->generate(2026);

        $result = $this->save(9, extra: ['start_date' => '2026-12-21', 'resume_date' => '2027-01-04', 'notes' => 'Year-end shutdown']);

        $period = CompulsoryLeavePeriod::query()->where('year', 2026)->sole();
        $this->assertSame(9, $period->days);
        $this->assertSame('2026-12-21', $period->start_date->toDateString());
        $this->assertSame('2027-01-04', $period->resume_date->toDateString());
        $this->assertSame($this->userOf($this->hr)->id, $period->updated_by);
        $this->assertSame(1, $result['recalculated']['updated']);

        $row = LeaveEntitlement::query()->where('employee_id', $senior->id)->where('year', 2026)->sole();
        $this->assertSame([36, 9, 27], [$row->gross_days, $row->compulsory_days, $row->net_days]);
    }

    public function test_reducing_the_days_recalculates_the_balance_but_never_below_what_was_used(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        $heavy = $this->staff('SNR002', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        $balances = app(LeaveBalanceService::class);

        // Net 25 now; $senior has taken 5, $heavy 26 (more than the 25 now available, e.g. approved before the shutdown was set).
        $balances->deduct($balances->getOrCreateForApproval($senior, 'Annual', 2026), 5);
        $heavyBalance = $balances->getOrCreateForApproval($heavy, 'Annual', 2026);
        $heavyBalance->update(['entitle_days' => 36, 'remaining_days' => 36]);
        $balances->deduct($heavyBalance->fresh(), 26);
        $this->save(11);

        $this->save(9);

        $this->assertSame(27, $this->balanceOf($senior)->entitle_days);
        $this->assertSame(22, $this->balanceOf($senior)->remaining_days);

        // 36 - 11 = 25 is below the 26 days already taken: the entitlement is held at 26, so nothing goes negative.
        $this->save(11);
        $this->assertSame(26, $this->balanceOf($heavy)->entitle_days);
        $this->assertSame(0, $this->balanceOf($heavy)->remaining_days);
        $this->assertSame(26, $this->balanceOf($heavy)->used_days);
    }

    public function test_approving_leave_does_not_deduct_the_compulsory_days_again(): void
    {
        $applicant = $this->staff('SNR001', $this->accraOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        $manager = $this->staff('DMR001', $this->accraOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('RCM001', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant, ['start_date' => '2026-10-05', 'end_date' => '2026-10-09']); // 5 working days
        $this->workflow()->recommend($manager, $request->fresh(), null, true);
        $this->workflow()->finalDecision($chief, $request->fresh(), null, true);

        $balance = $this->balanceOf($applicant);
        $this->assertSame(25, $balance->entitle_days); // 36 gross - 11 compulsory, once
        $this->assertSame(5, $balance->used_days);     // only the approved request: the compulsory days are not "used"
        $this->assertSame(20, $balance->remaining_days);
        $this->assertSame(20, app(LeaveBalanceService::class)->getVirtualRemaining($applicant, 'Annual', 2026));
    }

    public function test_the_casual_leave_rule_counts_the_net_annual_balance(): void
    {
        $applicant = $this->staff('SNR001', $this->accraOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        $this->staff('DMR001', $this->accraOffice, $this->finance, ['departmental_manager']);
        $this->staff('RCM001', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $balances = app(LeaveBalanceService::class);
        $balances->deduct($balances->getOrCreateForApproval($applicant, 'Annual', 2026), 24);

        // 25 net days less 24 taken leaves 1, so Casual leave is still refused...
        try {
            $this->workflow()->submit($applicant, ['leave_type' => 'Casual', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'leave_details' => 'Family']);
            $this->fail('Casual leave should be refused while Annual days remain.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Casual leave is not allowed while Annual leave balance is greater than 0.', $exception->getMessage());
        }

        // ...and with the last day used (not 36 - 25 = 11 days still showing) it is allowed.
        $balances->deduct($this->balanceOf($applicant), 1);

        $this->assertSame(0, $balances->getVirtualRemaining($applicant, 'Annual', 2026));
        $this->assertSame('Pending Approval', $this->workflow()->submit($applicant, ['leave_type' => 'Casual', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'leave_details' => 'Family'])->leave_status);
    }

    public function test_compulsory_days_never_use_carry_over(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        LeaveBalance::query()->create([
            'employee_id' => $senior->id, 'leave_type' => 'Annual', 'entitle_days' => 31, 'used_days' => 21, 'remaining_days' => 10,
            'carry_over_days' => 0, 'current_year' => 2025, 'region_id' => $senior->region_id, 'district_id' => $senior->district_id,
        ]);

        $this->travelTo(Carbon::parse('2026-02-09'));
        $balances = app(LeaveBalanceService::class);
        // 2 of the 10 carried days are used before the 1 April expiry (an approved request, as finalDecision() leaves it).
        LeaveRequest::query()->create([
            'requester_id' => $senior->id, 'leave_type' => 'Annual', 'start_date' => '2026-02-16', 'end_date' => '2026-02-17',
            'total_days_applied' => 2, 'manager_id' => $senior->id, 'manager_recommendation' => 'Recommended', 'leave_status' => 'Approved',
            'request_year' => 2026, 'department_id' => $senior->department_id, 'region_id' => $senior->region_id,
        ]);
        $balances->deduct($balances->getOrCreateForApproval($senior, 'Annual', 2026), 2);

        $this->travelTo(Carbon::parse('2026-06-01'));
        $this->artisan('leave:forfeit-expired-carry-over')->assertSuccessful();

        $balance = $this->balanceOf($senior);
        $this->assertSame(10, $balance->carry_over_days);
        $this->assertSame(8, $balance->carry_over_forfeited_days);
        // 25 net entitlement, not 36: 25 + 10 carried - 2 used - 8 forfeited = 25.
        $this->assertSame(25, $balance->remaining_days);
    }

    public function test_a_year_handled_by_the_earlier_deduction_is_never_deducted_again(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        CompulsoryLeaveDeduction::query()->create([
            'year' => 2026, 'start_date' => '2026-12-21', 'end_date' => '2027-01-01', 'deduction_days' => 10,
            'applies_to_categories' => ['Senior Staff'], 'applied_by_id' => $this->hr->id,
        ]);

        $this->assertSame(0, $this->figures($senior)['compulsory']);
        $this->assertSame('legacy', app(CompulsoryLeaveService::class)->statusFor(2026)['source']);

        $this->expectException(ValidationException::class);
        $this->save(11);
    }

    // ------------------------------------------------------------------ the dates

    public function test_staff_it_applies_to_cannot_book_leave_over_the_shutdown(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        $district = $this->staff('SNR002', $this->temaDistrict, $this->finance, grade: 'Snr. Gd. Level 2');
        $this->save(11, extra: ['start_date' => '2026-12-21', 'resume_date' => '2027-01-04']);

        Livewire::actingAs($this->userOf($senior))->test(ApplyForm::class)
            ->set('start_date', '2026-12-30')
            ->set('end_date', '2026-12-30')
            ->call('submit')
            ->assertHasErrors('start_date')
            ->assertSee('21 Dec 2026 - 03 Jan 2027');

        // District staff are outside compulsory leave, so the dates don't apply to them.
        $this->assertSame([], app(CompulsoryLeaveService::class)->windowsFor($district));
        $this->assertCount(1, app(CompulsoryLeaveService::class)->windowsFor($senior));
    }

    // ------------------------------------------------------------------ who can manage it

    public function test_head_office_hr_global_admin_and_super_admin_can_open_the_page(): void
    {
        $admin = $this->staff('ADM001', $this->headOffice, $this->finance, ['admin']);
        $superAdmin = $this->staff('SA001', $this->headOffice, $this->finance, ['super_admin']);

        foreach ([$this->hr, $admin, $superAdmin] as $allowed) {
            $this->actingAs($this->userOf($allowed))->get(route('leave.compulsory'))->assertOk()->assertSee('Compulsory Leave');
            Livewire::actingAs($this->userOf($allowed))->test(CompulsoryLeave::class)->assertOk();
        }
    }

    public function test_regional_hr_and_everyone_else_are_refused_even_with_the_permission(): void
    {
        $regionalHr = $this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']);
        $manager = $this->staff('DM001', $this->temaDistrict, $this->operations, ['district_manager']);
        $employee = $this->staff('EMP001', $this->temaDistrict, $this->operations);

        // Even a regional HR role granted the permission directly stays out.
        Role::query()->where('name', 'hr_region')->firstOrFail()->permissions()->syncWithoutDetaching(
            Permission::query()->where('name', 'leave.manage_compulsory')->firstOrFail()
        );

        foreach ([$regionalHr, $manager, $employee] as $denied) {
            $this->actingAs($this->userOf($denied))->get(route('leave.compulsory'))->assertForbidden();
            Livewire::actingAs($this->userOf($denied))->test(CompulsoryLeave::class)->assertForbidden();
            $this->assertFalse(app(CompulsoryLeaveService::class)->canManage($this->userOf($denied)));
        }
    }

    public function test_the_service_refuses_a_save_from_someone_who_may_not_manage_it(): void
    {
        $regionalHr = $this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']);

        $this->expectException(AuthorizationException::class);

        app(CompulsoryLeaveService::class)->save($this->userOf($regionalHr), 2026, ['days' => 5]);
    }

    public function test_the_page_warns_when_the_year_has_no_record_and_shows_the_default(): void
    {
        Livewire::actingAs($this->userOf($this->hr))->test(CompulsoryLeave::class)
            ->assertSee('No compulsory leave recorded for 2026')
            ->assertSee('default of 11 days')
            ->assertSet('days', '11');

        $this->save(11);

        Livewire::actingAs($this->userOf($this->hr))->test(CompulsoryLeave::class)
            ->assertDontSee('No compulsory leave recorded');
    }

    public function test_hr_saves_the_year_from_the_page_and_the_change_is_audited_with_old_and_new_values(): void
    {
        $senior = $this->staff('SNR001', $this->headOffice, $this->finance, grade: 'Snr. Gd. Level 2');
        app(AnnualEntitlementService::class)->generate(2026);

        Livewire::actingAs($this->userOf($this->hr))->test(CompulsoryLeave::class)
            ->set('days', '11')
            ->set('startDate', '2026-12-21')
            ->set('resumeDate', '2027-01-04')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($this->userOf($this->hr))->test(CompulsoryLeave::class)
            ->set('days', '9')
            ->call('save')
            ->assertHasNoErrors();

        $audits = AuditLog::query()->where('action', 'leave_compulsory_period_saved')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertNull($audits[0]->old_values);
        $this->assertSame(11, $audits[0]->new_values['days']);
        $this->assertSame(11, $audits[1]->old_values['days']);
        $this->assertSame(9, $audits[1]->new_values['days']);
        $this->assertSame('2026-12-21', $audits[1]->new_values['start_date']);
        $this->assertSame(2026, $audits[1]->metadata['year']);

        // The entitlement was recalculated for the new days, and that is audited per employee too.
        $this->assertSame(27, LeaveEntitlement::query()->where('year', 2026)->where('employee_id', $senior->id)->sole()->net_days);
        $this->assertTrue(AuditLog::query()->where('action', 'leave_entitlement_recalculated')->exists());
    }

    public function test_the_page_validates_the_days_and_the_dates(): void
    {
        Livewire::actingAs($this->userOf($this->hr))->test(CompulsoryLeave::class)
            ->set('days', '-1')
            ->call('save')
            ->assertHasErrors('days');

        Livewire::actingAs($this->userOf($this->hr))->test(CompulsoryLeave::class)
            ->set('days', '11')
            ->set('startDate', '2026-12-21')
            ->set('resumeDate', '2026-12-01')
            ->call('save')
            ->assertHasErrors('resumeDate');

        $this->assertSame(0, CompulsoryLeavePeriod::query()->count());
    }

    protected function balanceOf(Employee $employee, int $year = 2026): LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', 'Annual')
            ->where('current_year', $year)
            ->sole();
    }
}
