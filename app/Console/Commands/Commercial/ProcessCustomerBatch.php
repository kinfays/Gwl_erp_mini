<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Console\Command;

/** Runs (or resumes) a customer-list batch in the foreground: for operators, and for loads too big to wait on the queue. */
class ProcessCustomerBatch extends Command
{
    protected $signature = 'commercial:customers:process {batch : The customer batch id}';

    protected $description = 'Run or resume a Commercial customer-list import in the foreground';

    public function handle(CustomerImportService $imports): int
    {
        if (! config('gwl.commercial_module_enabled') || ! config('gwl.commercial_customer_list_enabled')) {
            $this->warn('The Commercial customer list is switched off.');

            return self::SUCCESS;
        }

        $batch = CommercialCustomerBatch::query()->find((int) $this->argument('batch'));

        if (! $batch) {
            $this->error('No such batch.');

            return self::FAILURE;
        }

        $batch = $imports->process($batch, fn (CommercialCustomerBatch $b) => $this->output->isVerbose() ? $this->line("phase {$b->phase}: staged through row {$b->staged_through_row}, merged to {$b->merge_cursor}") : null);

        $this->info("Batch #{$batch->id}: {$batch->status} ({$batch->rows_read} rows, {$batch->rows_new} new, {$batch->rows_changed} changed, {$batch->rows_missing} not in file).");

        return in_array($batch->status, [CommercialCustomerBatch::STATUS_IMPORTED, CommercialCustomerBatch::STATUS_NEEDS_MATCH], true) ? self::SUCCESS : self::FAILURE;
    }
}
