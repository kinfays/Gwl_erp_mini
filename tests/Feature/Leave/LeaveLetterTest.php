<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\ApprovalLetter;
use App\Models\AuditLog;
use App\Models\CompulsoryLeavePeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveLetter;
use App\Models\LeaveLetterSetting;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveLetterService;
use App\Services\Leave\LeaveLetterSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Leave\Concerns\BuildsLeaveLetters;
use Tests\TestCase;

/**
 * What an approval letter says (tense, retrospective wording, numbers in words, end and resume dates, the Christmas line,
 * the balance), when it is made, who may open and edit it, and what a print locks.
 */
class LeaveLetterTest extends TestCase
{
    use BuildsLeaveLetters;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLetterWorld();
    }

    protected function letters(): LeaveLetterService
    {
        return app(LeaveLetterService::class);
    }

    /** @return array<string, string|null> */
    protected function text(LeaveRequest $request): array
    {
        return $this->letters()->paragraphs($request->letter->snapshot);
    }

    protected function hr(string $role = 'hr_headoffice', ?\App\Models\District $district = null): User
    {
        return $this->userOf($this->staff('HR'.strtoupper(substr($role, 3, 3)).rand(100, 999), $district ?? $this->headOffice, $this->finance, [$role]));
    }

    protected function title(Employee $employee, string $title): void
    {
        Employee::withoutEvents(fn () => $employee->forceFill(['title' => $title])->save());
    }

    // ================================================================== wording

    public function test_a_future_leave_is_written_in_the_future_tense_with_the_template_wording(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-15'], $manager, $chief);
        $text = $this->text($request);

        $this->assertSame('RE: REQUEST FOR PART LEAVE', $request->letter->snapshot['subject']);
        $this->assertSame('Your application dated May 6, 2026, on the above subject refers.', $text['application']);
        $this->assertSame('Approval is given to enable you spend five (5) working days from your 2026 annual leave entitlement of thirty-six (36) working days with effect from Monday, May 11, 2026.', $text['approval']);
        $this->assertSame('Your five (5) working days leave will end on Friday, May 15, 2026, and you are expected to resume duty on Monday, May 18, 2026.', $text['dates']);
        $this->assertSame('You now have thirty-one (31) working days of leave remaining for 2026.', $text['balance']);
        $this->assertNull($text['christmas']);
    }

    public function test_a_leave_that_has_already_started_is_retrospective_and_in_the_past_tense(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-04', 'end_date' => '2026-05-05'], $manager, $chief);
        $text = $this->text($request);

        $this->assertStringContainsString('two (2) working days from your 2026 annual leave entitlement of thirty-six (36) working days with retrospective effect from Monday, May 4, 2026.', $text['approval']);
        $this->assertSame('Your two (2) working days leave ended on Tuesday, May 5, 2026, and you were expected to resume duty on Wednesday, May 6, 2026.', $text['dates']);
        $this->assertSame('You now have thirty-four (34) working days of leave remaining for 2026.', $text['balance']);
    }

    public function test_a_leave_that_started_but_has_not_ended_is_retrospective_and_still_future_for_the_end(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-05', 'end_date' => '2026-05-08'], $manager, $chief);
        $text = $this->text($request);

        $this->assertStringContainsString('with retrospective effect from Tuesday, May 5, 2026.', $text['approval']);
        $this->assertStringContainsString('will end on Friday, May 8, 2026, and you are expected to resume duty on Monday, May 11, 2026.', $text['dates']);
    }

    public function test_leave_starting_today_is_not_retrospective(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-06', 'end_date' => '2026-05-06'], $manager, $chief);

        $this->assertStringContainsString('one (1) working day from your 2026', $this->text($request)['approval']);
        $this->assertStringNotContainsString('retrospective', $this->text($request)['approval']);
        $this->assertStringContainsString('will end on Wednesday, May 6, 2026', $this->text($request)['dates']);
    }

    public function test_full_use_of_the_entitlement_is_annual_leave_and_part_use_is_a_request_for_part_leave(): void
    {
        // Junior Gd. Level 1 with five years' service has 26 days: 26 working days from 11 May is up to 15 June.
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain('Junior Gd. Level 1');

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-06-15'], $manager, $chief);

        $this->assertSame('RE: ANNUAL LEAVE', $request->letter->snapshot['subject']);
        $this->assertStringContainsString('twenty-six (26) working days from your 2026 annual leave entitlement of twenty-six (26) working days', $this->text($request)['approval']);
        $this->assertSame('You now have zero (0) working days of leave remaining for 2026.', $this->text($request)['balance']);
    }

    public function test_end_and_resume_dates_skip_weekends_and_holidays(): void
    {
        Holiday::query()->create(['holiday_name' => 'Test Holiday', 'holiday_date' => '2026-05-18']); // the Monday
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        // Wednesday to Saturday: three working days, ending on the Friday; back on Tuesday (Saturday, Sunday, holiday Monday).
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-13', 'end_date' => '2026-05-16'], $manager, $chief);

        $this->assertSame('Your three (3) working days leave will end on Friday, May 15, 2026, and you are expected to resume duty on Tuesday, May 19, 2026.', $this->text($request)['dates']);
    }

    public function test_numbers_are_written_in_lowercase_words_with_the_figure_in_brackets(): void
    {
        $letters = $this->letters();

        $this->assertSame('five (5)', $letters->figure(5));
        $this->assertSame('thirty-six (36)', $letters->figure(36));
        $this->assertSame('twenty-one (21)', $letters->figure(21));
        $this->assertSame('zero (0)', $letters->figure(0));
        $this->assertSame('eleven (11)', $letters->figure(11));
        $this->assertSame('ninety-three (93)', $letters->figure(93));
        $this->assertSame('one hundred and five (105)', $letters->figure(105));
        $this->assertSame('one (1) working day', $letters->workingDaysPhrase(1));
        $this->assertSame('seven (7) working days', $letters->workingDaysPhrase(7));
        $this->assertSame('Wednesday, May 6, 2026', $letters->formatDate('2026-05-06'));
    }

    public function test_the_christmas_line_appears_only_where_a_compulsory_deduction_applies_and_can_be_unticked(): void
    {
        CompulsoryLeavePeriod::query()->create(['year' => 2026, 'days' => 9]);
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->headOfficeChain();

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-15'], $manager, $chief);

        // 36 gross, the year's 9 compulsory days taken off (27 to take), 5 used: 22 left.
        $this->assertSame('Please note that the nine (9) days Christmas break granted by management has also been deducted from your 2026 annual leave.', $this->text($request)['christmas']);
        $this->assertSame('You now have twenty-two (22) working days of leave remaining for 2026.', $this->text($request)['balance']);

        $this->letters()->update($this->hr(), $request->letter, ['christmas' => false]);

        $this->assertNull($this->text($request->fresh(['letter']))['christmas']);
    }

    public function test_district_staff_and_other_leave_types_have_no_christmas_line(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $district = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);

        $this->assertNull($this->text($district)['christmas']);
        $this->assertFalse($district->letter->snapshot['compulsory']['applies']);

        $ho = $this->headOfficeChain();
        $casual = $this->approvedRequest($ho['applicant'], $this->userOf($ho['chief']), ['leave_type' => 'Casual', 'start_date' => '2026-05-08', 'end_date' => '2026-05-08']);
        $letter = $this->letters()->generate($casual, $this->userOf($ho['chief']));

        // The deduction is an annual-leave matter: a casual-leave letter doesn't mention it.
        $this->assertNull($this->letters()->paragraphs($letter->snapshot)['christmas']);
    }

    public function test_other_leave_types_get_the_same_wording_built_for_their_type(): void
    {
        ['applicant' => $applicant, 'chief' => $chief] = $this->districtChain();
        $approver = $this->userOf($chief);

        $casual = $this->letters()->generate($this->approvedRequest($applicant, $approver, ['leave_type' => 'Casual', 'start_date' => '2026-05-08', 'end_date' => '2026-05-08']), $approver);
        $this->assertSame('RE: CASUAL LEAVE', $casual->snapshot['subject']);
        $this->assertSame('Approval is given to enable you spend one (1) working day on casual leave with effect from Friday, May 8, 2026.', $this->letters()->paragraphs($casual->snapshot)['approval']);
        $this->assertStringContainsString('of casual leave remaining for 2026', $this->letters()->paragraphs($casual->snapshot)['balance']);

        $sick = $this->letters()->generate($this->approvedRequest($applicant, $approver, ['leave_type' => 'Sick', 'start_date' => '2026-05-11', 'end_date' => '2026-05-12']), $approver);
        $this->assertSame('RE: SICK LEAVE', $sick->snapshot['subject']);
        // Sick leave has no entitlement, so no balance line.
        $this->assertNull($this->letters()->paragraphs($sick->snapshot)['balance']);
    }

    // ================================================================== the addressee and the signatory

    public function test_the_addressee_block_and_salutation(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $this->title($applicant, 'Ing.');

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);
        $addressee = $request->letter->snapshot['addressee'];

        $this->assertSame('Ing. EMPLOYEE EMP001', $addressee['name']);
        $this->assertSame('OFFICER', $addressee['designation']);
        $this->assertSame('THRO’ THE DISTRICT MANAGER', $addressee['thro']);
        $this->assertSame('GHANA WATER LIMITED', $addressee['organisation']);
        $this->assertSame('TEMA DISTRICT', $addressee['station']);
        // No title says "Madam" or "Sir" on its own, so the gender decides (the fixture's employees are women).
        $this->assertSame('Dear Madam,', $addressee['salutation']);
    }

    public function test_the_salutation_follows_the_title_then_the_gender_then_falls_back(): void
    {
        $service = $this->letters();
        $make = fn (?string $title, ?string $gender) => (new Employee)->forceFill(['title' => $title, 'gender' => $gender]);

        $this->assertSame('Dear Sir,', $service->salutation($make('Mr.', 'Female')));
        $this->assertSame('Dear Madam,', $service->salutation($make('Mrs.', 'Male')));
        $this->assertSame('Dear Madam,', $service->salutation($make('Ms.', null)));
        $this->assertSame('Dear Sir,', $service->salutation($make('Dr.', 'Male')));
        $this->assertSame('Dear Madam,', $service->salutation($make('Prof.', 'Female')));
        $this->assertSame('Dear Sir/Madam,', $service->salutation($make(null, null)));
    }

    public function test_the_thro_line_follows_who_recommended_and_is_left_out_for_single_stage_leave(): void
    {
        ['chief' => $chief] = $this->headOfficeChain();
        $approver = $this->userOf($chief);

        $unit = $this->staff('HOU001', $this->headOffice, $this->finance, unit: 'Payroll');
        $unitLetter = $this->letters()->generate($this->approvedRequest($unit, $approver, ['is_single_stage' => false]), $approver);
        $this->assertSame('THRO’ THE UNIT MANAGER', $unitLetter->snapshot['addressee']['thro']);
        $this->assertSame('PAYROLL', $unitLetter->snapshot['addressee']['station']);

        $noUnit = $this->staff('HOU002', $this->headOffice, $this->finance);
        $deptLetter = $this->letters()->generate($this->approvedRequest($noUnit, $approver, ['is_single_stage' => false]), $approver);
        $this->assertSame('THRO’ THE DEPARTMENTAL MANAGER', $deptLetter->snapshot['addressee']['thro']);
        $this->assertSame('FINANCE', $deptLetter->snapshot['addressee']['station']);

        $regional = $this->staff('REG001', $this->accraOffice, $this->finance);
        $regionalLetter = $this->letters()->generate($this->approvedRequest($regional, $approver, ['is_single_stage' => false]), $approver);
        $this->assertSame('THRO’ THE DEPARTMENTAL MANAGER', $regionalLetter->snapshot['addressee']['thro']);
        // Regional office staff are addressed to their region, without the company line (as in the template).
        $this->assertSame('GREATER ACCRA', $regionalLetter->snapshot['addressee']['station']);
        $this->assertNull($regionalLetter->snapshot['addressee']['organisation']);

        // A district manager applies straight to the regional chief manager: nobody to go "through".
        ['chief' => $regionalChief] = $this->districtChain();
        $districtManager = $this->staff('DM009', $this->temaDistrict, $this->operations, ['district_manager']);
        $request = $this->approveLeave($districtManager, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], null, $regionalChief);

        $this->assertTrue($request->is_single_stage);
        $this->assertNull($request->letter->snapshot['addressee']['thro']);
    }

    public function test_the_signature_block_names_the_post_of_the_approver(): void
    {
        // Regional chief manager.
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $regional = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);
        $this->assertSame(['mode' => 'self', 'name' => 'EMPLOYEE RCM001', 'title' => 'REGIONAL CHIEF MANAGER', 'for_line' => null], collect($regional->letter->snapshot['signatory'])->only(['mode', 'name', 'title', 'for_line'])->all());

        // Head Office chief manager of the department.
        $ho = $this->headOfficeChain();
        $headOffice = $this->approveLeave($ho['applicant'], ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $ho['manager'], $ho['chief']);
        $this->assertSame('CHIEF MANAGER, FINANCE', $headOffice->letter->snapshot['signatory']['title']);

        // A chief manager's own leave goes to the Managing Director.
        $md = $this->staff('MD001', $this->headOffice, $this->finance, ['managing_director']);
        $chiefApplicant = $this->staff('HOC009', $this->headOffice, $this->finance, ['chief_manager'], grade: 'Mgt. Gd. Level 2');
        $mdLetter = $this->approveLeave($chiefApplicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], null, $md);
        $this->assertTrue($mdLetter->is_single_stage);
        $this->assertSame('MANAGING DIRECTOR', $mdLetter->letter->snapshot['signatory']['title']);
    }

    // ================================================================== when the letter is made

    public function test_a_letter_is_made_at_final_approval_for_two_stage_single_stage_and_md_flows(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $twoStage = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);

        $districtManager = $this->staff('DM010', $this->temaDistrict, $this->operations, ['district_manager']);
        $singleStage = $this->approveLeave($districtManager, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], null, $chief);

        $md = $this->staff('MD001', $this->headOffice, $this->finance, ['managing_director']);
        $chiefManager = $this->staff('HOC010', $this->headOffice, $this->finance, ['chief_manager']);
        $mdFlow = $this->approveLeave($chiefManager, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], null, $md);

        foreach ([$twoStage, $singleStage, $mdFlow] as $request) {
            $this->assertSame('Approved', $request->leave_status);
            $this->assertNotNull($request->letter, 'letter for request '.$request->id);
            $this->assertSame('2026-05-06', $request->letter->issued_at->toDateString());
        }

        $this->assertSame(3, LeaveLetter::query()->count());
    }

    public function test_a_denied_request_has_no_letter(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->workflow()->submit($applicant, $this->leaveData(['start_date' => '2026-05-11', 'end_date' => '2026-05-12']));
        $this->workflow()->recommend($manager, $request->fresh(), null, true);
        $this->workflow()->finalDecision($chief, $request->fresh(), null, false);

        $this->assertSame(0, LeaveLetter::query()->count());
    }

    public function test_a_letter_that_cannot_be_made_does_not_undo_the_approval_and_hr_can_regenerate_it(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $this->app->bind(LeaveLetterService::class, fn () => new class extends LeaveLetterService
        {
            public function __construct() {}

            public function generate(LeaveRequest $request, ?User $approver = null, bool $applySignature = false): LeaveLetter
            {
                throw new RuntimeException('letter generation failed');
            }
        });

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-15'], $manager, $chief);

        $this->assertSame('Approved', $request->leave_status);
        $this->assertNull($request->letter);
        $this->assertSame(5, (int) \App\Models\LeaveBalance::query()->where('employee_id', $applicant->id)->where('leave_type', 'Annual')->value('used_days'));

        // Back to normal: the screen offers HR the letter and regenerate makes it.
        $this->app->forgetInstance(LeaveLetterService::class);
        $this->app->bind(LeaveLetterService::class, LeaveLetterService::class);

        $letter = app(LeaveLetterService::class)->regenerate($this->hr('hr_headoffice', $this->temaDistrict), $request->fresh());

        $this->assertSame($request->id, $letter->leave_request_id);
        $this->assertTrue(AuditLog::query()->where('action', 'leave_letter_regenerated')->exists());
    }

    public function test_the_snapshot_never_changes_when_the_letterhead_or_board_is_edited_later(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $settings = app(LeaveLetterSettingsService::class);
        $admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));

        $settings->saveLetterhead($admin, $this->accra->id, ['region_name' => 'Accra West Region', 'address_lines' => ['P. O. Box 1', 'Accra, Ghana', 'West Africa']]);
        $first = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);
        $before = $first->letter->snapshot;

        $settings->saveLetterhead($admin, $this->accra->id, ['region_name' => 'Renamed Region', 'address_lines' => ['P. O. Box 999']]);
        $settings->saveCompany($admin, ['board_members' => [['name' => 'Mr. New Chair', 'role' => 'Chairman']], 'registered_office' => 'New Office']);

        $this->assertSame($before, $first->letter->fresh()->snapshot);
        $this->assertSame('Accra West Region', $first->letter->fresh()->snapshot['letterhead']['region_name']);
        $this->assertCount(11, $first->letter->fresh()->snapshot['company']['board']);

        // A letter made afterwards picks the new values up.
        $second = $this->approveLeave($applicant, ['start_date' => '2026-05-13', 'end_date' => '2026-05-14'], $manager, $chief);
        $this->assertSame('Renamed Region', $second->letter->snapshot['letterhead']['region_name']);
        $this->assertSame('Mr. New Chair', $second->letter->snapshot['company']['board'][0]['name']);
        $this->assertSame('New Office', $second->letter->snapshot['company']['registered_office']);
    }

    // ================================================================== who may open it

    public function test_the_applicant_the_approvers_and_hr_in_scope_may_open_the_letter(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);

        $accraHr = $this->hr('hr_region', $this->accraOffice);
        $headOfficeHr = $this->hr('hr_headoffice');
        $admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));
        $superAdmin = $this->userOf($this->staff('SA001', $this->headOffice, $this->finance, ['super_admin']));

        foreach ([$this->userOf($applicant), $this->userOf($manager), $this->userOf($chief), $accraHr, $headOfficeHr, $admin, $superAdmin] as $allowed) {
            $this->actingAs($allowed)->get(route('leave.letters.show', $request))->assertOk()->assertSee('Leave Approval Letter');
            $this->get(route('leave.letters.pdf', $request))->assertOk();
        }
    }

    public function test_everyone_else_and_other_regions_hr_are_refused(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);

        $colleague = $this->userOf($this->staff('EMP002', $this->temaDistrict, $this->operations));
        $ashantiHr = $this->hr('hr_region', $this->kumasiOffice);
        $otherManager = $this->userOf($this->staff('DM002', $this->kumasiDistrict, $this->operations, ['district_manager']));

        foreach ([$colleague, $ashantiHr, $otherManager] as $denied) {
            $this->actingAs($denied)->get(route('leave.letters.show', $request))->assertForbidden();
            $this->get(route('leave.letters.pdf', $request))->assertForbidden();
            Livewire::actingAs($denied)->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->assertForbidden();
        }

        // A Head Office letter is not Accra's regional HR's: Head Office is its own scope.
        $ho = $this->headOfficeChain();
        $hoRequest = $this->approveLeave($ho['applicant'], ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $ho['manager'], $ho['chief']);
        $this->actingAs($this->hr('hr_region', $this->accraOffice))->get(route('leave.letters.show', $hoRequest))->assertForbidden();
    }

    public function test_only_an_approved_request_has_a_letter_screen(): void
    {
        ['applicant' => $applicant] = $this->districtChain();
        $planned = $this->workflow()->savePlanned($applicant, $this->leaveData());

        $this->actingAs($this->userOf($applicant))->get(route('leave.letters.show', $planned))->assertForbidden();
    }

    // ================================================================== editing, printing and locking

    public function test_hr_in_scope_edits_the_few_fields_but_the_applicant_and_approvers_cannot(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);
        $hr = $this->hr('hr_region', $this->accraOffice);

        $this->letters()->update($hr, $request->letter, ['reference_no' => 'GWL/AW/HR/12', 'cc' => "Regional Chief Manager\nFile"]);

        $letter = $request->letter->fresh();
        $this->assertSame('GWL/AW/HR/12', $letter->reference_no);
        $this->assertSame(['Regional Chief Manager', 'File'], $letter->snapshot['cc']);

        foreach ([$applicant, $manager, $chief] as $other) {
            try {
                $this->letters()->update($this->userOf($other), $letter, ['reference_no' => 'X']);
                $this->fail('Only HR in scope may edit the letter.');
            } catch (AuthorizationException) {
                $this->assertSame('GWL/AW/HR/12', $letter->fresh()->reference_no);
            }
        }

        // The Livewire screen says the same.
        Livewire::actingAs($this->userOf($applicant))->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->set('referenceNo', 'Y')->call('save')->assertForbidden();
        Livewire::actingAs($hr)->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->set('referenceNo', 'GWL/AW/HR/13')->call('save')->assertHasNoErrors();
        $this->assertSame('GWL/AW/HR/13', $letter->fresh()->reference_no);
    }

    public function test_the_first_print_locks_the_fields_and_every_print_and_edit_is_counted_and_audited(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);
        $hr = $this->hr('hr_headoffice');

        $this->letters()->update($hr, $request->letter, ['reference_no' => 'REF/1']);

        $response = $this->actingAs($this->userOf($applicant))->get(route('leave.letters.pdf', $request));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $letter = $request->letter->fresh();
        $this->assertTrue($letter->isLocked());
        $this->assertSame(1, $letter->printed_count);
        $this->assertSame($this->userOf($applicant)->id, $letter->last_printed_by);

        try {
            $this->letters()->update($hr, $letter, ['reference_no' => 'REF/2']);
            $this->fail('A printed letter is locked.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        $this->assertSame('REF/1', $letter->fresh()->reference_no);

        // A reprint is the same letter, counted again.
        $before = $letter->snapshot;
        $this->get(route('leave.letters.pdf', $request))->assertOk();
        $this->assertSame(2, $letter->fresh()->printed_count);
        $this->assertSame($before, $letter->fresh()->snapshot);

        // The screen locks too.
        Livewire::actingAs($hr)->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->assertSee('Locked');

        $actions = AuditLog::query()->pluck('action');
        $this->assertTrue($actions->contains('leave_letter_generated'));
        $this->assertTrue($actions->contains('leave_letter_updated'));
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'leave_letter_printed')->count());
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'leave_letter_reprinted')->count());
        $updated = AuditLog::query()->where('action', 'leave_letter_updated')->sole();
        $this->assertNull($updated->old_values['reference_no']);
        $this->assertSame('REF/1', $updated->new_values['reference_no']);
    }

    public function test_the_print_view_has_the_letterhead_the_body_and_the_board_footer(): void
    {
        $settings = app(LeaveLetterSettingsService::class);
        $admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));
        $settings->saveLetterhead($admin, $this->accra->id, ['region_name' => 'Accra West Region', 'address_lines' => ['Post Office Box 1', 'Accra, Ghana', 'West Africa']]);
        $settings->saveCompany($admin, [
            'bankers' => ['GCB Bank'],
            'board_members' => LeaveLetterSetting::current()->board_members,
            'registered_office' => 'Ridge, Accra',
            'telephone' => '0302 000000',
            'website' => 'www.gwl.example',
            'email' => 'info@gwl.example',
        ]);

        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-15'], $manager, $chief);

        $html = $this->letters()->html($request->letter);

        foreach (['GHANA WATER LTD', 'Main Bankers', 'GCB Bank', 'My Ref. No.', 'Your Ref. No.', 'Accra West Region', 'Post Office Box 1', 'West Africa', '6 May 2026',
            'THRO’ THE DISTRICT MANAGER', 'GHANA WATER LIMITED', 'Dear Madam,', 'RE: REQUEST FOR PART LEAVE', 'Yours faithfully,', 'REGIONAL CHIEF MANAGER', 'cc:',
            'Board of Directors', 'Hon. Patrick Yaw Boamah (Chairman)', 'Ing. Dr. Clifford A. Braimah (Managing Director)', 'Registered Office:', 'Ridge, Accra', 'www.gwl.example', 'info@gwl.example'] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
    }
}
