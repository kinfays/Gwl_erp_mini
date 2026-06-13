<?php

namespace App\Listeners\Transport;

use App\Events\Transport\MaintenanceDue;
use App\Services\Transport\TransportNotificationService;

class NotifyTransportManagersOfMaintenanceDue
{
    public function __construct(protected TransportNotificationService $notifications) {}

    public function handle(MaintenanceDue $event): void
    {
        $vehicle = $event->vehicle;

        $this->notifications->notifyManagers(
            'Vehicle maintenance due',
            sprintf(
                '%s is within %s km of its next maintenance point.',
                $vehicle->number_plate,
                number_format($vehicle->maintenance_remaining_km)
            ),
            route('transport.maintenance'),
            [
                'type' => 'maintenance_due',
                'vehicle_id' => $vehicle->id,
            ]
        );
    }
}
