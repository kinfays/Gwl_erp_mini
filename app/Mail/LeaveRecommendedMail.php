<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;

class LeaveRecommendedMail extends Mailable
{
    public function __construct(public LeaveRequest $request) {}

    public function build()
    {
        return $this
            ->subject('Leave Request Recommended')
            ->markdown('emails.leave.recommended');
    }
}