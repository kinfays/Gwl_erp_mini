<?php

namespace App\Services\Leave;

use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveRecommendedMail;
use App\Mail\LeaveSubmittedMail;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use Illuminate\Support\Facades\Mail;

class LeaveNotificationService
{
    public function submitted(LeaveRequest $req): void
    {
        // To direct manager
        Mail::to($req->manager->email)->send(new LeaveSubmittedMail($req));
    }

    public function recommended(LeaveRequest $req, string $chiefEmail): void
    {
        Mail::to($chiefEmail)->send(new LeaveRecommendedMail($req));
    }

    public function approved(LeaveRequest $req, string $hrEmail, array $ccEmails = [], ?LeaveBalance $balance = null): void
    {
        Mail::to($hrEmail)
            ->cc($ccEmails)
            ->send(new LeaveApprovedMail($req, $balance));
    }
}