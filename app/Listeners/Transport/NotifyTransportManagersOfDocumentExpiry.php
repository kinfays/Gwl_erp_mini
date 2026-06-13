<?php

namespace App\Listeners\Transport;

use App\Events\Transport\DocumentExpiryDetected;
use App\Services\Transport\TransportNotificationService;

class NotifyTransportManagersOfDocumentExpiry
{
    public function __construct(protected TransportNotificationService $notifications) {}

    public function handle(DocumentExpiryDetected $event): void
    {
        $this->notifications->notifyManagers(
            'Vehicle document expiring',
            sprintf(
                '%s %s expires in %d day(s).',
                $event->vehicle->number_plate,
                str_replace('_', ' ', $event->documentType),
                $event->daysUntilExpiry
            ),
            route('transport.home'),
            [
                'type' => 'document_expiry',
                'vehicle_id' => $event->vehicle->id,
                'document_type' => $event->documentType,
            ]
        );
    }
}
