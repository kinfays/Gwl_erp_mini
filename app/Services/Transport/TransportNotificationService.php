<?php

namespace App\Services\Transport;

use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Facades\Schema;

class TransportNotificationService
{
    public function notifyManagers(string $title, string $message, ?string $url = null, array $meta = [], bool $sendMail = false): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'transport_manager']))
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
