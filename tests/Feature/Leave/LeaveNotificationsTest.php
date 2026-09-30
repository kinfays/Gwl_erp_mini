<?php

namespace Tests\Feature\Leave;

use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveDeniedMail;
use App\Mail\LeaveHrNotificationMail;
use App\Mail\LeaveRecommendedMail;
use App\Mail\LeaveSubmittedMail;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveHrContact;
use App\Models\LeaveRequest;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Feature\Leave\Concerns\BuildsLeaveOrg;
use Tests\TestCase;

/**
 * The emails and in-app notices around a leave request, especially the HR notice after final approval, the
 * central email switches, and that a mail failure never touches the workflow.
 */
class LeaveNotificationsTest extends TestCase
{
    use BuildsLeaveOrg;
    use RefreshDatabase;

    protected Employee $applicant;

    protected Employee $manager;

    protected Employee $chief;

    protected Employee $accraHr;

    protected Employee $ashantiHr;

    protected Employee $headOfficeHr;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->buildOrg();

        // A Greater Accra district chain, and HR of each scope.
        $this->applicant = $this->staff('EMP101', $this->temaDistrict, $this->operations);
        $this->manager = $this->staff('DM101', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->chief = $this->staff('RCM101', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $this->accraHr = $this->staff('HRA101', $this->accraOffice, $this->finance, ['hr_region']);
        $this->ashantiHr = $this->staff('HRK101', $this->kumasiOffice, $this->finance, ['hr_region']);
        $this->headOfficeHr = $this->staff('HRH101', $this->headOffice, $this->finance, ['hr_headoffice']);
        // Neither of these should ever be told anything.
        $this->staff('HRA102', $this->accraOffice, $this->finance, ['hr_region'], userActive: false);
        $this->staff('HRA103', $this->accraOffice, $this->finance, ['hr_region'], employeeActive: false);

        $this->contact($this->accra->id, 'hr.accra@example.com');
        $this->contact($this->ashanti->id, 'hr.ashanti@example.com');
        $this->contact(null, 'hr.headoffice@example.com');
    }

    // ---------------------------------------------------------------- HR notice after final approval

    public function test_region_staff_approval_notifies_that_regions_hr_by_email_and_in_app(): void
    {
        $request = $this->approveTwoStage($this->applicant, $this->manager, $this->chief);

        Mail::assertSent(LeaveHrNotificationMail::class, 1);
        Mail::assertSent(LeaveHrNotificationMail::class, fn (LeaveHrNotificationMail $mail) => $mail->hasTo('hr.accra@example.com')
            && $mail->assertSeeInHtml('Employee EMP101')
            && $mail->assertSeeInHtml('Annual')
            && $mail->assertSeeInHtml('05 Oct 2026')
            && $mail->assertSeeInHtml('07 Oct 2026')
            && $mail->assertSeeInHtml('Working Days')
            && $mail->assertSeeInHtml('Employee DM101')
            && $mail->assertSeeInHtml('Employee RCM101'));

        $this->assertHrInAppSentTo([$this->accraHr], $request);
        $this->assertHrInAppNotSentTo([$this->ashantiHr, $this->headOfficeHr, $this->staffByStaffId('HRA102'), $this->staffByStaffId('HRA103')], $request);
    }

    public function test_head_office_staff_approval_notifies_head_office_hr(): void
    {
        $applicant = $this->staff('EMP102', $this->headOffice, $this->finance);
        $manager = $this->staff('DMH101', $this->headOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('CM101', $this->headOffice, $this->finance, ['chief_manager']);

        $request = $this->approveTwoStage($applicant, $manager, $chief);

        // Head Office staff carry the Greater Accra region_id, but they belong to Head Office HR, not Accra's.
        Mail::assertSent(LeaveHrNotificationMail::class, 1);
        Mail::assertSent(LeaveHrNotificationMail::class, fn ($mail) => $mail->hasTo('hr.headoffice@example.com'));
        Mail::assertNotSent(LeaveHrNotificationMail::class, fn ($mail) => $mail->hasTo('hr.accra@example.com'));

        $this->assertHrInAppSentTo([$this->headOfficeHr], $request);
        $this->assertHrInAppNotSentTo([$this->accraHr, $this->ashantiHr], $request);
    }

    public function test_a_regional_office_request_goes_to_its_region_not_head_office(): void
    {
        $applicant = $this->staff('EMP103', $this->kumasiOffice, $this->finance);
        $manager = $this->staff('DMR101', $this->kumasiOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('RCM102', $this->kumasiOffice, $this->operations, ['regional_chief_manager']);
        // Two HR users for Ashanti (only region matters) and the Head Office one, who must not be told.
        $request = $this->approveTwoStage($applicant, $manager, $chief);

        Mail::assertSent(LeaveHrNotificationMail::class, 1);
        Mail::assertSent(LeaveHrNotificationMail::class, fn ($mail) => $mail->hasTo('hr.ashanti@example.com'));
        $this->assertHrInAppSentTo([$this->ashantiHr], $request);
        $this->assertHrInAppNotSentTo([$this->headOfficeHr, $this->accraHr], $request);
    }

    public function test_single_stage_approval_by_the_managing_director_also_notifies_hr(): void
    {
        $md = $this->staff('MD101', $this->headOffice, $this->operations, ['managing_director']);
        $chiefManager = $this->staff('CM102', $this->headOffice, $this->finance, ['chief_manager']);

        $request = $this->submitLeave($chiefManager);
        $this->workflow()->finalDecision($md, $request->fresh(), 'Approved', true);

        Mail::assertSent(LeaveHrNotificationMail::class, 1);
        Mail::assertSent(LeaveHrNotificationMail::class, fn (LeaveHrNotificationMail $mail) => $mail->hasTo('hr.headoffice@example.com')
            && $mail->assertSeeInHtml('Employee CM102')
            && $mail->assertSeeInHtml('Approved by')
            && $mail->assertDontSeeInHtml('Recommended by'));
        $this->assertHrInAppSentTo([$this->headOfficeHr], $request);
    }

    public function test_a_denial_does_not_notify_hr(): void
    {
        $request = $this->submitLeave($this->applicant);
        $this->workflow()->recommend($this->manager, $request->fresh(), null, true);
        $this->workflow()->finalDecision($this->chief, $request->fresh(), 'No', false);

        Mail::assertNotSent(LeaveHrNotificationMail::class);
        Mail::assertSent(LeaveDeniedMail::class, fn ($mail) => $mail->hasTo('emp101@example.com'));
        $this->assertHrInAppNotSentTo([$this->accraHr], $request);
    }

    public function test_with_no_hr_contact_configured_the_in_app_notice_still_goes_out_and_a_warning_is_logged(): void
    {
        LeaveHrContact::query()->where('region_id', $this->accra->id)->delete();
        Log::spy();

        $request = $this->approveTwoStage($this->applicant, $this->manager, $this->chief);

        Mail::assertNotSent(LeaveHrNotificationMail::class);
        $this->assertHrInAppSentTo([$this->accraHr], $request);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'no active HR contact'))->once();
        $this->assertSame('Approved', $request->fresh()->leave_status);
    }

    public function test_an_inactive_hr_contact_is_treated_as_not_configured(): void
    {
        LeaveHrContact::query()->where('region_id', $this->accra->id)->update(['is_active' => false]);
        Log::spy();

        $this->approveTwoStage($this->applicant, $this->manager, $this->chief);

        Mail::assertNotSent(LeaveHrNotificationMail::class);
        Log::shouldHaveReceived('warning')->once();
    }

    // ---------------------------------------------------------------- The other emails

    public function test_each_step_sends_its_email_to_the_right_people(): void
    {
        $secondManager = $this->staff('DM102', $this->temaDistrict, $this->operations, ['district_manager']);
        $secondChief = $this->staff('RCM103', $this->accraOffice, $this->finance, ['regional_chief_manager']);

        $request = $this->submitLeave($this->applicant);

        // The submission mail (never sent before) goes to every recommender.
        Mail::assertSent(LeaveSubmittedMail::class, 2);
        foreach ([$this->manager, $secondManager] as $recommender) {
            Mail::assertSent(LeaveSubmittedMail::class, fn ($mail) => $mail->hasTo($recommender->email));
        }

        $this->workflow()->recommend($this->manager, $request->fresh(), null, true);

        Mail::assertSent(LeaveRecommendedMail::class, 2);
        foreach ([$this->chief, $secondChief] as $approver) {
            Mail::assertSent(LeaveRecommendedMail::class, fn ($mail) => $mail->hasTo($approver->email));
        }

        $this->workflow()->finalDecision($this->chief, $request->fresh(), null, true);

        // The applicant hears, with the recommender copied.
        Mail::assertSent(LeaveApprovedMail::class, fn ($mail) => $mail->hasTo('emp101@example.com') && $mail->hasCc('dm101@example.com'));
        $this->assertInAppSentToStaff($this->applicant, 'leave_approved');
    }

    public function test_a_managers_rejection_notifies_the_applicant(): void
    {
        $request = $this->submitLeave($this->applicant);
        $this->workflow()->recommend($this->manager, $request->fresh(), 'No cover', false);

        Mail::assertSent(LeaveDeniedMail::class, fn ($mail) => $mail->hasTo('emp101@example.com'));
        Mail::assertNotSent(LeaveRecommendedMail::class);
        $this->assertInAppSentToStaff($this->applicant, 'leave_rejected');
    }

    public function test_a_single_stage_request_emails_the_final_approver_and_copies_nobody_on_approval(): void
    {
        $request = $this->submitLeave($this->manager);

        Mail::assertSent(LeaveSubmittedMail::class, 1);
        Mail::assertSent(LeaveSubmittedMail::class, fn ($mail) => $mail->hasTo('rcm101@example.com'));

        $this->workflow()->finalDecision($this->chief, $request->fresh(), null, true);

        Mail::assertSent(LeaveApprovedMail::class, fn ($mail) => $mail->hasTo('dm101@example.com') && ! $mail->hasCc('rcm101@example.com'));
    }

    // ---------------------------------------------------------------- Email switches

    public function test_no_email_is_sent_when_leave_emails_are_switched_off_but_in_app_notices_still_are(): void
    {
        config(['gwl.leave_email_notifications_enabled' => false]);

        $request = $this->approveTwoStage($this->applicant, $this->manager, $this->chief);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Everything in-app still happened: the manager, the chief, the applicant and HR.
        $this->assertInAppSentToStaff($this->manager, 'leave_submitted');
        $this->assertInAppSentToStaff($this->chief, 'leave_recommended');
        $this->assertInAppSentToStaff($this->applicant, 'leave_approved');
        $this->assertHrInAppSentTo([$this->accraHr], $request);
        $this->assertSame('Approved', $request->fresh()->leave_status);
    }

    public function test_a_denial_sends_no_email_when_switched_off_but_still_notifies_in_app(): void
    {
        config(['gwl.leave_email_notifications_enabled' => false]);

        $request = $this->submitLeave($this->applicant);
        $this->workflow()->recommend($this->manager, $request->fresh(), null, false);

        Mail::assertNothingSent();
        $this->assertInAppSentToStaff($this->applicant, 'leave_rejected');
    }

    public function test_the_hr_switch_stops_only_the_hr_email(): void
    {
        config(['gwl.leave_hr_email_notifications_enabled' => false]);

        $request = $this->approveTwoStage($this->applicant, $this->manager, $this->chief);

        Mail::assertNotSent(LeaveHrNotificationMail::class);
        Mail::assertSent(LeaveSubmittedMail::class);
        Mail::assertSent(LeaveRecommendedMail::class);
        Mail::assertSent(LeaveApprovedMail::class);
        $this->assertHrInAppSentTo([$this->accraHr], $request);
    }

    public function test_the_hr_switch_cannot_turn_hr_emails_on_while_the_master_switch_is_off(): void
    {
        config([
            'gwl.leave_email_notifications_enabled' => false,
            'gwl.leave_hr_email_notifications_enabled' => true,
        ]);

        $this->approveTwoStage($this->applicant, $this->manager, $this->chief);

        Mail::assertNothingSent();
    }

    public function test_the_email_switches_default_to_on_and_the_hr_one_follows_the_master(): void
    {
        $this->assertTrue(config('gwl.leave_email_notifications_enabled'));
        $this->assertTrue(config('gwl.leave_hr_email_notifications_enabled'));
    }

    // ---------------------------------------------------------------- Failures never break the workflow

    public function test_an_email_failure_is_logged_and_never_breaks_or_rolls_back_the_workflow(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));

        $request = $this->submitLeave($this->applicant);
        $this->assertSame('Pending Approval', $request->fresh()->leave_status);

        $this->workflow()->recommend($this->manager, $request->fresh(), null, true);
        $this->assertSame('Recommended', $request->fresh()->manager_recommendation);

        $this->workflow()->finalDecision($this->chief, $request->fresh(), 'Enjoy', true);

        // Approved and charged, in spite of every email failing.
        $request->refresh();
        $this->assertSame('Approved', $request->leave_status);
        $this->assertSame($this->chief->id, $request->approved_by_id);
        $this->assertSame(3, (int) LeaveBalance::query()->where('employee_id', $this->applicant->id)->value('used_days'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'SMTP is down'));

        // The in-app notices are not email: they still went out.
        $this->assertInAppSentToStaff($this->applicant, 'leave_approved');
        $this->assertHrInAppSentTo([$this->accraHr], $request);
    }

    public function test_an_email_failure_on_denial_does_not_undo_the_denial(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));

        $request = $this->submitLeave($this->applicant);
        $this->workflow()->recommend($this->manager, $request->fresh(), 'No cover', false);

        $this->assertSame('Denied', $request->fresh()->leave_status);
        $this->assertSame('Rejected', $request->fresh()->manager_recommendation);
    }

    // ---------------------------------------------------------------- helpers

    protected function contact(?int $regionId, string $email): LeaveHrContact
    {
        return LeaveHrContact::query()->create(['region_id' => $regionId, 'email' => $email, 'is_active' => true]);
    }

    protected function approveTwoStage(Employee $applicant, Employee $manager, Employee $chief): LeaveRequest
    {
        $request = $this->submitLeave($applicant);
        $this->workflow()->recommend($manager, $request->fresh(), 'Covered', true);
        $this->workflow()->finalDecision($chief, $request->fresh(), 'Enjoy', true);

        return $request->fresh();
    }

    protected function staffByStaffId(string $staffId): Employee
    {
        return Employee::query()->where('staff_id', $staffId)->firstOrFail();
    }

    /** @param  list<Employee>  $recipients */
    protected function assertHrInAppSentTo(array $recipients, LeaveRequest $request): void
    {
        foreach ($recipients as $employee) {
            Notification::assertSentTo(
                $this->userOf($employee),
                GeneralDatabaseNotification::class,
                fn ($notification) => $this->isHrNotice($notification, $employee, $request)
            );
        }
    }

    /** @param  list<Employee>  $recipients */
    protected function assertHrInAppNotSentTo(array $recipients, LeaveRequest $request): void
    {
        foreach ($recipients as $employee) {
            Notification::assertNotSentTo(
                $this->userOf($employee),
                GeneralDatabaseNotification::class,
                fn ($notification) => $this->isHrNotice($notification, $employee, $request)
            );
        }
    }

    protected function isHrNotice(GeneralDatabaseNotification $notification, Employee $employee, LeaveRequest $request): bool
    {
        $data = $notification->toArray($this->userOf($employee));

        return ($data['type'] ?? null) === 'leave_hr_approved' && ($data['leave_request_id'] ?? null) === $request->id;
    }

    protected function assertInAppSentToStaff(Employee $employee, string $type): void
    {
        Notification::assertSentTo(
            $this->userOf($employee),
            GeneralDatabaseNotification::class,
            fn ($notification) => ($notification->toArray($this->userOf($employee))['type'] ?? null) === $type
        );
    }
}
