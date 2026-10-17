<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerConsumptionHistory;
use App\Services\Commercial\Customers\CustomerLookups;
use Illuminate\Console\Command;

/**
 * Starts the consumption history from what is already loaded: files the current state of each district's newest upload under that
 * upload's month. Uploads record themselves from now on, so this is only needed once, for districts uploaded before the history
 * existed (it saves waiting one more upload for the first month). Safe to repeat: the month's rows are replaced, not added to.
 * It does not rebuild any rollup; the comparison appears with the first upload that has enough earlier months.
 */
class RecordCustomerHistory extends Command
{
    protected $signature = 'commercial:customers:record-history {--district= : Only this district id}';

    protected $description = "File each district's current customers under the month of its newest upload (starts the consumption history)";

    public function handle(CustomerConsumptionHistory $history, CustomerBatchLifecycle $lifecycle): int
    {
        if (! config('gwl.commercial_module_enabled') || ! config('gwl.commercial_customer_list_enabled')) {
            $this->info('The Commercial customer list is switched off.');

            return self::SUCCESS;
        }

        $billing = (new CustomerLookups)->billingStatusIds();
        $districts = CommercialCustomerBatch::query()->live()->when($this->option('district'), fn ($q, $id) => $q->where('district_id', (int) $id))->distinct()->pluck('district_id');

        foreach ($districts as $districtId) {
            $batch = $lifecycle->newest((int) $districtId);

            if (! $batch) {
                continue;
            }

            $rows = $history->record($batch, $billing);
            $this->line("District {$districtId}: {$rows} account(s) filed under ".$history->month($batch)->format('Y-m').'.');
        }

        return self::SUCCESS;
    }
}
