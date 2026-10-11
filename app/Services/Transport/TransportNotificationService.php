<?php

namespace App\Services\Transport;

use App\Models\Vehicle;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Facades\Schema;

class TransportNotificationService
{
    public function __construct(protected TransportScope $scope) {}

    /**
     * Tells the managers of the vehicle's location (and super_admin, who reaches every vehicle) about it. A transport
     * manager elsewhere is never told; an unplaced vehicle reaches super_admin only. TransportScope decides who.
     */
    public function notifyManagers(Vehicle $vehicle, string $title, string $message, ?string $url = null, array $meta = [], bool $sendMail = false): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $this->scope->managersFor($vehicle)
            ->chunkById(100, function ($users) use ($title, $message, $url, $meta, $sendMail): void {
                foreach ($users as $user) {
                    $user->notify(new GeneralDatabaseNotification(
                        $title,
                        $message,
                        $url,
                        'transport',
                        $meta,
                        $sendMail
                    ));
                }
            });
    }
}
