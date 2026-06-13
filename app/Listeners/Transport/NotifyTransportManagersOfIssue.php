<?php

namespace App\Listeners\Transport;

use App\Events\Transport\VehicleIssueReported;
use App\Services\Transport\TransportNotificationService;

class NotifyTransportManagersOfIssue
{
    public function __construct(protected TransportNotificationService $notifications) {}

    public function handle(VehicleIssueReported $event): void
    {
        $issue = $event->issue->loadMissing(['vehicle', 'reporter']);
        $vehicle = $issue->vehicle;

        $this->notifications->notifyManagers(
            'Vehicle issue reported',
            sprintf(
                '%s reported a %s issue on %s.',
                $issue->reporter?->full_name ?? $issue->reporter?->email ?? 'A driver',
                str_replace('_', ' ', $issue->severity),
                $vehicle?->number_plate ?? 'a vehicle'
            ),
            route('transport.issues'),
            [
                'type' => 'vehicle_issue_reported',
                'issue_id' => $issue->id,
                'vehicle_id' => $vehicle?->id,
            ],
            true
        );
    }
}
