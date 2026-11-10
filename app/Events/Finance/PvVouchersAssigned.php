<?php

namespace App\Events\Finance;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the commit when the Audit manager gave SEVERAL loose vouchers to one officer at once (one voucher raises
 * PaymentVoucherActioned instead). Ids only.
 */
class PvVouchersAssigned
{
    use Dispatchable;

    /** @param array<int, int> $pvIds */
    public function __construct(
        public int $officerId,
        public array $pvIds,
        public int $actorId,
    ) {}
}
