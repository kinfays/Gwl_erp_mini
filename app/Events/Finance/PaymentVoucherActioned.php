<?php

namespace App\Events\Finance;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by PaymentVoucherWorkflowService AFTER the transaction commits, once per workflow action. It carries ids and the
 * action only (never the payee, amount or a comment); the listener looks the voucher up again.
 */
class PaymentVoucherActioned
{
    use Dispatchable;

    public function __construct(
        public int $pvId,
        public string $action,
        public int $round,
        public int $actorId,
    ) {}
}
