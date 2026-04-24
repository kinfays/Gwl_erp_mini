<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveRequest $request,
        public ?LeaveBalance $balance
    ) {}

    public function build()
    {
        return $this->subject('Leave Request Approved')
            ->markdown('emails.leave.approved');
    }
}