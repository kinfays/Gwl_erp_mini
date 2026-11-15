<?php

namespace App\Console\Commands\Commercial;

use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\ReadingRouteSampleFile;
use Illuminate\Console\Command;

/**
 * Invents ONE workbook in the layout of the reading report BY ROUTE (rptReadingSumm; Phase 8c), so the upload, the batch page and the
 * "By route and CCA" screens can be tried without a real file. Everything is made up (SAMPLE CCA 01, staff ids 91001 to 91012, routes
 * 7001 to 7040); nothing is read from a real export. The file holds twelve CCAs on 40 routes in four districts and every awkward case:
 * a route under two CCAs (strengths 165 and 169), a CCA with no routes, a CCA whose routes have no visits, an unknown district, a row
 * whose read + skipped is not visited, a TOTALS row that is one out, the excluded System Administrator sheet and a part-month period.
 * The design, with the arithmetic, is in ReadingRouteSampleFile::sampleSpec().
 *
 * By default the four district names are the region's own (when it has at least four), so the routes land in real districts of a
 * development database; pass --districts to print other names (the tests do, with invented ones).
 */
class MakeSampleReadingRouteFile extends Command
{
    protected $signature = 'commercial:reading-routes:sample
        {--region=ACCRA WEST : The region name printed in the file}
        {--districts= : Four district names as printed, comma separated (default: the region\'s own, else invented names)}
        {--period= : The period as the report prints it (default: 01-October-2026 - 21-October-2026, a part month)}
        {--output= : The path of the file to write (default: storage/app/sample-reading-route-files/rptReadingSumm-sample.xlsx)}';

    protected $description = 'Make a synthetic (not real) reading-by-route workbook for trying the upload and the By route and CCA screens';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Sample data is for development only.');

            return self::FAILURE;
        }

        $districts = $this->districts();

        if (count($districts) < 4) {
            $this->error('Give four district names, comma separated.');

            return self::FAILURE;
        }

        $output = (string) ($this->option('output') ?: storage_path('app/sample-reading-route-files/rptReadingSumm-sample.xlsx'));

        if (! is_dir(dirname($output)) && ! @mkdir(dirname($output), 0775, true) && ! is_dir(dirname($output))) {
            $this->error('Could not create the folder for '.$output.'.');

            return self::FAILURE;
        }

        $spec = ReadingRouteSampleFile::sampleSpec((string) $this->option('region'), $districts, (string) ($this->option('period') ?: ReadingRouteSampleFile::SAMPLE_PERIOD));
        $path = ReadingRouteSampleFile::write($spec, $output);

        $this->info('Wrote '.$path.' ('.count($spec['ccas']).' CCA sheets).');
        $this->line('Districts printed: '.implode(', ', $districts).'. Unknown district: '.ReadingRouteSampleFile::UNKNOWN_DISTRICT.'. Upload it on Commercial > Uploads.');

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

        return count($own) >= 4 ? array_slice($own, 0, 4) : ReadingRouteSampleFile::INVENTED_DISTRICTS;
    }
}
