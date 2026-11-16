<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Trends\TrendsSampleLoader;
use App\Services\Commercial\Trends\TrendsSampleSet;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads a SERIES of invented uploads for the trends (Phase 8f, design 8.45): three weekly, one monthly and one year-earlier reading-by-route and Detailed
 * Billing upload (with a gap, a late upload and the same week a year before) and three daily customer-list snapshots, through the real import paths, into an
 * INVENTED region with two invented districts. It never touches a real region and it refuses production. The series and its arithmetic are described in
 * TrendsSampleSet. Needs the customer list switched on (GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED) and runs in the foreground (no queue worker).
 */
class MakeTrendsSample extends Command
{
    protected $signature = 'commercial:trends:sample
        {--region=Sample Trends Region : The invented region to load the series into (created when it does not exist)}
        {--output= : A folder to write the workbooks to (default: storage/app/sample-trends-files)}
        {--files-only : Only write the workbooks; load nothing}';

    protected $description = 'Load a series of invented weekly and monthly reading, billing and customer-list uploads for one invented region (for trying the Trends screens)';

    public function handle(TrendsSampleLoader $loader): int
    {
        if (app()->environment('production')) {
            $this->error('Sample data is for development only.');

            return self::FAILURE;
        }

        $directory = (string) ($this->option('output') ?: storage_path('app/sample-trends-files'));
        $regionName = trim((string) $this->option('region')) ?: 'Sample Trends Region';

        if ($this->option('files-only')) {
            $files = TrendsSampleSet::write($directory, ['SAMPLE ONE', 'SAMPLE TWO'], mb_strtoupper($regionName));
            $this->info('Wrote '.count($files['reading']).' reading, '.count($files['billing']).' billing and '.count($files['customers']).' customer-list files to '.$directory.'.');

            return self::SUCCESS;
        }

        if (! config('gwl.commercial_customer_list_enabled')) {
            $this->error('Switch the customer list on first (GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED=true): the series includes customer-list snapshots.');

            return self::FAILURE;
        }

        $region = Region::query()->whereRaw('lower(region_name) = ?', [mb_strtolower($regionName)])->first() ?? Region::query()->create(['region_name' => $regionName]);
        $names = [];

        foreach (['Sample One', 'Sample Two'] as $name) {
            $names[] = District::query()->firstOrCreate(['region_id' => $region->id, 'district_name' => $name])->district_name;
        }

        if (CommercialImportBatch::query()->where('region_id', $region->id)->whereIn('report_type', ['reading_route_summary', 'billing_details'])->where('status', '!=', 'voided')->exists()) {
            $this->warn('Region "'.$region->region_name.'" already has route-grain uploads: loading the series again would add a second copy. Use another --region, or void the old uploads first.');

            return self::FAILURE;
        }

        // the sample files are small: the reconciliation limits are widened for the run only, as the tests do
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 10.0, 'gwl.commercial_reconcile_grand_total_tolerance_pct' => 1.0]);

        try {
            $loaded = $loader->load($directory, array_map('mb_strtoupper', $names), mb_strtoupper($region->region_name));
        } catch (Throwable $e) {
            $this->error('The series could not be loaded ('.class_basename($e).': '.$e->getMessage().').');

            return self::FAILURE;
        }

        $this->info('Loaded the invented series into region "'.$region->region_name.'" (districts '.implode(', ', $names).').');
        $this->line('  reading by route: '.implode(', ', array_map(fn ($b, $k) => $k.' '.$b->status, $loaded['reading'], array_keys($loaded['reading']))));
        $this->line('  detailed billing: '.implode(', ', array_map(fn ($b, $k) => $k.' '.$b->status, $loaded['billing'], array_keys($loaded['billing']))));
        $this->line('  customer list: '.count($loaded['customers']).' daily snapshots');
        $this->line('Open Commercial > Trends as a super admin, or as a user whose employee record is in this region. Set GWL_COMMERCIAL_MIN_VISITS_FOR_RANKING=10 to rank the CCAs of this small series.');

        return self::SUCCESS;
    }
}
