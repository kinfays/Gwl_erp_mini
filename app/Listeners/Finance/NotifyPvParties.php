<?php

namespace App\Listeners\Finance;

use App\Events\Finance\PaymentVoucherActioned;
use App\Events\Finance\PvBatchActioned;
use App\Events\Finance\PvVouchersAssigned;
use App\Services\Finance\PvNotificationService;

/**
 * Found by Laravel's event discovery (every public handle* method, keyed by its first parameter's type): never register it in
 * AppServiceProvider as well, or every notice is sent twice.
 */
class NotifyPvParties
{
    public function __construct(protected PvNotificationService $notifications) {}

    public function handle(PaymentVoucherActioned $event): void
    {
        $this->notifications->actioned($event->pvId, $event->action, $event->actorId);
    }

    public function handleBatch(PvBatchActioned $event): void
    {
        $this->notifications->batchActioned($event->batchId, $event->action, $event->actorId, $event->officerId, $event->count);
    }

    public function handleAssigned(PvVouchersAssigned $event): void
    {
        $this->notifications->vouchersAssigned($event->officerId, $event->pvIds, $event->actorId);
    }
}
