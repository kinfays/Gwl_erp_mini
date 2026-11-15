<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Housekeeping for the Detailed Billing Report (Phase 8d, design 8.39):
 *
 *   1. the ACCOUNT lists (commercial_billing_detail_issues) older than commercial_billing_detail_issue_keep_months are deleted: they hold
 *      account numbers and must not live for ever. The rollups and bands (figures, no customer) follow their batch and are kept;
 *   2. staging rows (commercial_billing_detail_accounts) of a batch that is no longer running or waiting for its check, or whose batch is gone;
 *   3. a run that is still "queued" or "processing" a day after it last moved (its worker died) is marked failed, so a person can run it again
 *      or discard it, and its stale staging rows go.
 */
class PurgeBillingDetails extends Command
{
    protected $signature = 'commercial:billing-details:purge {--dry-run : Count what would go and delete nothing}';

    protected $description = 'Delete old Detailed Billing account lists, orphaned staging rows and mark runs that never finished as failed';

    public function handle(): int
    {
        if (! config('gwl.commercial_module_enabled')) {
            $this->info('The Commercial module is switched off.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $keep = max(1, (int) config('gwl.commercial_billing_detail_issue_keep_months', 3));

        // 3. runs that never finished (done first, so their staging rows are orphans below)
        $stuck = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->whereIn('status', CommercialImportBatch::IN_FLIGHT)->where('updated_at', '<', now()->subDay())->get();

        if (! $dry) {
            foreach ($stuck as $batch) {
                $batch->forceFill(['status' => CommercialImportBatch::STATUS_FAILED, 'phase' => 'failed', 'error_message' => 'The run did not finish (its worker stopped). Run it again, or discard it.', 'finished_at' => now()])->save();
            }
        }

        // 2. staging rows that nobody will use
        $live = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->whereIn('status', [...CommercialImportBatch::IN_FLIGHT, CommercialImportBatch::STATUS_AWAITING_REVIEW])->pluck('id')->all();
        $orphans = DB::table('commercial_billing_detail_accounts')->when($live !== [], fn ($q) => $q->whereNotIn('batch_id', $live))->count();

        // 1. the account lists past their keep period
        $cutoff = now()->subMonths($keep);
        $lists = DB::table('commercial_billing_detail_issues')->where('created_at', '<', $cutoff)->count();

        if (! $dry) {
            DB::table('commercial_billing_detail_accounts')->when($live !== [], fn ($q) => $q->whereNotIn('batch_id', $live))->delete();
            DB::table('commercial_billing_detail_issues')->where('created_at', '<', $cutoff)->delete();

            // a stored workbook of a run that is over (loaded or discarded) is never needed again
            foreach (CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->whereIn('status', [CommercialImportBatch::STATUS_IMPORTED, CommercialImportBatch::STATUS_SUPERSEDED, CommercialImportBatch::STATUS_VOIDED])->whereNotNull('file_path')->get() as $batch) {
                Storage::disk('local')->delete($batch->file_path);
                $batch->forceFill(['file_path' => null])->save();
            }
        }

        $this->info(sprintf('%s %d account-list row(s) older than %d month(s), %d orphaned staging row(s); %d run(s) that never finished %s.', $dry ? 'Would delete' : 'Deleted', $lists, $keep, $orphans, $stuck->count(), $dry ? 'would be marked failed' : 'marked failed'));

        return self::SUCCESS;
    }
}
