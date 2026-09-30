<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;

/**
 * Tells the HR contact for the applicant's scope that leave has been finally approved.
 */
class LeaveHrNotificationMail extends Mailable
{
    public function __construct(
        public LeaveRequest $request,
        public string $scopeLabel,
    ) {}

    public function build()
    {
        $request = $this->request->loadMissing(['requester.department', 'requester.region', 'requester.district', 'manager', 'approvedBy']);

        // Two-stage: the recommender and the final approver. Single-stage: only the final approver.
        $approvers = collect();

        if (! $request->is_single_stage && $request->manager) {
            $approvers->push(['role' => 'Recommended by', 'name' => $request->manager->full_name]);
        }

        if ($request->approvedBy) {
            $approvers->push(['role' => 'Approved by', 'name' => $request->approvedBy->full_name]);
        }

        return $this
            ->subject('Leave approved: '.$request->requester->full_name.' ('.$this->scopeLabel.')')
            ->markdown('emails.leave.hr-notice', ['approvers' => $approvers]);
    }
}
