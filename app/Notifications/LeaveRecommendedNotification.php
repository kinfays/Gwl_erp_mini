<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRecommendedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'leave_recommended',
            'leave_request_id' => $this->leaveRequest->id,
            'requester_id' => $this->leaveRequest->requester_id,
            'requester_name' => $this->leaveRequest->requester?->full_name,
            'manager_id' => $this->leaveRequest->manager_id,
            'manager_name' => $this->leaveRequest->manager?->full_name,
            'leave_type' => $this->leaveRequest->leave_type?->value ?? (string) $this->leaveRequest->leave_type,
            'start_date' => $this->leaveRequest->start_date?->toDateString(),
            'end_date' => $this->leaveRequest->end_date?->toDateString(),
            'total_days_applied' => $this->leaveRequest->total_days_applied,
            'message' => sprintf(
                '%s recommended a leave request for %s.',
                $this->leaveRequest->manager?->full_name ?? 'A manager',
                $this->leaveRequest->requester?->full_name ?? 'an employee'
            ),
        ];
    }
}
