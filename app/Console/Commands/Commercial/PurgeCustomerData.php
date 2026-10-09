<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Housekeeping for the personal data the customer list handles:
 *
 *   1. leftover staging rows and uploaded workbooks of batches that did not finish (failed / parked for a match / queued for
 *      more than a week), which hold names and phone numbers;
 *   2. finished background exports older than a week;
 *   3. OPTIONAL retention: with commercial_customer_contact_retention_days > 0, the contact details (name, address, phones,
 *      e-mail) of accounts that have been missing from the files for that many days are deleted. The account row itself and
 *      its figures stay. 0 (the default) deletes nothing.
 */
class PurgeCustomerData extends Command
{
    protected $signature = 'commercial:customers:purge {--dry-run : Count what would go and delete nothing}';

    protected $description = 'Delete stale customer-list staging data, old export files and (if switched on) contact details past retention';

    public function handle(): int
    {
        if (! config('gwl.commercial_module_enabled') || ! config('gwl.commercial_customer_list_enabled')) {
            $this->info('The Commercial customer list is switched off.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $week = now()->subDays(7);

        // 1. unfinished batches
        $stale = CommercialCustomerBatch::query()
            ->whereIn('status', [CommercialCustomerBatch::STATUS_FAILED, CommercialCustomerBatch::STATUS_NEEDS_MATCH, CommercialCustomerBatch::STATUS_QUEUED])
            ->where('updated_at', '<', $week)->get();

        foreach ($stale as $batch) {
            if (! $dry) {
                CustomerMergeService::clearStaging($batch->id);

                if ($batch->file_path) {
                    Storage::disk('local')->delete($batch->file_path);
                    $batch->forceFill(['file_path' => null, 'status' => $batch->status === CommercialCustomerBatch::STATUS_NEEDS_MATCH ? CommercialCustomerBatch::STATUS_FAILED : $batch->status, 'error_message' => $batch->error_message ?: 'The file was deleted after a week without being finished: upload it again.'])->save();
                }
            }
        }

        $this->line($stale->count().' unfinished batch(es): their staged rows and files '.($dry ? 'would be' : 'were').' deleted.');

        // 2. old export files
        $exports = 0;

        foreach (Storage::disk('local')->files('commercial/customer-exports') as $file) {
            if (Storage::disk('local')->lastModified($file) < $week->timestamp) {
                $exports++;

                if (! $dry) {
                    Storage::disk('local')->delete($file);
                    Cache::forget('commercial-customer-export:'.basename($file, '.xlsx'));
                }
            }
        }

        $this->line($exports.' old export file(s) '.($dry ? 'would be' : 'were').' deleted.');

        // 3. optional contact retention
        $days = (int) config('gwl.commercial_customer_contact_retention_days', 0);

        if ($days <= 0) {
            $this->line('Contact retention is off (commercial_customer_contact_retention_days = 0): no contact details deleted.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $contacts = DB::table('commercial_customer_contacts AS ct')
            ->join('commercial_customers AS c', 'c.id', '=', 'ct.customer_id')
            ->join('commercial_customer_batches AS b', 'b.id', '=', 'c.missing_since_batch_id')
            ->where('b.finished_at', '<', $cutoff);

        $count = (clone $contacts)->count();

        if (! $dry && $count > 0) {
            foreach (array_chunk((clone $contacts)->pluck('ct.customer_id')->all(), 5000) as $ids) {
                DB::table('commercial_customer_contacts')->whereIn('customer_id', $ids)->delete();
            }
        }

        $this->line($count.' contact record(s) of accounts missing for more than '.$days.' days '.($dry ? 'would be' : 'were').' deleted.');

        return self::SUCCESS;
    }
}
