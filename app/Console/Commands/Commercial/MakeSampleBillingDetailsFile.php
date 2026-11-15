<?php

namespace App\Console\Commands\Commercial;

use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use Illuminate\Console\Command;

/**
 * Invents ONE workbook in the layout of the Detailed Billing Report (rptBillingDetails_ExP; Phase 8d), so the background upload, the batch
 * page and the Billing details screens can be tried without a real file. Everything is made up (customers "Sample Customer n", accounts
 * 100000000001 and up, meter numbers SMPL-n, routes 4101 to 4305); nothing is read from a real export. The file holds 578 customer rows on
 * 21 route headings in three districts and every awkward case the importer and the analyses handle: a part-month bill period, a route whose
 * totals row is one row out, a duplicate account, ESTIMATED on working meters and ESTIMATE on faulty and missing meters, an active unbilled
 * account, a disconnected account that is billed, a tariff mismatch, a zero-consumption bill, a last read date of 2000-01-01, route 0000, a
 * row whose charges do not add up and a route in an unknown district. The design, with the arithmetic, is in
 * BillingDetailsSampleFile::sampleSpec().
 *
 * By default the three district names are the region's own (when it has at least three), so the routes land in real districts of a
 * development database; pass --districts to print other names (the tests do, with invented ones).
 */
class MakeSampleBillingDetailsFile extends Command
{
    protected $signature = 'commercial:billing-details:sample
        {--region=ACCRA WEST : The region name printed in the file}
        {--districts= : Three district names as printed, comma separated (default: the region\'s own, else invented names)}
        {--period= : The bill period as the report prints it (default: 01-September-2026 - 21-September-2026, a part month)}
        {--output= : The path of the file to write (default: storage/app/sample-billing-details-files/rptBillingDetails-sample.xlsx)}';

    protected $description = 'Make a synthetic (not real) Detailed Billing Report workbook for trying the upload and the Billing details screens';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Sample data is for development only.');

            return self::FAILURE;
        }

        $districts = $this->districts();

        if (count($districts) < 3) {
            $this->error('Give three district names, comma separated.');

            return self::FAILURE;
        }

        $output = (string) ($this->option('output') ?: storage_path('app/sample-billing-details-files/rptBillingDetails-sample.xlsx'));

        if (! is_dir(dirname($output)) && ! @mkdir(dirname($output), 0775, true) && ! is_dir(dirname($output))) {
            $this->error('Could not create the folder for '.$output.'.');

            return self::FAILURE;
        }

        $spec = BillingDetailsSampleFile::sampleSpec((string) $this->option('region'), $districts, (string) ($this->option('period') ?: BillingDetailsSampleFile::SAMPLE_PERIOD));
        $path = BillingDetailsSampleFile::write($spec, $output);

        $this->info('Wrote '.$path.' ('.count($spec['routes']).' route headings, '.array_sum(array_map(fn (array $r) => count($r['customers']), $spec['routes'])).' customer rows).');
        $this->line('Districts printed: '.implode(', ', $districts).'. Unknown district: '.BillingDetailsSampleFile::UNKNOWN_DISTRICT.'. Upload it with the "Detailed billing report" form on Commercial > Uploads (and run a queue worker).');

        return self::SUCCESS;
    }

    /** @return list<string> */
    protected function districts(): array
    {
        if (trim((string) $this->option('districts')) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', (string) $this->option('districts'))), fn (string $name) => $name !== ''));
        }

        $region = Region::query()->whereRaw('lower(region_name) = ?', [mb_strtolower((string) $this->option('region'))])->first();
        $own = $region ? District::query()->where('region_id', $region->id)->where('district_name', 'not like', '%Office%')->orderBy('district_name')->pluck('district_name')->map(fn ($name) => mb_strtoupper((string) $name))->all() : [];

        return count($own) >= 3 ? array_slice($own, 0, 3) : BillingDetailsSampleFile::INVENTED_DISTRICTS;
    }
}
