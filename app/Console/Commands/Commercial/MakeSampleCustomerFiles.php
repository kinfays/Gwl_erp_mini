<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Invents customer-list workbooks for the districts of one region, in the layout of the real rptCustomerDetails export, so
 * the Customer List screens can be tried without a real (personal-data) file. Everything is made up: names are
 * "Sample Customer 0001234", phones are 024 + digits, e-mails end in @example.test. Nothing is read from a real export.
 *
 * The files are written to storage/app/sample-customer-files. With --import they are also loaded through the real import
 * pipeline, one weekly upload per week (each week changes some balances, adds a few accounts and drops a few), so the
 * dashboards have trends and the lists have changes to show. Undo with the page's void action, newest upload first.
 */
class MakeSampleCustomerFiles extends Command
{
    protected $signature = 'commercial:customers:sample
        {--region=Accra West : Region whose districts get a file}
        {--customers=2500 : Customers per district in the first week}
        {--weeks=3 : Weekly uploads to make (the last is the newest)}
        {--monthly : Make month-end uploads instead of weekly ones, starting at --start}
        {--start=2026-03-31 : With --monthly, the date of the first month-end upload}
        {--steps=7 : With --monthly, how many month-end uploads to make}
        {--fresh : First delete the customer-list data (customers, uploads, rollups, history) so older files can be loaded in date order; development only}
        {--force : With --fresh, do not ask first}
        {--import : Also load the files through the import pipeline}
        {--region-wide : Write ONE file for the whole region in the real layout (no DISTRICT: line, route headings "code, DISTRICT, REGION"), like the real export}
        {--districts=5 : With --region-wide, how many districts the file holds (the region\'s own first; any extra are invented names that will ask for a match)}
        {--district-names= : With --region-wide, the district names as printed, comma separated (instead of the region\'s own)}
        {--per-route=400 : With --region-wide, customers per route}
        {--edge-cases : With --region-wide, add the awkward cases: a route whose TOTALS row is one row out, one code under two districts, a 0000 route, a customer with two mobiles and two e-mails, and a heading with an unknown district}
        {--output= : With --region-wide, the path of the one file to write (default: the sample folder)}';

    protected $description = 'Make synthetic (not real) customer-list workbooks for a region, and optionally import them';

    private const CATEGORIES = ['611' => 62, '612' => 14, '613' => 6, '623' => 5, '601' => 4, '621' => 3, '643' => 3, '651' => 3];

    private const STATUSES = ['ACTB' => 78, 'ACTN' => 8, 'DISC' => 7, 'SUSP' => 3, 'VACN' => 2, 'DISO' => 2];

    public function handle(CustomerImportService $imports): int
    {
        if (app()->environment('production')) {
            $this->error('Sample data is for development only.');

            return self::FAILURE;
        }

        $region = Region::query()->where('region_name', $this->option('region'))->first();

        if (! $region) {
            $this->error('No region named "'.$this->option('region').'".');

            return self::FAILURE;
        }

        $districts = District::query()->where('region_id', $region->id)
            ->where('district_name', 'not like', '%Office%')->orderBy('district_name')->get();

        if ($districts->isEmpty()) {
            $this->error('That region has no districts to make files for.');

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! $this->wipe()) {
            return self::FAILURE;
        }

        if ($this->option('region-wide')) {
            return $this->regionWide($imports, $region, $districts);
        }

        $customers = max(50, (int) $this->option('customers'));
        $monthly = (bool) $this->option('monthly');
        $weeks = $monthly ? max(1, min(24, (int) $this->option('steps'))) : max(1, min(8, (int) $this->option('weeks')));
        $first = CarbonImmutable::parse((string) $this->option('start'))->endOfMonth()->startOfDay();
        $newest = $monthly ? $first->addMonthsNoOverflow($weeks - 1)->endOfMonth()->startOfDay() : CarbonImmutable::now()->startOfWeek()->subDay();   // last Sunday
        $directory = storage_path('app/sample-customer-files');
        is_dir($directory) || mkdir($directory, 0775, true);

        for ($week = 0; $week < $weeks; $week++) {
            $asOf = $monthly ? $first->addMonthsNoOverflow($week)->endOfMonth()->startOfDay() : $newest->subWeeks($weeks - 1 - $week);

            foreach ($districts as $district) {
                $path = $directory.'/rptCustomerDetails-SAMPLE-'.str_replace(' ', '', $district->district_name).'-'.$asOf->format('Y-m-d').'.xlsx';
                $rows = $this->write($path, $region->region_name, $district, $customers, $week, $asOf, $newest);
                $this->line(sprintf('%s  week %d  %s  %s rows', $asOf->format('Y-m-d'), $week + 1, str_pad($district->district_name, 12), number_format($rows)));

                if (! $this->option('import')) {
                    continue;
                }

                try {
                    ['batch' => $batch, 'duplicate' => $duplicate] = $imports->register($path, basename($path), $monthly ? CommercialCustomerBatch::PERIOD_MONTHLY : CommercialCustomerBatch::PERIOD_WEEKLY, \Illuminate\Support\Carbon::instance($asOf), null, 'Synthetic sample data');
                    $batch = $duplicate ? $batch : $imports->process($batch);
                    $this->line('    -> '.$batch->status.($duplicate ? ' (already uploaded)' : '').($batch->error_message ? ': '.$batch->error_message : ''));
                } catch (CustomerImportException $e) {
                    $this->error('    -> '.$e->getMessage());
                } catch (Throwable $e) {
                    $this->error('    -> failed: '.class_basename($e));
                }
            }
        }

        $this->info($this->option('import')
            ? 'Done. Open Commercial > Customer List.'
            : 'Files are in '.$directory.'. Upload them (oldest first) on Commercial > Customer uploads, as weekly.');

        return self::SUCCESS;
    }

    /**
     * ONE file for the whole region, laid out like the real rptCustomerDetails export (Phase 8b): the filter block has only REGION, and every
     * route has its own heading "code, DISTRICT, REGION" followed by its customers and a "<code> TOTALS :" row. Everything is invented.
     */
    protected function regionWide(CustomerImportService $imports, Region $region, $districts): int
    {
        $names = $this->option('district-names')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('district-names')))))
            : $districts->pluck('district_name')->all();
        $wanted = max(1, min(40, (int) $this->option('districts')));
        $names = array_slice($names, 0, $wanted);

        for ($extra = count($names) + 1; count($names) < $wanted; $extra++) {
            $names[] = 'Sample District '.$extra;   // invented: it is not in the region, so the import asks for a match
        }

        $names = array_map('mb_strtoupper', $names);
        $customers = max(10, (int) $this->option('customers'));
        $asOf = CarbonImmutable::parse((string) $this->option('start'))->endOfMonth()->startOfDay();
        $directory = storage_path('app/sample-customer-files');
        is_dir($directory) || mkdir($directory, 0775, true);
        $path = (string) ($this->option('output') ?: $directory.'/rptCustomerDetails-SAMPLE-'.str_replace(' ', '', $region->region_name).'-all-districts-'.$asOf->format('Y-m-d').'.xlsx');

        $rows = $this->writeRegionWide($path, mb_strtoupper($region->region_name), $names, $customers, max(1, (int) $this->option('per-route')), $asOf, (bool) $this->option('edge-cases'));
        $this->line(sprintf('%s  %s districts  %s rows  %s', $asOf->format('Y-m-d'), count($names), number_format($rows), $path));

        if (! $this->option('import')) {
            $this->info('Upload it on Commercial > Customer uploads.');

            return self::SUCCESS;
        }

        try {
            ['batch' => $batch, 'upload' => $upload, 'duplicate' => $duplicate] = $imports->register($path, basename($path), $this->option('monthly') ? CommercialCustomerBatch::PERIOD_MONTHLY : CommercialCustomerBatch::PERIOD_WEEKLY, \Illuminate\Support\Carbon::instance($asOf), null, 'Synthetic sample data');
            $imports->process($batch);
            $upload->refresh();
            $this->line('    -> '.$upload->status.($duplicate ? ' (already uploaded)' : '').', '.$upload->batches()->count().' district batch(es)');
        } catch (CustomerImportException $e) {
            $this->error('    -> '.$e->getMessage());
        } catch (Throwable $e) {
            $this->error('    -> failed: '.class_basename($e));
        }

        return self::SUCCESS;
    }

    /**
     * Writes the region-wide workbook and returns its number of customers. $names are the district names as printed (upper case).
     *
     * @param  list<string>  $names
     */
    protected function writeRegionWide(string $path, string $region, array $names, int $perDistrict, int $perRoute, CarbonImmutable $asOf, bool $edge): int
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Document map');
        $writer->addRow(Row::fromValues(['Customer List Report (SAMPLE - invented data)']));
        $writer->addNewSheetAndMakeItCurrent()->setName('rptCustomerDetails');
        $writer->addRow(Row::fromValues(['Customer List Report']));
        $writer->addRow(Row::fromValues(['FILTERS']));
        $writer->addRow(Row::fromValues(['REGION: '.$region."\n"]));   // no DISTRICT: line: the districts are in the route headings

        $headers = [null, 'Account #', 'Meter  #', 'Account Name', null, 'Category', 'Status', 'Meter Status', 'Residential Address', null, null, null, null, 'Mobile', 'Email', null, 'Balance', 'Last Read Date', null, 'Last Reading', 'Last Bill Date', 'Last Bill Amount', 'Last Paid Date', 'Last Paid Amount', 'Connect Date', 'Estimated Consume', 'Average Consume', 'Meter Factor'];
        $written = 0;

        $emit = function (string $code, string $district, array $customers, ?int $claimed = null) use ($writer, $headers, $region, &$written): void {
            $writer->addRow(Row::fromValues([null, "{$code}, {$district}, {$region}"]));
            $writer->addRow(Row::fromValues($headers));
            $balance = 0.0;

            foreach ($customers as $c) {
                $balance += $c['balance'];
                $written++;
                $writer->addRow(Row::fromValues([
                    null, $c['account'], $c['meter_no'], $c['name'], null, $c['category'], $c['status'], $c['meter_status'], $c['address'], null, null, null, null,
                    $c['mobile'], $c['email'], null, $c['balance'], $c['last_read_date'], null, $c['last_reading'], $c['last_bill_date'], $c['last_bill_amount'],
                    $c['last_paid_date'], $c['last_paid_amount'], $c['connect_date'], $c['estimated_consume'], $c['average_consume'], $c['meter_factor'],
                ]));
            }

            $writer->addRow(Row::fromValues([null, "{$code} TOTALS :", null, null, null, null, null, null, null, null, null, null, null, null, 'Customer Count: '.number_format($claimed ?? count($customers)), null, round($balance, 2)]));
            $writer->addRow(Row::fromValues([]));
        };

        foreach ($names as $index => $district) {
            $offset = ($index + 1) * 1000000;
            $routeNo = 0;

            for ($from = 0; $from < $perDistrict; $from += $perRoute) {
                $routeNo++;
                $customers = [];

                for ($i = $from; $i < min($perDistrict, $from + $perRoute); $i++) {
                    $customers[] = $this->customer($offset + $i, $i, 0, $asOf, $asOf);
                }

                if ($edge && $index === 0 && $routeNo === 1) {
                    // a customer with two mobiles and two e-mail addresses in their cells
                    $customers[0]['mobile'] = '233241234567 / 233207654321';
                    $customers[0]['email'] = 'two.mail@example.test / second.mail@example.test';
                }

                // the first route of the first district says it has one customer more than it lists (a route that disagrees with its own totals)
                $emit((string) (1000 + $index * 100 + $routeNo), $district, $customers, $edge && $index === 0 && $routeNo === 1 ? count($customers) + 1 : null);
            }
        }

        if ($edge && count($names) >= 2) {
            // one route code printed under two districts, and an unassigned 0000 route
            $emit('4001', $names[0], array_map(fn (int $i) => $this->customer(900000000 + $i, $i, 0, $asOf, $asOf), range(1, 5)));
            $emit('4001', $names[1], array_map(fn (int $i) => $this->customer(910000000 + $i, $i, 0, $asOf, $asOf), range(1, 5)));
            $emit('0000', $names[0], array_map(fn (int $i) => $this->customer(920000000 + $i, $i, 0, $asOf, $asOf), range(1, 4)));
        }

        if ($edge) {
            // a heading with a district the region does not have: the whole file waits for a match
            $emit('9001', 'SAMPLE UNKNOWN', array_map(fn (int $i) => $this->customer(930000000 + $i, $i, 0, $asOf, $asOf), range(1, 6)));
        }

        $writer->close();

        return $written;
    }

    /** Deletes the customer-list data of the whole database (not the lookups). Refuses when SMS campaigns point at customers. */
    protected function wipe(): bool
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('commercial_sms_campaigns') && DB::table('commercial_sms_campaigns')->exists()) {
            $this->error('SMS campaigns exist and point at customers: refusing to delete the customer list.');

            return false;
        }

        if (! $this->option('force') && ! $this->confirm('Delete ALL customer-list uploads, customers, contacts, rollups and history from this database?')) {
            return false;
        }

        foreach (['commercial_customer_issue_accounts', 'commercial_customer_consumption_history', 'commercial_customer_changes', 'commercial_customer_undo', 'commercial_customer_diff', 'commercial_customer_staging',
            'commercial_customer_connections', 'commercial_customer_consumption', 'commercial_customer_quality', 'commercial_customer_debt_stats', 'commercial_customer_rollups_district', 'commercial_customer_rollups',
            'commercial_customer_contacts', 'commercial_customers', 'commercial_customer_batches'] as $table) {
            DB::table($table)->delete();
        }

        $this->line('Customer-list data deleted.');

        return true;
    }

    /** Writes one district's file for one week and returns its number of customers. */
    protected function write(string $path, string $region, District $district, int $base, int $week, CarbonImmutable $asOf, CarbonImmutable $anchor): int
    {
        $upper = mb_strtoupper($district->district_name);
        $count = $base + $week * (int) ceil($base * 0.012);   // new connections each week
        $perRoute = 400;
        $offset = $district->id * 1000000;

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Document map');
        $writer->addRow(Row::fromValues(['Customer List Report (SAMPLE - invented data)']));
        $writer->addNewSheetAndMakeItCurrent()->setName('rptCustomerDetails');
        $writer->addRow(Row::fromValues(['Customer List Report']));
        $writer->addRow(Row::fromValues(['FILTERS']));
        $writer->addRow(Row::fromValues(['REGION: '.mb_strtoupper($region)."\nDISTRICT: {$upper}\n"]));

        $headers = [null, 'Account #', 'Meter  #', 'Account Name', null, 'Category', 'Status', 'Meter Status', 'Residential Address', null, null, null, null, 'Mobile', 'Email', null, 'Balance', 'Last Read Date', null, 'Last Reading', 'Last Bill Date', 'Last Bill Amount', 'Last Paid Date', 'Last Paid Amount', 'Connect Date', 'Estimated Consume', 'Average Consume', 'Meter Factor'];
        $written = 0;
        $routeNo = 0;
        $balance = 0.0;
        $inRoute = 0;
        $open = false;
        $routeName = '';

        $close = function () use (&$open, &$routeName, &$inRoute, &$balance, $writer): void {
            if (! $open) {
                return;
            }

            $writer->addRow(Row::fromValues([null, "{$routeName} TOTALS :", null, null, null, null, null, null, null, null, null, null, null, null, 'Customer Count: '.number_format($inRoute), null, round($balance, 2)]));
            $writer->addRow(Row::fromValues([]));
            $open = false;
        };

        for ($i = 0; $i < $count; $i++) {
            // A few old accounts stop being listed from the third week on, so "missing from the file" has something to show.
            if ($week >= 2 && $i < $base && $i % 200 === 3) {
                continue;
            }

            if (! $open || $inRoute >= $perRoute) {
                $close();
                $routeNo++;
                $routeName = $upper.' '.(1100 + $routeNo);
                $writer->addRow(Row::fromValues([null, "{$routeName}, {$upper}, ".mb_strtoupper($region)]));
                $writer->addRow(Row::fromValues($headers));
                $open = true;
                $inRoute = 0;
                $balance = 0.0;
            }

            $c = $this->customer($offset + $i, $i, $week, $asOf, $anchor);
            $balance += $c['balance'];
            $inRoute++;
            $written++;

            $writer->addRow(Row::fromValues([
                null, $c['account'], $c['meter_no'], $c['name'], null, $c['category'], $c['status'], $c['meter_status'], $c['address'], null, null, null, null,
                $c['mobile'], $c['email'], null, $c['balance'], $c['last_read_date'], null, $c['last_reading'], $c['last_bill_date'], $c['last_bill_amount'],
                $c['last_paid_date'], $c['last_paid_amount'], $c['connect_date'], $c['estimated_consume'], $c['average_consume'], $c['meter_factor'],
            ]));
        }

        $close();
        $writer->close();

        return $written;
    }

    /** One invented customer, deterministic in its number and the week. @return array<string, mixed> */
    protected function customer(int $n, int $i, int $week, CarbonImmutable $asOf, CarbonImmutable $anchor): array
    {
        $mix = $n % 100;
        $bill = round(18 + (($n * 31) % 340) / 2, 2);

        // Months of bill owed: most pay up, a tail owes a lot. Some accounts keep accruing, some keep paying down.
        $months = match (true) {
            $mix < 38 => 0.0, $mix < 43 => -0.5, $mix < 63 => 1.0, $mix < 74 => 2.0 + ($n % 2), $mix < 84 => 4.0 + ($n % 3),
            $mix < 94 => 7.0 + ($n % 6), default => 13.0 + ($n % 14),
        };
        $balance = $bill * $months;

        if ($n % 7 === 0) {
            $balance += $week * $bill * 0.5;
        } elseif ($n % 11 === 0) {
            $balance = max(0, $balance - $week * $bill);
        }

        $paidAgo = $months <= 1 ? ($n % 30) : ($months < 4 ? 20 + ($n % 70) : 90 + ($n % 400));

        return [
            'account' => str_pad((string) (200000000000 + $n), 12, '0', STR_PAD_LEFT),
            'meter_no' => $n % 23 === 0 ? null : 'SMP'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'name' => 'Sample Customer '.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'category' => $this->pick(self::CATEGORIES, $n * 7),
            'status' => $this->pick(self::STATUSES, $n * 13),
            'meter_status' => $n % 50 === 0 ? 'F' : ($n % 37 === 0 ? 'N' : 'W'),
            'address' => 'Sample House '.$n.', Test Estate',
            'mobile' => $this->mobile($n),
            'email' => $n % 6 === 0 ? 'sample'.$n.'@example.test' : null,
            'balance' => round($balance, 2),
            'last_reading' => 100 + ($n % 4000) + $week * (4 + $n % 9),
            'last_read_date' => $asOf->subDays($n % 75)->toDateTimeImmutable(),
            'last_bill_date' => $asOf->subDays($n % 35)->toDateTimeImmutable(),
            'last_bill_amount' => $bill,
            'last_paid_date' => $asOf->subDays($paidAgo + ($week > 0 && $n % 5 === 0 ? 0 : $week * 7))->toDateTimeImmutable(),
            'last_paid_amount' => round(max(5, $bill * (0.5 + ($n % 4) / 4)), 2),
            'connect_date' => $anchor->subDays(30 + ($n * 13) % 5000)->toDateTimeImmutable(),
            'estimated_consume' => $n % 9 === 0 ? 8 : 0,
            'average_consume' => $this->average($n, $week),
            'meter_factor' => 1,
        ];
    }

    /**
     * A customer's average consumption: steady, with a few planted changes in the later uploads so the own-history comparison has
     * something to find (a spike, a drop, and a customer who stops using water).
     */
    protected function average(int $n, int $week): float
    {
        $base = 4 + ($n * 3) % 40;
        $drift = 1 + (($n + $week * 7) % 11 - 5) / 100;   // a few percent up or down from one upload to the next

        return match (true) {
            $week >= 4 && $n % 53 === 0 => round($base * 3.2, 1),
            $week >= 4 && $n % 61 === 0 => round($base * 0.3, 1),
            $week >= 5 && $n % 97 === 0 => 0.0,
            default => round($base * $drift, 1),
        };
    }

    /**
     * The Mobile cell. Like the real export, numbers are 12 digits (233 + nine) and a customer with two has " / " between them.
     * Every eighth customer has two numbers, and a few share one "agent" number so the shared-mobile list has something to show.
     */
    protected function mobile(int $n): ?string
    {
        if ($n % 9 === 0) {
            return null;
        }

        $first = '233'.'24'.str_pad((string) ($n % 10000000), 7, '0', STR_PAD_LEFT);

        if ($n % 250 === 5) {
            return $first.' / 233500000123';
        }

        return $n % 8 === 0 ? $first.' / 233'.'20'.str_pad((string) (($n * 7) % 10000000), 7, '0', STR_PAD_LEFT) : $first;
    }

    /** @param array<string, int> $weights summing to 100 */
    private function pick(array $weights, int $seed): string
    {
        $point = $seed % 100;
        $sum = 0;

        foreach ($weights as $value => $weight) {
            $sum += $weight;

            if ($point < $sum) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }
}
