<?php

namespace App\Jobs\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Models\User;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerImportException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Voids (and exactly rolls back) the newest customer upload of a district, off the request: it can touch millions of rows. */
class VoidCustomerListBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    public function __construct(public readonly int $batchId, public readonly int $userId, public readonly string $reason) {}

    public function handle(CustomerBatchLifecycle $lifecycle): void
    {
        $batch = CommercialCustomerBatch::query()->find($this->batchId);
        $user = User::query()->find($this->userId);

        if (! $batch || ! $user || $batch->isVoided()) {
            return;
        }

        try {
            $lifecycle->void($batch, $user, $this->reason);
        } catch (CustomerImportException $exception) {
            // Not voidable any more (a newer upload arrived meanwhile): nothing was changed. The batch page shows why.
            $batch->forceFill(['phase' => CommercialCustomerBatch::PHASE_DONE, 'error_message' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }
}
