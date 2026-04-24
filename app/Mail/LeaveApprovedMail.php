<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use Illuminate\Mail\Mailable;

class LeaveApprovedMail extends Mailable
{
    public function __construct(
        public LeaveRequest $request,
        public ?LeaveBalance $balance
    ) {}

    public function build()
    {
        return $this
            ->subject('Leave Request Approved')
            ->markdown('emails.leave.approved');
    }
}