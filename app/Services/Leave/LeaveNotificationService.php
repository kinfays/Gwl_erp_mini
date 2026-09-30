<?php

namespace App\Services\Leave;

use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveDeniedMail;
use App\Mail\LeaveHrNotificationMail;
use App\Mail\LeaveRecommendedMail;
use App\Mail\LeaveSubmittedMail;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Every notification the leave workflow sends: the in-app (bell) notice and the email for each event.
 *
 * Emails are switched off centrally here (config gwl.leave_email_notifications_enabled, and
 * gwl.leave_hr_email_notifications_enabled for the HR notice) and in-app notices are never affected.
 * Nothing in here may break the workflow: it runs after the state change has been committed, and a failure
 * to email or notify one recipient — an SMTP outage, say — is logged and skipped.
 */
class LeaveNotificationService
{
    public function __construct(
        protected LeaveApprovalChainResolver $chain,
        protected LeaveHrContactService $hrContacts,
    ) {}

    /** A request has been submitted (or resubmitted): tell everyone who can act on it now. */
    public function submitted(LeaveRequest $req): void
    {
        $stage = $this->chain->stageOf($req);

        if ($stage === null) {
            return;
        }

        $approvers = $this->chain->eligible($req, $stage);
        $final = $stage === LeaveApprovalChainResolver::STAGE_FINAL;

        $this->inApp(
            $approvers,
            $final ? 'Final leave approval needed' : 'Leave approval needed',
            $req->requester->full_name.' submitted a '.$req->leave_type.' leave request.',
            route('leave.approvals'),
            ['type' => 'leave_submitted', 'leave_request_id' => $req->id]
        );

        $this->email($approvers->pluck('email'), fn () => new LeaveSubmittedMail($req), 'submitted', $req);
    }

    /** A manager recommended the request: tell everyone who can give the final approval. */
    public function recommended(LeaveRequest $req): void
    {
        $approvers = $this->chain->eligible($req, LeaveApprovalChainResolver::STAGE_FINAL);

        $this->inApp(
            $approvers,
            'Final leave approval needed',
            $req->requester->full_name."'s leave request has been recommended.",
            route('leave.approvals'),
            ['type' => 'leave_recommended', 'leave_request_id' => $req->id]
        );

        $this->email($approvers->pluck('email'), fn () => new LeaveRecommendedMail($req), 'recommended', $req);
    }

    /** The request was refused — by the manager ($byManager) or by the final approver. Tells the applicant. */
    public function denied(LeaveRequest $req, bool $byManager = false): void
    {
        $this->inApp(
            collect([$this->chain->userOf($req->requester)])->filter(),
            $byManager ? 'Leave request rejected' : 'Leave request denied',
            'Your '.$req->leave_type.' leave request was '.($byManager ? 'rejected by your manager.' : 'denied.'),
            route('leave.my-history'),
            ['type' => $byManager ? 'leave_rejected' : 'leave_denied', 'leave_request_id' => $req->id]
        );

        $this->email([$req->requester->email], fn () => new LeaveDeniedMail($req), 'denied', $req);
    }

    /** Final approval: tell the applicant (copying who recommended it), then HR. */
    public function approved(LeaveRequest $req, ?LeaveBalance $balance): void
    {
        $this->inApp(
            collect([$this->chain->userOf($req->requester)])->filter(),
            'Leave request approved',
            'Your '.$req->leave_type.' leave request was approved.',
            route('leave.my-history'),
            ['type' => 'leave_approved', 'leave_request_id' => $req->id]
        );

        $recommender = $req->is_single_stage ? null : $req->manager;

        $this->email(
            [$req->requester->email],
            fn () => (new LeaveApprovedMail($req, $balance))->cc(array_filter([$recommender?->email])),
            'approved',
            $req
        );

        $this->hr($req);
    }

    /**
     * HR of the applicant's scope hears about the approval: every active HR user of that scope in-app, and the
     * configured contact address by email. With no contact set up the in-app notice still goes out.
     */
    protected function hr(LeaveRequest $req): void
    {
        try {
            $regionId = $this->hrContacts->scopeFor($req->requester);
            $scopeLabel = $regionId === null ? 'Head Office' : (Region::query()->whereKey($regionId)->value('region_name') ?? 'Region');

            $this->inApp(
                $this->hrContacts->hrUsersFor($regionId),
                'Leave approved',
                sprintf(
                    '%s (%s): %s leave, %s to %s, %d working day(s).',
                    $req->requester->full_name,
                    $scopeLabel,
                    $req->leave_type,
                    $req->start_date->format('d M Y'),
                    $req->end_date->format('d M Y'),
                    $req->total_days_applied
                ),
                route('leave.requests'),
                ['type' => 'leave_hr_approved', 'leave_request_id' => $req->id]
            );

            if (! $this->emailsEnabled(hr: true)) {
                return;
            }

            $contact = $this->hrContacts->contactFor($regionId);

            if (! $contact) {
                Log::warning('Leave approved but no active HR contact is configured for '.$scopeLabel.'; HR email skipped.', [
                    'leave_request_id' => $req->id,
                    'region_id' => $regionId,
                ]);

                return;
            }

            $this->email([$contact->email], fn () => new LeaveHrNotificationMail($req, $scopeLabel), 'hr_approved', $req, hr: true);
        } catch (Throwable $e) {
            Log::error('Leave HR notification failed: '.$e->getMessage(), ['leave_request_id' => $req->id, 'exception' => $e]);
        }
    }

    public function emailsEnabled(bool $hr = false): bool
    {
        return (bool) config('gwl.leave_email_notifications_enabled', true)
            && (! $hr || (bool) config('gwl.leave_hr_email_notifications_enabled', true));
    }

    /**
     * One email per address, each with a fresh mailable (a reused one would accumulate recipients), each in
     * its own try/catch so one bad address or an SMTP failure neither skips the rest nor reaches the workflow.
     *
     * @param  iterable<int, string|null>  $addresses
     * @param  Closure(): \Illuminate\Mail\Mailable  $mailable
     */
    protected function email(iterable $addresses, Closure $mailable, string $event, LeaveRequest $req, bool $hr = false): void
    {
        if (! $this->emailsEnabled($hr)) {
            return;
        }

        foreach (collect($addresses)->filter()->unique() as $address) {
            try {
                Mail::to($address)->send($mailable());
            } catch (Throwable $e) {
                Log::error('Leave email failed ('.$event.'): '.$e->getMessage(), [
                    'leave_request_id' => $req->id,
                    'recipient' => $address,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<string, mixed>  $meta
     */
    protected function inApp(Collection $users, string $title, string $message, string $url, array $meta): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        foreach ($users->unique('id') as $user) {
            try {
                $user->notify(new GeneralDatabaseNotification($title, $message, $url, 'leave', $meta));
            } catch (Throwable $e) {
                Log::error('Leave in-app notification failed: '.$e->getMessage(), ['user_id' => $user->id, 'exception' => $e]);
            }
        }
    }
}
