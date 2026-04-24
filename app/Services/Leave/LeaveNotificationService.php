<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Mail\{
    LeaveSubmittedMail,
    LeaveRecommendedMail,
    LeaveApprovedMail,
    LeaveDeniedMail
};
use Illuminate\Support\Facades\Mail;

class LeaveNotificationService
{
    public function submitted(LeaveRequest $req): void
    {
        Mail::to($req->manager->email)
            ->send(new LeaveSubmittedMail($req));
    }

    public function recommended(LeaveRequest $req, string $chiefEmail): void
    {
        Mail::to($chiefEmail)
            ->send(new LeaveRecommendedMail($req));
    }

    public function approved(LeaveRequest $req, LeaveBalance $balance, array $hrEmails): void
    {
        Mail::to($hrEmails)
            ->cc([
                $req->requester->email,
                $req->manager->email,
            ])
            ->send(new LeaveApprovedMail($req, $balance));
    }

    public function denied(LeaveRequest $req): void
    {
        Mail::to($req->requester->email)
            ->send(new LeaveDeniedMail($req));
    }
}