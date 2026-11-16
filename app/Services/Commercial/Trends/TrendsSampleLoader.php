<?php

namespace App\Services\Commercial\Trends;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialImportBatch;
use App\Models\User;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Loads the invented trends series (TrendsSampleSet) through the REAL import paths, oldest period first: each daily customer-list snapshot, then for each
 * period the reading report by route and the Detailed Billing Report. After each upload its import time is set to the day the schedule says (the series
 * has a late upload on purpose), so the timeliness page has lateness to show; the figures are the import's own. Used by `commercial:trends:sample` and the
 * tests.
 */
class TrendsSampleLoader
{
    public function __construct(
        protected CommercialImportService $imports,
        protected BillingDetailsImportService $billing,
        protected CustomerImportService $customers,
    ) {}

    /**
     * @param  list<string>  $districts  the two district names as printed
     * @return array{reading: array<string, CommercialImportBatch>, billing: array<string, CommercialImportBatch>, customers: array<string, CommercialCustomerBatch>}
     */
    public function load(string $directory, array $districts, string $region, ?User $actor = null): array
    {
        $files = TrendsSampleSet::write($directory, $districts, $region);
        $out = ['reading' => [], 'billing' => [], 'customers' => [], 'files' => $files];
        $imported = collect(TrendsSampleSet::SCHEDULE)->pluck('imported', 'key');

        foreach ($files['customers'] as $asOf => $path) {
            $registered = $this->customers->register($path, basename($path), CommercialCustomerBatch::PERIOD_DAILY, Carbon::parse($asOf), $actor, 'Invented trends sample');
            $this->customers->process($registered['batch']);
            // the snapshot is imported on its as-of day plus one
            $day = Carbon::parse($asOf)->addDay()->toDateString().' 09:00:00';
            CommercialCustomerBatch::query()->where('upload_id', $registered['upload']->id)->update(['imported_at' => $day]);
            $out['customers'][$asOf] = $registered['batch']->refresh();
        }

        foreach (TrendsSampleSet::SCHEDULE as $p) {
            $file = new UploadedFile($files['reading'][$p['key']], basename($files['reading'][$p['key']]), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $reading = $this->imports->createBatch(['notes' => 'Invented trends sample'], $this->imports->preview($file), null, $actor?->id);

            ['batch' => $queued] = $this->billing->register($files['billing'][$p['key']], basename($files['billing'][$p['key']]), $actor, 'Invented trends sample');
            $billing = $this->billing->process($queued);

            foreach ([$reading, $billing] as $batch) {
                CommercialImportBatch::query()->whereKey($batch->id)->update(['imported_at' => $imported[$p['key']].' 09:00:00']);
            }

            $out['reading'][$p['key']] = $reading->refresh();
            $out['billing'][$p['key']] = $billing->refresh();
        }

        return $out;
    }
}
