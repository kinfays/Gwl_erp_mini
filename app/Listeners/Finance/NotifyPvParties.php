<?php

namespace App\Listeners\Finance;

use App\Events\Finance\PaymentVoucherActioned;
use App\Services\Finance\PvNotificationService;

class NotifyPvParties
{
    public function __construct(protected PvNotificationService $notifications) {}

    public function handle(PaymentVoucherActioned $event): void
    {
        $this->notifications->actioned($event->pvId, $event->action, $event->actorId);
    }
}
