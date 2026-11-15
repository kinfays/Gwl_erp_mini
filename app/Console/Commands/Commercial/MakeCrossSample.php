<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Cross\CrossSampleLoader;
use App\Services\Commercial\Cross\CrossSampleSet;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads ONE consistent set of invented files for the "Across the files" analyses (Phase 8e): a region-wide customer list, a reading report by route
 * and CCA and a Detailed Billing Report whose accounts and routes line up, with a route whose district differs between the list and billing, a route
 * with a stale second CCA, an account whose status changed between the bill and the list, and the other cases the analyses look for. It goes through the
 * real import paths, so every rule of a real upload applies. The set and its arithmetic are described in CrossSampleSet.
 *
 * It makes (or reuses) an INVENTED region with two invented districts, so it never touches a real region's data, and it refuses production. Needs the
 * customer list switched on (GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED) and a queue-free run (it processes in the foreground).
 */
class MakeCrossSample extends Command
{
    protected $signature = 'commercial:cross:sample
        {--region=Sample Cross Region : The invented region to load the set into (created when it does not exist)}
        {--output= : A folder to write the three workbooks to (default: storage/app/sample-cross-files)}
        {--files-only : Only write the three workbooks; load nothing}';

    protected $description = 'Load a consistent set of invented reading, billing and customer-list files for one invented region (for trying the Across the files screens)';

    public function handle(CrossSampleLoader $loader): int
    {
        if (app()->environment('production')) {
            $this->error('Sample data is for development only.');

            return self::FAILURE;
        }

        $directory = (string) ($this->option('output') ?: storage_path('app/sample-cross-files'));
        $regionName = trim((string) $this->option('region')) ?: 'Sample Cross Region';

        if ($this->option('files-only')) {
            $files = CrossSampleSet::write($directory, CrossSampleSet::INVENTED_DISTRICTS, mb_strtoupper($regionName));
            $this->info('Wrote '.implode(', ', $files).'.');

            return self::SUCCESS;
        }

        if (! config('gwl.commercial_customer_list_enabled')) {
            $this->error('Switch the customer list on first (GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED=true): the set links billing to the list.');

            return self::FAILURE;
        }

        $region = Region::query()->whereRaw('lower(region_name) = ?', [mb_strtolower($regionName)])->first() ?? Region::query()->create(['region_name' => $regionName]);
        $names = [];

        foreach (['Sample One', 'Sample Two'] as $name) {
            $names[] = District::query()->firstOrCreate(['region_id' => $region->id, 'district_name' => $name])->district_name;
        }

        $printed = array_map('mb_strtoupper', $names);

        if (CommercialImportBatch::query()->where('region_id', $region->id)->whereIn('report_type', ['reading_route_summary', 'billing_details'])->where('status', '!=', 'voided')->exists()) {
            $this->warn('Region "'.$region->region_name.'" already has route-grain uploads: loading the set again would add a second copy. Use another --region, or void the old uploads first.');

            return self::FAILURE;
        }

        try {
            $loaded = $loader->load($directory, $printed, mb_strtoupper($region->region_name));
        } catch (Throwable $e) {
            $this->error('The set could not be loaded ('.class_basename($e).': '.$e->getMessage().').');

            return self::FAILURE;
        }

        $this->info('Loaded the invented set into region "'.$region->region_name.'" (districts '.implode(', ', $names).').');
        $this->line('  customer list: '.$loaded['customers']->status.' | reading by route: '.$loaded['reading']->status.' | detailed billing: '.$loaded['billing']->status);
        $this->line('Open Commercial > Across the files as a super admin, or as a user whose employee record is in this region.');
        $this->line('The routes of this small set have 10 or 11 customers: set GWL_COMMERCIAL_CROSS_MIN_ROUTE_CUSTOMERS=5 and GWL_COMMERCIAL_MIN_VISITS_FOR_RANKING=10 to see X3 and the ranking of X1 on it.');

        return self::SUCCESS;
    }
}
