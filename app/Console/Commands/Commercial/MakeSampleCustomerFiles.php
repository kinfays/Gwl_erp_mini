<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
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
        {--import : Also load the files through the import pipeline}';

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

        $customers = max(50, (int) $this->option('customers'));
        $weeks = max(1, min(8, (int) $this->option('weeks')));
        $newest = CarbonImmutable::now()->startOfWeek()->subDay();   // last Sunday
        $directory = storage_path('app/sample-customer-files');
        is_dir($directory) || mkdir($directory, 0775, true);

        for ($week = 0; $week < $weeks; $week++) {
            $asOf = $newest->subWeeks($weeks - 1 - $week);

            foreach ($districts as $district) {
                $path = $directory.'/rptCustomerDetails-SAMPLE-'.str_replace(' ', '', $district->district_name).'-'.$asOf->format('Y-m-d').'.xlsx';
                $rows = $this->write($path, $region->region_name, $district, $customers, $week, $asOf, $newest);
                $this->line(sprintf('%s  week %d  %s  %s rows', $asOf->format('Y-m-d'), $week + 1, str_pad($district->district_name, 12), number_format($rows)));

                if (! $this->option('import')) {
                    continue;
                }

                try {
                    ['batch' => $batch, 'duplicate' => $duplicate] = $imports->register($path, basename($path), CommercialCustomerBatch::PERIOD_WEEKLY, \Illuminate\Support\Carbon::instance($asOf), null, 'Synthetic sample data');
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
            'mobile' => $n % 9 === 0 ? null : '024'.str_pad((string) ($n % 10000000), 7, '0', STR_PAD_LEFT),
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
            'average_consume' => 4 + ($n * 3) % 40,
            'meter_factor' => 1,
        ];
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
