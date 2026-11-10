<?php

namespace App\Events\Finance;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by BatchWorkflowService AFTER the transaction commits when a batch is sent to Audit (`sent`) or given to an Audit officer
 * (`assigned`). Ids, the action and a count only: the listener looks the batch up again and never reads a payee, amount or comment.
 */
class PvBatchActioned
{
    use Dispatchable;

    public function __construct(
        public int $batchId,
        public string $action,
        public int $actorId,
        public ?int $officerId = null,
        public int $count = 0,
    ) {}
}
