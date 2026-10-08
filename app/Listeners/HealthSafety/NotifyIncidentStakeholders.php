<?php

namespace App\Listeners\HealthSafety;

use App\Events\HealthSafety\IncidentReported;
use App\Events\HealthSafety\IncidentSeverityRaised;
use App\Events\HealthSafety\IncidentStatusChanged;
use App\Models\HsIncident;
use App\Services\HealthSafety\IncidentNotificationService;

/** Turns the incident events into notices; who gets which is decided in IncidentNotificationService. */
class NotifyIncidentStakeholders
{
    public function __construct(protected IncidentNotificationService $notifications) {}

    public function onReported(IncidentReported $event): void
    {
        $this->notifications->newReport($event->incident);
    }

    public function onSeverityRaised(IncidentSeverityRaised $event): void
    {
        $this->notifications->severityRaised($event->incident);
    }

    public function onStatusChanged(IncidentStatusChanged $event): void
    {
        match ($event->to) {
            HsIncident::STATUS_PENDING_CLOSURE => $this->notifications->approvalRequested($event->incident),
            HsIncident::STATUS_CLOSED => $this->notifications->closed($event->incident),
            HsIncident::STATUS_CANCELLED => $this->notifications->cancelled($event->incident),
            default => null,
        };
    }
}
