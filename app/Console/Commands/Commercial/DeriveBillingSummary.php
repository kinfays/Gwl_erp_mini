<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingSummaryFromDetails;
use Illuminate\Console\Command;

/**
 * Rebuilds the billing summaries derived from Detailed Billing Reports (Phase 8h, design 8.52). Safe to run again: a derived summary is rebuilt in
 * place, one that has lost its source is removed, a live source without one gets one. It prints COUNTS only (never a route, a district or a figure).
 *
 *   php artisan commercial:billing-summary:derive --batch=12     one detailed batch (or the derived batch built from it)
 *   php artisan commercial:billing-summary:derive --all          every live detailed batch
 */
class DeriveBillingSummary extends Command
{
    protected $signature = 'commercial:billing-summary:derive {--batch= : The id of a detailed billing batch (or of its derived summary)} {--all : Rebuild every derived summary}';

    protected $description = 'Rebuild the billing summary derived from the Detailed Billing Report (one batch, or all of them)';

    public function handle(BillingSummaryFromDetails $derive): int
    {
        if (! config('gwl.commercial_module_enabled')) {
            $this->info('The Commercial module is switched off.');

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $out = $derive->refreshAll();
            $this->info("Rebuilt {$out['rebuilt']}, built {$out['built']} more, removed {$out['removed']}.");

            return self::SUCCESS;
        }

        $id = $this->option('batch');

        if ($id === null || ! ctype_digit((string) $id)) {
            $this->error('Give --batch=<id> or --all.');

            return self::FAILURE;
        }

        $batch = CommercialImportBatch::query()->find((int) $id);

        if ($batch === null || ! ($batch->isBillingDetails() || $batch->isDerived())) {
            $this->error('That is not a detailed billing batch or a summary derived from one.');

            return self::FAILURE;
        }

        $derived = $derive->refreshBatch($batch->id);
        $this->info($derived ? 'Rebuilt 1 derived summary ('.$derived->routes()->count().' routes).' : 'Nothing to derive: that detailed batch is not live (voided, superseded or still running), so its derived summary was removed if there was one.');

        return self::SUCCESS;
    }
}
