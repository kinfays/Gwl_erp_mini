<?php

namespace App\Jobs\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerDistrictBusy;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs (or resumes) one customer-list import. It carries only the batch id: no file name, no customer data. The pipeline
 * stores its own progress on the batch, so a retry carries on where the last attempt stopped.
 */
class ProcessCustomerListBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Large files take a while; the queue worker's own --timeout must be at least this. */
    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $batchId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(CustomerImportService $imports): void
    {
        $batch = CommercialCustomerBatch::query()->find($this->batchId);

        if (! $batch) {
            return;
        }

        try {
            $imports->process($batch);
        } catch (CustomerDistrictBusy) {
            $this->release(60);
        } catch (CustomerImportException) {
            // Already recorded on the batch (failed, with a safe message); a retry resumes from the stored step.
            throw new \RuntimeException('Customer import failed: see batch #'.$this->batchId.'.');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $batch = CommercialCustomerBatch::query()->find($this->batchId);

        if ($batch && ! in_array($batch->status, [CommercialCustomerBatch::STATUS_IMPORTED, CommercialCustomerBatch::STATUS_BLOCKED, CommercialCustomerBatch::STATUS_VOIDED], true)) {
            $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_FAILED, 'error_message' => $batch->error_message ?: 'The import job failed and gave up. Run it again from the batch page.', 'finished_at' => now()])->save();
        }
    }
}
