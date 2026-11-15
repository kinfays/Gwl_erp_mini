<?php

namespace App\Jobs\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\CommercialImportException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs (or re-runs) one Detailed Billing Report upload (design 8.39). It carries only the batch id: no file name, no account, no figure. The
 * service clears what an earlier try wrote and starts again, so a retry is always safe; a failure leaves the batch FAILED with a safe message
 * on its page, from where it can be run again or discarded.
 *
 * The queue worker's own --timeout must be at least $timeout, and the connection's retry_after must be longer than it (config/queue.php:
 * DB_QUEUE_RETRY_AFTER defaults to 90 seconds, far too short for a 130 MB file; see the limits in the design doc).
 */
class ProcessBillingDetailsBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** A 133 MB sheet takes minutes, not seconds; the queue worker's --timeout must be at least this. */
    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $batchId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(BillingDetailsImportService $imports): void
    {
        $batch = CommercialImportBatch::query()->find($this->batchId);

        if (! $batch) {
            return;
        }

        try {
            $imports->process($batch);
        } catch (CommercialImportException) {
            // Already recorded on the batch (failed, with a safe message); a retry starts again from the stored file.
            throw new \RuntimeException('Detailed billing import failed: see batch #'.$this->batchId.'.');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $batch = CommercialImportBatch::query()->find($this->batchId);

        if ($batch && in_array($batch->status, CommercialImportBatch::IN_FLIGHT, true)) {
            $batch->forceFill(['status' => CommercialImportBatch::STATUS_FAILED, 'phase' => 'failed', 'error_message' => $batch->error_message ?: 'The import job failed and gave up. Run it again from the upload\'s page.', 'finished_at' => now()])->save();
        }
    }
}
