<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRecommendedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LeaveRequest $request) {}

    public function build()
    {
        return $this->subject('Leave Request Recommended')
            ->markdown('emails.leave.recommended');
    }
}