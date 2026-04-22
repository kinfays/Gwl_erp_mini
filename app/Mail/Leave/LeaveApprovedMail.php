<?php

namespace App\Mail\Leave;

use App\Models\LeaveRequest;
use Illuminate\Queue\SerializesModels;
use App\Mail\Leave\LeaveSubmittedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailable;


class LeaveApprovedMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public LeaveRequest $leaveRequest
    ) {}

    public function build()
    {
        return $this->subject('Leave Approved')
            ->view('emails.leave.approved');
                
// after update()
if ($manager?->email) {
    Mail::to($manager->email)
        ->send(new LeaveSubmittedMail($request));
}

    }

}