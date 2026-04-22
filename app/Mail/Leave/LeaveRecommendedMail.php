<?php

namespace App\Mail\Leave;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRecommendedMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public LeaveRequest $leaveRequest
    ) {}

    public function build()
    {
        return $this->subject('Leave Request Recommended')
            ->view('emails.leave.recommended');
    }
}
