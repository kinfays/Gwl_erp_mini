<?php

namespace Tests\Feature\Hr;

use App\Livewire\Leave\HrAnalytics;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Hr\HrAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\LeaveApprovalRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Leave\Concerns\BuildsLeaveOrg;
use Tests\TestCase;

/**
 * HR analytics: who sees what, and the arithmetic behind each card (milestones, headcount, turnover, exits, distribution,
 * grades). Every figure is read as at a fixed date, so month and year boundaries, leap days and the retirement window
 * are pinned exactly.
 */
class HrAnalyticsTest extends TestCase
{
    use BuildsLeaveOrg;
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        config([
            'gwl.hr_analytics_cache_seconds' => 0,
            'gwl.retirement_age' => 60,
            'gwl.retirement_window_months' => 12,
            'gwl.leave_compulsory_default_days' => 11,
        ]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, LeaveApprovalRolePermissionSeeder::class]);
        $this->buildOrg();

        // The super_admin account exists in the database but is never counted, so most tests read the figures as this user.
        $this->superAdmin = $this->userOf($this->staff('SA001', $this->headOffice, $this->finance, ['super_admin']));
    }

    protected function asOf(string $date): Carbon
    {
        return Carbon::parse($date);
    }

    /** @param  array<string, mixed>  $attributes  columns set directly (dates, reasons), bypassing the model events */
    protected function person(string $id, ?District $district = null, array $attributes = [], array $roles = [], ?string $grade = null, ?Department $department = null, ?string $joined = null): Employee
    {
        $employee = $this->staff($id, $district ?? $this->temaDistrict, $department ?? $this->finance, $roles, grade: $grade, joined: $joined);

        if ($attributes !== []) {
            Employee::withoutEvents(fn () => $employee->forceFill($attributes)->save());
        }

        return $employee->refresh();
    }

    protected function analytics(string $asOf, array $filters = [], ?User $viewer = null): array
    {
        return app(HrAnalyticsService::class)->analytics($viewer ?? $this->superAdmin, $filters, $this->asOf($asOf));
    }

    protected function names(array $listing): array
    {
        return array_column($listing['items'], 'staff_id');
    }

    // ================================================================== scope

    public function test_super_admin_global_admin_and_head_office_hr_see_every_region(): void
    {
        $this->person('A1', $this->temaDistrict);
        $this->person('A2', $this->accraOffice);
        $this->person('K1', $this->kumasiDistrict);
        $this->person('K2', $this->kumasiOffice);

        $admin = $this->userOf($this->person('ADM001', $this->headOffice, roles: ['admin']));
        $headOfficeHr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));

        // Four staff, the admin and the Head Office HR user themselves; the super_admin is never counted.
        foreach ([$this->superAdmin, $admin, $headOfficeHr] as $viewer) {
            $this->assertSame(6, $this->analytics('2026-06-30', viewer: $viewer)['headcount']['total_active']);
        }
    }

    public function test_regional_hr_see_only_their_own_region_and_cannot_widen_it(): void
    {
        $this->person('A1', $this->temaDistrict);
        $this->person('A2', $this->accraOffice);
        $this->person('K1', $this->kumasiDistrict);
        $this->person('K2', $this->kumasiOffice);
        $accraHr = $this->userOf($this->person('HRA001', $this->accraOffice, roles: ['hr_region']));
        $kumasiHr = $this->userOf($this->person('HRK001', $this->kumasiOffice, roles: ['hr_region']));

        // Greater Accra: A1, A2 and the HR user (Head Office's super_admin is hidden). Ashanti: K1, K2 and their HR user.
        $this->assertSame(3, $this->analytics('2026-06-30', viewer: $accraHr)['headcount']['total_active']);
        $this->assertSame(3, $this->analytics('2026-06-30', viewer: $kumasiHr)['headcount']['total_active']);

        // Asking for the other region's figures changes nothing: the region filter is only for people who see them all.
        $this->assertSame(3, $this->analytics('2026-06-30', ['region_id' => $this->ashanti->id], $accraHr)['headcount']['total_active']);
        $this->assertSame(['all' => false, 'region_id' => $this->accra->id], app(HrAnalyticsService::class)->scopeFor($accraHr));
    }

    public function test_the_region_and_department_filters_narrow_what_the_wide_roles_see(): void
    {
        $this->person('A1', $this->temaDistrict, department: $this->finance);
        $this->person('A2', $this->temaDistrict, department: $this->operations);
        $this->person('K1', $this->kumasiDistrict, department: $this->operations);

        $this->assertSame(3, $this->analytics('2026-06-30')['headcount']['total_active']);
        $this->assertSame(2, $this->analytics('2026-06-30', ['region_id' => $this->accra->id])['headcount']['total_active']);
        $this->assertSame(2, $this->analytics('2026-06-30', ['department_id' => $this->operations->id])['headcount']['total_active']);
        $this->assertSame(1, $this->analytics('2026-06-30', ['region_id' => $this->ashanti->id, 'department_id' => $this->operations->id])['headcount']['total_active']);
    }

    public function test_everyone_else_is_refused(): void
    {
        $this->person('A1');

        foreach (['district_manager', 'manager', 'chief_manager', 'departmental_manager', 'ict_team'] as $role) {
            $viewer = $this->userOf($this->person('R'.$role, $this->temaDistrict, roles: [$role]));

            try {
                app(HrAnalyticsService::class)->analytics($viewer);
                $this->fail("{$role} must not see HR analytics.");
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode(), $role);
            }
        }
    }

    public function test_regional_hr_with_no_region_on_their_record_are_refused(): void
    {
        $user = User::query()->create([
            'staff_id' => 'HRX001', 'full_name' => 'No Region', 'email' => 'hrx001@example.com',
            'password' => Hash::make('abc12'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('name', 'hr_region')->firstOrFail());

        $this->expectException(HttpException::class);

        app(HrAnalyticsService::class)->analytics($user);
    }

    public function test_super_admin_accounts_are_never_counted_by_anyone(): void
    {
        $this->person('A1');
        $this->person('SA002', $this->temaDistrict, roles: ['super_admin']);
        $admin = $this->userOf($this->person('ADM001', $this->headOffice, roles: ['admin']));

        // SA001 and SA002 exist; only A1 and the admin are counted, whoever is looking (a super_admin included).
        $this->assertSame(2, $this->analytics('2026-06-30')['headcount']['total_active']);
        $this->assertSame(2, $this->analytics('2026-06-30', viewer: $admin)['headcount']['total_active']);

    }

    // ================================================================== (a) milestones

    public function test_birthdays_this_month_with_a_leap_day_falling_on_the_28th_in_a_common_year(): void
    {
        $this->person('B1', attributes: ['date_of_birth' => '1990-02-01']);
        $this->person('B2', attributes: ['date_of_birth' => '1992-02-29']);
        $this->person('B3', attributes: ['date_of_birth' => '1990-03-01']);
        $this->person('B4', attributes: ['date_of_birth' => '1990-01-31']);
        $this->person('B5', attributes: ['date_of_birth' => '1985-02-28', 'is_active' => false]);

        $birthdays = $this->analytics('2026-02-10')['milestones']['birthdays'];

        $this->assertSame(2, $birthdays['count']);
        $this->assertSame(['B1', 'B2'], $this->names($birthdays));
        $this->assertSame(['2026-02-01', '2026-02-28'], array_column($birthdays['items'], 'date'));
        $this->assertSame([36, 34], array_column($birthdays['items'], 'turning'));

        // In a leap year the day is the 29th.
        $leap = $this->analytics('2028-02-10')['milestones']['birthdays'];
        $this->assertSame('2028-02-29', collect($leap['items'])->firstWhere('staff_id', 'B2')['date']);
    }

    public function test_birthdays_respect_the_first_and_last_day_of_the_month(): void
    {
        $this->person('B1', attributes: ['date_of_birth' => '1990-01-01']);
        $this->person('B2', attributes: ['date_of_birth' => '1990-01-31']);
        $this->person('B3', attributes: ['date_of_birth' => '1990-02-01']);
        $this->person('B4', attributes: ['date_of_birth' => '1990-12-31']);

        // Read on the last day of January: the whole of January, nothing of February or December.
        $this->assertSame(['B1', 'B2'], $this->names($this->analytics('2026-01-31')['milestones']['birthdays']));
        // And on 1 February: only February.
        $this->assertSame(['B3'], $this->names($this->analytics('2026-02-01')['milestones']['birthdays']));
        $this->assertSame(['B4'], $this->names($this->analytics('2026-12-31')['milestones']['birthdays']));
    }

    public function test_work_anniversaries_this_month_leave_out_people_who_joined_this_year(): void
    {
        $this->person('W1', joined: '2025-02-10');   // 1 year
        $this->person('W2', joined: '2020-02-29');   // 6 years, a leap-day hire: the 28th in 2026
        $this->person('W3', joined: '2026-02-03');   // joined this year: not an anniversary
        $this->person('W4', joined: '2019-03-01');   // another month
        $this->person('W5', joined: '2018-02-14', attributes: ['is_active' => false]);

        $anniversaries = $this->analytics('2026-02-20')['milestones']['anniversaries'];

        $this->assertSame(['W1', 'W2'], $this->names($anniversaries));
        $this->assertSame([1, 6], array_column($anniversaries['items'], 'years'));
        $this->assertSame(['2026-02-10', '2026-02-28'], array_column($anniversaries['items'], 'date'));
    }

    public function test_anniversaries_at_the_turn_of_the_year(): void
    {
        $this->person('Y1', joined: '2024-12-31');
        $this->person('Y2', joined: '2025-12-31');
        $this->person('Y3', joined: '2025-01-01');
        $this->person('Y4', joined: '2026-12-01');

        $december = $this->analytics('2026-12-31')['milestones']['anniversaries'];

        $this->assertSame(['Y1', 'Y2'], $this->names($december));
        $this->assertSame([2, 1], array_column($december['items'], 'years'));
        $this->assertSame(['Y3'], $this->names($this->analytics('2026-01-01')['milestones']['anniversaries']));
    }

    public function test_staff_completing_5_10_15_or_20_years_this_year_whatever_the_month(): void
    {
        $this->person('S5A', joined: '2021-12-31');
        $this->person('S5B', joined: '2021-01-01');
        $this->person('S10', joined: '2016-03-01');
        $this->person('S15', joined: '2011-06-15');
        $this->person('S20', joined: '2006-01-01');
        $this->person('S6', joined: '2020-05-05');    // 6 years: not a milestone
        $this->person('S1', joined: '2025-01-01');
        $this->person('S10X', joined: '2016-03-01', attributes: ['is_active' => false]);

        // Read in February: the December anniversary is still "this year".
        $groups = collect($this->analytics('2026-02-10')['milestones']['service_years'])->keyBy('years');

        $this->assertSame([5, 10, 15, 20], $groups->keys()->all());
        $this->assertSame(['S5B', 'S5A'], $this->names($groups[5]));
        $this->assertSame(['2026-01-01', '2026-12-31'], array_column($groups[5]['items'], 'date'));
        $this->assertSame(['S10'], $this->names($groups[10]));
        $this->assertSame(['S15'], $this->names($groups[15]));
        $this->assertSame(['S20'], $this->names($groups[20]));
        $this->assertSame(2, $groups[5]['count']);
    }

    public function test_approaching_retirement_uses_the_configured_age_and_window_edges_are_inclusive(): void
    {
        $this->person('R1', attributes: ['date_of_birth' => '1966-06-01']);  // retires 2026-06-01: today
        $this->person('R2', attributes: ['date_of_birth' => '1967-06-01']);  // 2027-06-01: the last day of the window
        $this->person('R3', attributes: ['date_of_birth' => '1967-06-02']);  // 2027-06-02: one day outside
        $this->person('R4', attributes: ['date_of_birth' => '1966-05-31']);  // retired yesterday
        $this->person('R5', attributes: ['date_of_birth' => '1968-02-29']);  // 2028-02-29: far off
        $this->person('R6', attributes: ['date_of_birth' => '1960-01-01']);  // long past it
        $this->person('R7', attributes: ['date_of_birth' => '1966-07-01', 'is_active' => false]);

        $retirement = $this->analytics('2026-06-01')['milestones']['retirement'];

        $this->assertSame(60, $retirement['age']);
        $this->assertSame(12, $retirement['window_months']);
        $this->assertSame('2027-06-01', $retirement['window_end']);
        $this->assertSame(['R1', 'R2'], $this->names($retirement));
        $this->assertSame(['2026-06-01', '2027-06-01'], array_column($retirement['items'], 'date'));
        $this->assertSame(2, $retirement['overdue']);   // R4 and R6 are active and past retirement age
    }

    public function test_a_leap_day_birth_retires_on_the_29th_and_the_window_edge_is_exact(): void
    {
        $this->person('L1', attributes: ['date_of_birth' => '1968-02-29']); // 60 on 2028-02-29

        // The window ends 2028-02-27: not yet. One day later it is 2028-02-28; two days later it is in.
        $this->assertSame([], $this->names($this->analytics('2027-02-27')['milestones']['retirement']));
        $this->assertSame([], $this->names($this->analytics('2027-02-28')['milestones']['retirement']));
        $this->assertSame(['L1'], $this->names($this->analytics('2027-03-01')['milestones']['retirement']));

        // Before a leap-day birth reaches the age in a common year the retirement date is the 28th.
        config(['gwl.retirement_age' => 59]);
        $this->assertSame(['L1'], $this->names($this->analytics('2026-12-01')['milestones']['retirement']));
        $this->assertSame('2027-02-28', $this->analytics('2026-12-01')['milestones']['retirement']['items'][0]['date']);
    }

    public function test_the_retirement_age_comes_from_config_and_is_reported(): void
    {
        $this->person('R1', attributes: ['date_of_birth' => '1971-06-01']);   // 55 on 2026-06-01
        $this->person('R2', attributes: ['date_of_birth' => '1966-06-01']);

        config(['gwl.retirement_age' => 55, 'gwl.retirement_window_months' => 6]);
        $retirement = $this->analytics('2026-06-01')['milestones']['retirement'];

        $this->assertSame(55, $retirement['age']);
        $this->assertSame(6, $retirement['window_months']);
        $this->assertSame(['R1'], $this->names($retirement));
        $this->assertSame(1, $retirement['overdue']);   // R2 turned 55 in 2021
    }

    // ================================================================== (b) headcount and turnover

    protected function turnoverFixture(): void
    {
        // The books at 2025-12-31 held A1-A10 and X1, X2, X3, X6; at 2026-06-30 they hold A1-A10, H1 and H2.
        foreach (range(1, 10) as $n) {
            $this->person('A'.$n);
        }

        $this->person('X1', attributes: ['is_active' => false, 'deactivation_reason' => 'resigned', 'deactivated_at' => '2026-03-10']);
        $this->person('X2', attributes: ['is_active' => false, 'deactivation_reason' => 'retired', 'deactivated_at' => '2026-06-15']);
        $this->person('X3', attributes: ['is_active' => false, 'deactivation_reason' => 'transfer', 'deactivated_at' => '2026-04-01']);
        $this->person('X6', attributes: ['is_active' => false, 'deactivation_reason' => 'other', 'deactivated_at' => '2026-05-01']);
        $this->person('X4', attributes: ['is_active' => false, 'deactivation_reason' => 'left', 'deactivated_at' => '2025-11-01']);  // last year
        $this->person('X5', attributes: ['is_active' => false, 'deactivation_reason' => 'left', 'deactivated_at' => null]);          // no date: can't be placed
        $this->person('H1', joined: '2026-02-01', attributes: ['date_of_birth' => '2005-07-01']);
        $this->person('H2', joined: '2026-06-10', attributes: ['date_of_birth' => '1970-06-30']);
    }

    public function test_headcount_new_hires_and_exits(): void
    {
        $this->turnoverFixture();

        $headcount = $this->analytics('2026-06-30')['headcount'];

        $this->assertSame(12, $headcount['total_active']);
        $this->assertSame(1, $headcount['new_hires_month']);   // H2
        $this->assertSame(2, $headcount['new_hires_year']);    // H1 and H2
        $this->assertSame(1, $headcount['exits_month']);       // X2 (June); the transfer and last year's leaver aren't exits
        $this->assertSame(3, $headcount['exits_year']);        // X1, X2, X6; not X3 (transfer), X4 (last year), X5 (no date)
    }

    public function test_hires_and_exits_on_the_boundary_days_of_the_period(): void
    {
        $this->person('H1', joined: '2026-06-01');
        $this->person('H2', joined: '2026-06-30');
        $this->person('H3', joined: '2026-05-31');
        $this->person('H4', joined: '2026-07-01');   // joins after the date being read
        $this->person('E1', attributes: ['is_active' => false, 'deactivation_reason' => 'resigned', 'deactivated_at' => '2026-06-01']);
        $this->person('E2', attributes: ['is_active' => false, 'deactivation_reason' => 'resigned', 'deactivated_at' => '2026-06-30']);
        $this->person('E3', attributes: ['is_active' => false, 'deactivation_reason' => 'resigned', 'deactivated_at' => '2026-05-31']);
        $this->person('E4', attributes: ['is_active' => false, 'deactivation_reason' => 'resigned', 'deactivated_at' => '2025-12-31']);
        $this->person('E5', attributes: ['is_active' => false, 'deactivation_reason' => 'resigned', 'deactivated_at' => '2026-01-01']);

        $headcount = $this->analytics('2026-06-30')['headcount'];

        $this->assertSame(2, $headcount['new_hires_month']);   // 1 and 30 June, not 31 May or 1 July
        $this->assertSame(3, $headcount['new_hires_year']);    // plus 31 May
        $this->assertSame(2, $headcount['exits_month']);       // 1 and 30 June
        $this->assertSame(4, $headcount['exits_year']);        // plus 31 May and 1 January, not 31 December
    }

    public function test_turnover_is_exits_over_the_average_headcount_and_leaves_transfers_out(): void
    {
        $this->turnoverFixture();

        $headcount = $this->analytics('2026-06-30')['headcount'];

        // Year: 14 on the books at 31 Dec (A1-A10, X1, X2, X3, X6), 12 on 30 Jun: average 13; exits X1, X2, X6 = 3.
        $this->assertSame(13.0, $headcount['turnover_year']['average_headcount']);
        $this->assertSame(23.1, $headcount['turnover_year']['rate']);
        // Month: 12 on 31 May (A1-A10, X2, H1), 12 on 30 Jun: exits X2 = 1.
        $this->assertSame(12.0, $headcount['turnover_month']['average_headcount']);
        $this->assertSame(8.3, $headcount['turnover_month']['rate']);
    }

    public function test_a_transfer_changes_neither_the_exit_count_nor_the_turnover_numerator(): void
    {
        $this->turnoverFixture();
        $before = $this->analytics('2026-06-30')['headcount'];

        $this->person('X7', attributes: ['is_active' => false, 'deactivation_reason' => 'transfer', 'deactivated_at' => '2026-06-20']);
        $after = $this->analytics('2026-06-30')['headcount'];

        $this->assertSame($before['exits_year'], $after['exits_year']);
        $this->assertSame($before['exits_month'], $after['exits_month']);
        // The transfer does show in the exit-reasons breakdown.
        $this->assertSame(2, collect($this->analytics('2026-06-30')['exit_reasons']['rows'])->firstWhere('label', 'Transfer')['count']);
    }

    public function test_turnover_is_null_rather_than_a_division_by_zero_when_there_is_nobody(): void
    {
        $headcount = $this->analytics('2026-06-30')['headcount'];

        $this->assertSame(0, $headcount['total_active']);
        $this->assertNull($headcount['turnover_year']['rate']);
        $this->assertNull($headcount['average_tenure_years']);
    }

    public function test_average_tenure_and_the_age_profile(): void
    {
        $this->turnoverFixture();

        $analytics = $this->analytics('2026-06-30');

        // Ten staff of 6.5 years, one of 0.4 and one of 0.05 give 5.4.
        $this->assertEqualsWithDelta(5.4, $analytics['headcount']['average_tenure_years'], 0.05);
        $this->assertSame(0, $analytics['headcount']['tenure_unknown']);

        // Ten aged 36, H1 aged 20 (21 next week), H2 exactly 56 today: (360 + 20 + 56) / 12.
        $this->assertSame(36.3, $analytics['age']['average']);
        $this->assertSame(
            ['Under 25' => 1, '25 to 34' => 0, '35 to 44' => 10, '45 to 54' => 0, '55 and over' => 1],
            array_column($analytics['age']['bands'], 'count', 'label')
        );
    }

    // ================================================================== (c) distribution

    public function test_distribution_by_department_region_location_grade_and_employment(): void
    {
        $this->person('D1', $this->temaDistrict, department: $this->finance, grade: 'Snr. Gd. Level 1');
        $this->person('D2', $this->headOffice, department: $this->finance, grade: 'Junior Gd. Level 2');
        $this->person('D3', $this->kumasiDistrict, department: $this->operations, grade: 'Charwoman');
        $this->person('D4', $this->kumasiOffice, department: $this->operations, grade: 'Mgt. Gd. Level 1');
        $this->person('D5', $this->temaDistrict, department: $this->operations);   // no grade, "Senior Staff"

        $distribution = $this->analytics('2026-06-30')['distribution'];
        $counts = fn (array $rows) => array_column($rows, 'count', 'label');

        $this->assertEquals(['Finance' => 2, 'Operations' => 3], $counts($distribution['departments']));
        $this->assertSame(['Greater Accra' => 3, 'Ashanti' => 2], $counts($distribution['regions']));
        $this->assertSame(['Head Office' => 1, 'Regional Office' => 1, 'District' => 3], $counts($distribution['locations']));
        $this->assertSame(['Junior Staff' => 1, 'Senior Staff' => 2, 'Management' => 1, 'Contract' => 1], $counts($distribution['categories']));
        $this->assertSame(['Permanent' => 4, 'Contract' => 1], $counts($distribution['employment']));
        $this->assertSame(1, $counts($distribution['grades'])['Snr. Gd. Level 1']);
        $this->assertSame(0, $counts($distribution['grades'])['Snr. Gd. Level 4']);
        $this->assertSame(['Tema District' => 2], array_slice($counts($distribution['districts']), 0, 1));

        // Each department row carries the staff-list filter it links to.
        $this->assertSame(['department_id' => $this->operations->id], $distribution['departments'][0]['filter']);
    }

    public function test_the_old_categories_are_grouped_with_the_ones_they_became(): void
    {
        $this->person('L1', attributes: ['category' => 'Senior Management']);
        $this->person('L2', attributes: ['category' => 'Charwoman']);

        $counts = array_column($this->analytics('2026-06-30')['distribution']['categories'], 'count', 'label');

        $this->assertSame(1, $counts['Management']);
        $this->assertSame(1, $counts['Contract']);
    }

    // ================================================================== (d) exit reasons

    public function test_exit_reasons_are_shares_of_all_departures_with_transfers_shown_but_not_counted_as_exits(): void
    {
        $this->turnoverFixture();

        $reasons = $this->analytics('2026-06-30')['exit_reasons'];
        $rows = collect($reasons['rows'])->keyBy('label');

        $this->assertSame(2026, $reasons['year']);
        $this->assertSame(4, $reasons['departures']);   // X1, X2, X3, X6
        $this->assertSame(3, $reasons['exits']);        // without the transfer
        $this->assertSame(['Retirement', 'Resignation', 'Contract ended', 'Transfer', 'Other'], $rows->keys()->all());
        $this->assertSame([1, 1, 0, 1, 1], $rows->pluck('count')->all());
        $this->assertSame([25.0, 25.0, 0.0, 25.0, 25.0], $rows->pluck('percent')->all());
        $this->assertFalse($rows['Transfer']['counts_as_exit']);
        $this->assertTrue($rows['Resignation']['counts_as_exit']);
    }

    public function test_a_departure_with_no_reason_recorded_is_other_and_the_shares_add_up(): void
    {
        $this->person('E1', attributes: ['is_active' => false, 'deactivation_reason' => null, 'deactivated_at' => '2026-02-01']);
        $this->person('E2', attributes: ['is_active' => false, 'deactivation_reason' => 'contract_ended', 'deactivated_at' => '2026-03-01']);
        $this->person('E3', attributes: ['is_active' => false, 'deactivation_reason' => 'dead', 'deactivated_at' => '2026-04-01']);

        $reasons = $this->analytics('2026-06-30')['exit_reasons'];
        $rows = collect($reasons['rows'])->keyBy('label');

        $this->assertSame(2, $rows['Other']['count']);
        $this->assertSame(1, $rows['Contract ended']['count']);
        $this->assertEqualsWithDelta(100.0, collect($reasons['rows'])->sum('percent'), 0.2);
    }

    public function test_with_no_departures_every_share_is_zero(): void
    {
        $reasons = $this->analytics('2026-06-30')['exit_reasons'];

        $this->assertSame(0, $reasons['departures']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0], array_column($reasons['rows'], 'percent'));
    }

    // ================================================================== (e) grades and entitlement

    public function test_staff_missing_a_grade_and_the_entitlement_summary_by_category(): void
    {
        $this->person('G1', $this->headOffice, grade: 'Snr. Gd. Level 2');                 // 36 gross, 11 compulsory, 25
        $this->person('G2', $this->temaDistrict, grade: 'Snr. Gd. Level 1');               // 36, 0, 36
        $this->person('G3', $this->accraOffice, grade: 'Junior Gd. Level 4');              // 31, 11, 20
        $this->person('G4', $this->temaDistrict, grade: 'Junior Gd. Level 2', joined: '2020-01-06'); // 5 years: 26, 0, 26
        $this->person('G5', $this->headOffice, grade: 'Charwoman');                        // no leave
        $this->person('G6', $this->headOffice);                                            // no grade: flat 31, no compulsory

        $grades = $this->analytics('2026-06-30')['grades'];
        $rows = collect($grades['entitlement'])->keyBy('label');

        $this->assertSame(1, $grades['missing_grade']);
        $this->assertSame(2026, $grades['year']);
        $this->assertSame(11, $grades['compulsory_days']);
        $this->assertSame(['staff' => 3, 'gross' => 103, 'compulsory' => 11, 'net' => 92], collect($rows['Senior Staff'])->only(['staff', 'gross', 'compulsory', 'net'])->all());
        $this->assertSame(['staff' => 2, 'gross' => 57, 'compulsory' => 11, 'net' => 46], collect($rows['Junior Staff'])->only(['staff', 'gross', 'compulsory', 'net'])->all());
        $this->assertSame(['staff' => 1, 'gross' => 0, 'compulsory' => 0, 'net' => 0], collect($rows['Contract'])->only(['staff', 'gross', 'compulsory', 'net'])->all());
        $this->assertSame(['staff' => 6, 'gross' => 160, 'compulsory' => 22, 'net' => 138], collect($grades['entitlement_total'])->only(['staff', 'gross', 'compulsory', 'net'])->all());
    }

    public function test_the_compulsory_days_in_the_summary_follow_the_years_record(): void
    {
        $this->person('G1', $this->headOffice, grade: 'Snr. Gd. Level 2');
        \App\Models\CompulsoryLeavePeriod::query()->create(['year' => 2026, 'days' => 9]);

        $grades = $this->analytics('2026-06-30')['grades'];

        $this->assertSame(9, $grades['compulsory_days']);
        $this->assertSame(27, $grades['entitlement_total']['net']);
    }

    // ================================================================== caching and cost

    public function test_the_result_is_cached_briefly_per_scope_and_never_shared_across_scopes(): void
    {
        config(['gwl.hr_analytics_cache_seconds' => 60]);
        $this->person('A1', $this->temaDistrict);
        $this->person('K1', $this->kumasiDistrict);
        $accraHr = $this->userOf($this->person('HRA001', $this->accraOffice, roles: ['hr_region']));

        $all = $this->analytics('2026-06-30')['headcount']['total_active'];
        $this->person('A2', $this->temaDistrict);

        // Served from the cache: the new person is not there yet...
        $this->assertSame($all, $this->analytics('2026-06-30')['headcount']['total_active']);
        // ...and a regional HR user asking next gets their own figures, not the all-regions ones just cached.
        $this->assertSame(3, $this->analytics('2026-06-30', viewer: $accraHr)['headcount']['total_active']);   // A1, A2 and the HR user: not Ashanti's K1
        $this->assertSame($all, $this->analytics('2026-06-30')['headcount']['total_active']);

        // Another date is another entry; with caching off the new person shows straight away.
        config(['gwl.hr_analytics_cache_seconds' => 0]);
        $this->assertSame($all + 1, $this->analytics('2026-06-30')['headcount']['total_active']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_staff(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->analytics('2026-06-30');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->analytics('2026-06-30');   // warm the compulsory-days cache, which costs a few queries once

        foreach (range(1, 3) as $n) {
            $this->person('S'.$n, joined: '2021-02-01', attributes: ['date_of_birth' => '1966-06-0'.$n]);
        }
        $small = $count();

        foreach (range(4, 60) as $n) {
            $this->person('S'.$n, $n % 2 ? $this->kumasiDistrict : $this->headOffice, joined: '2021-02-01', attributes: ['date_of_birth' => '1966-06-01', 'is_active' => $n % 7 !== 0, 'deactivated_at' => $n % 7 === 0 ? '2026-03-01' : null]);
        }

        $this->assertSame($small, $count());
    }

    // ================================================================== the page

    public function test_the_page_opens_for_the_hr_roles_and_nobody_else(): void
    {
        $headOfficeHr = $this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']);
        $accraHr = $this->person('HRA001', $this->accraOffice, roles: ['hr_region']);
        $admin = $this->person('ADM001', $this->headOffice, roles: ['admin']);
        $manager = $this->person('DM001', $this->temaDistrict, roles: ['district_manager']);
        $employee = $this->person('EMP001', $this->temaDistrict);

        foreach ([$headOfficeHr, $accraHr, $admin] as $allowed) {
            $this->actingAs($this->userOf($allowed))->get(route('leave.hr-analytics'))->assertOk()->assertSee('HR Analytics');
            Livewire::actingAs($this->userOf($allowed))->test(HrAnalytics::class)->assertOk();
        }

        $this->actingAs($this->superAdmin)->get(route('leave.hr-analytics'))->assertOk();

        foreach ([$manager, $employee] as $denied) {
            $this->actingAs($this->userOf($denied))->get(route('leave.hr-analytics'))->assertForbidden();
            Livewire::actingAs($this->userOf($denied))->test(HrAnalytics::class)->assertForbidden();
        }
    }

    public function test_the_page_shows_every_card_and_says_which_retirement_age_it_used(): void
    {
        $this->person('A1', attributes: ['date_of_birth' => '1966-06-01']);
        $this->person('X1', attributes: ['is_active' => false, 'deactivation_reason' => 'transfer', 'deactivated_at' => today()->toDateString()]);

        Livewire::actingAs($this->superAdmin)->test(HrAnalytics::class)
            ->assertSee('Active staff')
            ->assertSee('Turnover, year to date')
            ->assertSee('Approaching retirement')
            ->assertSee('Retirement age 60')
            ->assertSee('Work anniversaries in')
            ->assertSee('Completing 5, 10, 15 or 20 years')
            ->assertSee('Staff by department')
            ->assertSee('Permanent and contract staff')
            ->assertSee('Why staff left in')
            ->assertSee('internal move, not counted as an exit')
            ->assertSee('Staff missing grade')
            ->assertSee('Annual leave entitlement');
    }

    public function test_the_page_filters_by_region_and_department_and_regional_hr_get_no_region_filter(): void
    {
        $this->person('A1', $this->temaDistrict, department: $this->finance);
        $this->person('K1', $this->kumasiDistrict, department: $this->operations);

        $page = Livewire::actingAs($this->superAdmin)->test(HrAnalytics::class);
        $this->assertSame(2, $page->get('analytics.headcount.total_active'));

        $page->set('regionId', (string) $this->ashanti->id);
        $this->assertSame(1, $page->get('analytics.headcount.total_active'));
        $page->set('regionId', '')->set('departmentId', (string) $this->finance->id);
        $this->assertSame(1, $page->get('analytics.headcount.total_active'));

        $accraHr = $this->userOf($this->person('HRA001', $this->accraOffice, roles: ['hr_region']));
        $regional = Livewire::actingAs($accraHr)->test(HrAnalytics::class);
        $this->assertSame([], $regional->viewData('filters')['regions']);
        $this->assertSame(2, $regional->get('analytics.headcount.total_active'));   // A1 and the HR user
    }

    public function test_count_links_go_to_the_staff_list_only_for_people_who_may_open_it(): void
    {
        $this->person('A1');   // no grade
        $hr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));
        $admin = $this->userOf($this->person('ADM001', $this->headOffice, roles: ['admin']));

        Livewire::actingAs($hr)->test(HrAnalytics::class)->assertSeeHtml('grade=none');
        // Global Admin can open the page but not the staff list: plain numbers, no dead links.
        Livewire::actingAs($admin)->test(HrAnalytics::class)->assertOk()->assertDontSeeHtml('grade=none');
    }

    public function test_the_hr_dashboard_and_hr_tools_live_in_the_staff_sidebar_not_the_leave_one(): void
    {
        $hr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));
        $admin = $this->userOf($this->person('ADM001', $this->headOffice, roles: ['admin']));
        $manager = $this->userOf($this->person('DM001', $this->temaDistrict, roles: ['district_manager']));

        $nav = app(\App\Support\ErpNavigation::class);
        $labels = fn (User $user, string $module) => collect($nav->build($user, $module)['sidebar'])->pluck('label')->all();
        $tools = ['HR Dashboard', 'HR Tools', 'HR Analytics', 'Compulsory Leave', 'HR Contacts', 'Leave Reports', 'Staff Reports'];

        // Staff Reports is gated by its own permission (staff.view_reports): Head Office HR hold it, Global Admin does not.
        $this->seed(\Database\Seeders\StaffRolePermissionSeeder::class);

        foreach ([$hr, $admin] as $viewer) {
            $staff = $labels($viewer, 'staff');
            foreach (array_diff($tools, $viewer === $admin ? ['Staff Reports'] : []) as $label) {
                $this->assertContains($label, $staff, $label);
            }
            if ($viewer === $hr) {
                // Staff Reports sits under HR Tools, before the Data section.
                $this->assertGreaterThan(array_search('HR Tools', $staff, true), array_search('Staff Reports', $staff, true));
                $this->assertLessThan(array_search('Data', $staff, true), array_search('Staff Reports', $staff, true));
            }
            // And none of it is left in Leave.
            $this->assertSame([], array_intersect($tools, $labels($viewer, 'leave')));
        }

        // Managers have no HR tools anywhere.
        $this->assertSame([], array_intersect($tools, $labels($manager, 'staff')));
        // Global Admin has the tools but not the staff list itself.
        $this->assertNotContains('All Employees', $labels($admin, 'staff'));
        $this->assertContains('All Employees', $labels($hr, 'staff'));
    }

    public function test_the_hr_dashboard_header_links_to_analytics_and_staff_reports_for_those_who_may_open_them(): void
    {
        $this->seed(\Database\Seeders\StaffRolePermissionSeeder::class);
        $hr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));
        $admin = $this->userOf($this->person('ADM001', $this->headOffice, roles: ['admin']));

        $this->actingAs($hr)->get(route('leave.hr-dashboard'))
            ->assertSee('HR Analytics')->assertSee('Staff Reports')->assertSeeHtml(route('staff.reports'));
        // Global Admin lacks staff.view_reports, so no dead button.
        $this->actingAs($admin)->get(route('leave.hr-dashboard'))
            ->assertSee('HR Analytics')->assertDontSee('Staff Reports');
    }
    public function test_the_three_hr_pages_link_to_each_other(): void
    {
        $this->seed(\Database\Seeders\StaffRolePermissionSeeder::class);
        $hr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));

        $this->actingAs($hr)->get(route('leave.hr-analytics'))
            ->assertSeeHtml(route('leave.hr-dashboard'))->assertSeeHtml(route('staff.reports'));
        $this->get(route('staff.reports'))
            ->assertSeeHtml(route('leave.hr-dashboard'))->assertSeeHtml(route('leave.hr-analytics'));
        $this->get(route('leave.hr-dashboard'))
            ->assertSeeHtml(route('leave.hr-analytics'))->assertSeeHtml(route('staff.reports'));
    }
    public function test_staff_is_the_first_module_and_leave_the_second(): void
    {
        $hr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));

        $slugs = collect(app(\App\Support\ErpNavigation::class)->build($hr, 'staff')['modules'])->pluck('slug')->all();

        $this->assertSame(['staff', 'leave'], array_slice($slugs, 0, 2));
    }

    public function test_global_admins_staff_tile_lands_on_the_hr_dashboard(): void
    {
        $admin = $this->userOf($this->person('ADM001', $this->headOffice, roles: ['admin']));

        $staffTile = collect(app(\App\Support\ErpNavigation::class)->build($admin, 'staff')['modules'])->firstWhere('slug', 'staff');

        $this->assertSame(route('leave.hr-dashboard'), $staffTile['route']);
    }

    public function test_the_hr_dashboard_has_its_own_page_for_hr_and_leave_home_is_their_personal_one(): void
    {
        $hr = $this->userOf($this->person('HRH001', $this->headOffice, roles: ['hr_headoffice']));
        $manager = $this->userOf($this->person('DM001', $this->temaDistrict, roles: ['district_manager']));

        $this->actingAs($hr)->get(route('leave.hr-dashboard'))->assertOk()->assertSee('HR Dashboard');
        $this->actingAs($hr)->get(route('leave.home'))->assertOk()->assertDontSee('HR Dashboard');
        $this->actingAs($manager)->get(route('leave.hr-dashboard'))->assertForbidden();
        // A super_admin account with no employee record has no personal leave home: it is sent to the HR dashboard.
        $this->actingAs(User::query()->where('staff_id', 'SA001')->firstOrFail())->get(route('leave.hr-dashboard'))->assertOk();
    }
}
