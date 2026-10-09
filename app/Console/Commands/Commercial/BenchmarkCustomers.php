<?php

namespace App\Console\Commands\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerLookups;
use App\Services\Commercial\Customers\CustomerRecordBuilder;
use App\Services\Commercial\Customers\CustomerRollupService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Services\Commercial\Customers\XlsxStreamReader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Proof that the customer list stays fast with millions of rows. It builds a SCRATCH database (never the application's own:
 * the name must contain "bench" and differ from the configured one), then:
 *
 *   1. generates real-layout .xlsx files and runs the REAL import pipeline on them (initial load, a changed re-upload, an
 *      unchanged re-upload), reporting time per 100k rows for each phase and peak memory;
 *   2. bulk-seeds the rest of the customers directly (--customers in total), building their rollups with the real service;
 *   3. times the dashboard and drill-down queries and prints EXPLAIN for the heaviest ones.
 *
 * All data is synthetic (Customer 000123, 0240000123, c123@example.test). The scratch database is dropped at the end unless
 * --keep is given.
 */
class BenchmarkCustomers extends Command
{
    protected $signature = 'commercial:customers:benchmark
        {--customers=2000000 : Total synthetic customers in the scratch database}
        {--districts=20 : Districts the bulk customers are spread over}
        {--file-rows=100000 : Rows in each generated .xlsx used to measure the real import pipeline}
        {--driver= : mysql or sqlite (default: the application\'s driver)}
        {--database= : Scratch database name; must contain "bench" (default erp_commercial_bench)}
        {--keep : Keep the scratch database afterwards}
        {--reuse : Use the scratch database as it is (kept by an earlier --keep run) instead of building it again}
        {--profile : Print where the database time goes, by statement, for the import runs}
        {--skip-seed : Do not bulk-seed (only run the file pipeline)}
        {--allow-in-tests : (internal) let the test suite create a scratch database}';

    protected $description = 'Benchmark the Commercial customer list on a scratch database with millions of synthetic customers';

    protected string $connection = 'commercial_benchmark';

    protected Carbon $asOf;

    /** @var array<string, mixed> */
    protected array $report = [];

    /** @var array<string, array{ms: float, n: int}> */
    protected array $profile = [];

    public function handle(): int
    {
        $this->asOf = Carbon::parse('2026-10-05');

        if (! $this->scratchDatabase($driver, $name)) {
            return self::FAILURE;
        }

        $this->info("Scratch database: {$driver}:{$name}. Nothing here touches the application's own database.");
        $this->report['driver'] = $driver;
        $this->report['customers_target'] = (int) $this->option('customers');

        try {
            if (! $this->option('reuse')) {
                $this->migrate();
                $this->seedPlaces();
            }

            $this->importFiles();

            if (! $this->option('skip-seed')) {
                $this->bulkSeed();
            }

            $this->queries();
            $this->explain();
        } finally {
            $this->report['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
            $path = storage_path('app/commercial-benchmark-report.json');
            File::put($path, json_encode($this->report, JSON_PRETTY_PRINT));
            $this->line("Report written to {$path}");

            if (! $this->option('keep')) {
                $this->dropScratch($driver, $name);
            }
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- scratch database

    protected function scratchDatabase(?string &$driver, ?string &$name): bool
    {
        $default = config('database.default');
        $driver = $this->option('driver') ?: config("database.connections.{$default}.driver");

        if ($driver === 'sqlite') {
            $name = 'commercial-benchmark.sqlite';
            $path = storage_path('app/'.$name);

            if (! $this->option('reuse')) {
                File::delete($path);
                File::put($path, '');
            }

            config(["database.connections.{$this->connection}" => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $name = (string) ($this->option('database') ?: 'erp_commercial_bench');
            $reuse = (bool) $this->option('reuse');
            $appDatabases = array_filter([config('database.connections.mysql.database'), config("database.connections.{$default}.database")]);

            if (! preg_match('/^[A-Za-z0-9_]+$/', $name) || ! str_contains(strtolower($name), 'bench') || in_array($name, $appDatabases, true)) {
                $this->error("Refusing to use \"{$name}\": the scratch database name must be letters, digits and underscores, must contain \"bench\" and must not be the application's database.");

                return false;
            }

            // The test suite must never reach a real server, whatever name it is given.
            if (app()->runningUnitTests() && ! $this->option('allow-in-tests')) {
                $this->error('Refusing to create a MySQL database from the test suite.');

                return false;
            }

            $base = config('database.connections.mysql');
            $pdo = new \PDO("mysql:host={$base['host']};port={$base['port']}", $base['username'], $base['password']);
            if (! $reuse) {
                $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
                $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            config(["database.connections.{$this->connection}" => array_merge($base, ['database' => $name])]);
        } else {
            $this->error("Driver {$driver} is not supported by the benchmark.");

            return false;
        }

        DB::purge($this->connection);
        config(['database.default' => $this->connection]);
        DB::setDefaultConnection($this->connection);

        // Locks and cache entries must NEVER land in the application's own database (a database cache store binds its
        // connection when it is first used, which may be before the switch above): keep them in memory.
        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        app('cache')->forgetDriver();

        return true;
    }

    protected function dropScratch(string $driver, string $name): void
    {
        DB::purge($this->connection);

        if ($driver === 'sqlite') {
            File::delete(storage_path('app/'.$name));
        } else {
            $base = config("database.connections.{$this->connection}");
            (new \PDO("mysql:host={$base['host']};port={$base['port']}", $base['username'], $base['password']))->exec("DROP DATABASE IF EXISTS `{$name}`");
        }

        $this->line("Scratch database {$name} dropped.");
    }

    protected function migrate(): void
    {
        $start = microtime(true);
        Artisan::call('migrate', ['--database' => $this->connection, '--force' => true]);
        $this->line('Migrated the scratch database in '.round(microtime(true) - $start, 1).' s.');
    }

    protected function seedPlaces(): void
    {
        DB::table('regions')->insert(['region_name' => 'Bench Region', 'created_at' => now(), 'updated_at' => now()]);
        $regionId = (int) DB::table('regions')->value('id');
        $districts = max(3, (int) $this->option('districts'));

        for ($i = 1; $i <= $districts; $i++) {
            DB::table('districts')->insert(['region_id' => $regionId, 'district_name' => 'Bench District '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ---------------------------------------------------------------- 1. the real pipeline

    protected function importFiles(): void
    {
        $rows = max(1000, (int) $this->option('file-rows'));
        $imports = app(CustomerImportService::class);
        $out = [];

        // Memory is flat in the size of the file: a quarter-size file first, then the full one.
        foreach ([['small initial load', intdiv($rows, 4), 1, 0.0, '2026-08-05'], ['initial load', $rows, 2, 0.0, '2026-08-05'], ['re-upload, 10% changed', $rows, 2, 0.10, '2026-09-05'], ['re-upload, unchanged', $rows, 2, 0.10, '2026-10-05']] as [$label, $count, $district, $changed, $asOf]) {
            $district = 'Bench District '.str_pad((string) $district, 2, '0', STR_PAD_LEFT);
            $path = $this->generateFile($count, $district, $changed);
            $size = round(filesize($path) / 1048576, 1);

            $reader = microtime(true);
            $parsedRows = 0;
            $probe = new XlsxStreamReader($path);

            foreach ($probe->rows('rptCustomerDetails') as $row) {
                $parsedRows++;
            }

            $probe->close();
            $readSeconds = microtime(true) - $reader;

            ['batch' => $batch] = $imports->register($path, "{$label}.xlsx", 'monthly', Carbon::parse($asOf), null);
            $imports->timings = [];
            $this->profile = [];

            if ($this->option('profile')) {
                DB::listen(function ($query): void {
                    $key = substr((string) preg_replace('/\s+/', ' ', $query->sql), 0, 110);
                    $this->profile[$key]['ms'] = ($this->profile[$key]['ms'] ?? 0) + $query->time;
                    $this->profile[$key]['n'] = ($this->profile[$key]['n'] ?? 0) + 1;
                });
            }

            $start = microtime(true);
            $batch = $imports->process($batch);
            $total = microtime(true) - $start;

            if ($this->option('profile')) {
                $dbMs = array_sum(array_column($this->profile, 'ms'));
                $this->line(sprintf("  timings: %s", json_encode(array_map(fn ($s) => round($s, 1), $imports->timings))));
            $this->line(sprintf("  [%s] %.1f s total, %.1f s in the database, %.1f s in PHP. Top statements:", $label, $total, $dbMs / 1000, $total - $dbMs / 1000));
                uasort($this->profile, fn ($a, $b) => $b['ms'] <=> $a['ms']);

                foreach (array_slice($this->profile, 0, 8, true) as $sql => $stat) {
                    $this->line(sprintf('    %7.0f ms  x%-5d %s', $stat['ms'], $stat['n'], $sql));
                }
            }

            if ($batch->status !== CommercialCustomerBatch::STATUS_IMPORTED) {
                $this->error("Import of {$label} ended as {$batch->status}: ".json_encode($batch->errors));

                continue;
            }

            $per100k = fn (float $seconds) => round($seconds / max(1, $count) * 100000, 1);
            $out[] = [
                'label' => $label, 'rows' => $count, 'file_mb' => $size, 'read_only_s' => round($readSeconds, 2), 'total_s' => round($total, 1),
                'per_100k_s' => $per100k($total), 'stage_per_100k_s' => $per100k($imports->timings['stage'] ?? 0), 'merge_per_100k_s' => $per100k($imports->timings['merge'] ?? 0),
                'stage_insert_per_100k_s' => $per100k($imports->timings['stage_insert'] ?? 0), 'stage_build_per_100k_s' => $per100k($imports->timings['stage_build'] ?? 0),
                'rollup_s' => round($imports->timings['rollups'] ?? 0, 2), 'new' => $batch->rows_new, 'changed' => $batch->rows_changed, 'unchanged' => $batch->rows_unchanged,
                'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];

            @unlink($path);
        }

        $this->report['pipeline'] = $out;
        $this->table(['Run', 'Rows', 'File MB', 'Read only s', 'Total s', 's / 100k rows', 'stage /100k', 'merge /100k', 'Rollups s', 'New', 'Changed', 'Unchanged', 'Peak MB'],
            array_map(fn ($r) => [$r['label'], number_format($r['rows']), $r['file_mb'], $r['read_only_s'], $r['total_s'], $r['per_100k_s'], $r['stage_per_100k_s'], $r['merge_per_100k_s'], $r['rollup_s'], $r['new'], $r['changed'], $r['unchanged'], $r['peak_mb']], $out));
    }

    /** A real-layout customer list of $count rows in routes of ~400, written with a streaming writer. */
    protected function generateFile(int $count, string $district, float $changedShare): string
    {
        $path = storage_path('app/bench-customers-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Document map');
        $writer->addRow(Row::fromValues(['Customer List Report']));
        $writer->addNewSheetAndMakeItCurrent()->setName('rptCustomerDetails');
        $writer->addRow(Row::fromValues(['Customer List Report']));
        $writer->addRow(Row::fromValues(['Generated '.uniqid()]));   // makes two files with the same customers different files
        $writer->addRow(Row::fromValues(['FILTERS']));
        $writer->addRow(Row::fromValues(["REGION: Bench Region\nDISTRICT: {$district}\n"]));

        $headers = [null, 'Account #', 'Meter  #', 'Account Name', null, 'Category', 'Status', 'Meter Status', 'Residential Address', null, null, null, null, 'Mobile', 'Email', null, 'Balance', 'Last Read Date', null, 'Last Reading', 'Last Bill Date', 'Last Bill Amount', 'Last Paid Date', 'Last Paid Amount', 'Connect Date', 'Estimated Consume', 'Average Consume', 'Meter Factor'];
        $perRoute = 400;
        $routes = (int) ceil($count / $perRoute);
        $seedOffset = (int) crc32($district) % 1000000 * 1000;

        for ($route = 1; $route <= $routes; $route++) {
            $name = 'R'.str_pad((string) $route, 3, '0', STR_PAD_LEFT);
            $writer->addRow(Row::fromValues([null, "{$name}, {$district}, Bench Region"]));
            $writer->addRow(Row::fromValues($headers));
            $balance = 0;
            $in = 0;

            for ($i = ($route - 1) * $perRoute; $i < min($count, $route * $perRoute); $i++) {
                $n = $seedOffset + $i;
                $c = $this->customer($n);

                if ($changedShare > 0 && ($i % 100) < (int) ($changedShare * 100)) {
                    $c['balance'] += 12.34;
                    $c['last_paid_date'] = $this->asOf->copy()->subDays(1);
                }

                $balance += $c['balance'];
                $in++;
                $writer->addRow(Row::fromValues([
                    null, $c['account'], $c['meter_no'], $c['name'], null, $c['category'], $c['status'], $c['meter_status'], $c['address'], null, null, null, null,
                    $c['mobile_cell'], $c['email'], null, $c['balance'], $c['last_read_date']->toDateTimeImmutable(), null, $c['last_reading'], $c['last_bill_date']->toDateTimeImmutable(), $c['last_bill_amount'],
                    $c['last_paid_date']->toDateTimeImmutable(), $c['last_paid_amount'], $c['connect_date']->toDateTimeImmutable(), $c['estimated_consume'], $c['average_consume'], $c['meter_factor'],
                ]));
            }

            $writer->addRow(Row::fromValues([null, "{$name} TOTALS :", null, null, null, null, null, null, null, null, null, null, null, null, 'Customer Count: '.number_format($in), null, round($balance, 2)]));
            $writer->addRow(Row::fromValues([]));
        }

        $writer->close();

        return $path;
    }

    /** One synthetic customer, deterministic in $n. @return array<string, mixed> */
    protected function customer(int $n): array
    {
        $c = $this->rawCustomer($n);
        // The Mobile cell of the real export: 12 digits (233 + nine), two numbers joined by " / ".
        $c['mobile_cell'] = '233'.substr($c['mobile'], 1).($c['mobile2'] ? ' / 233'.substr($c['mobile2'], 1) : '');

        return $c;
    }

    /** @return array<string, mixed> */
    protected function rawCustomer(int $n): array
    {
        $mix = $n % 100;
        $asOf = $this->asOf;

        return [
            'account' => str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT),
            'meter_no' => 'M'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'name' => 'Customer '.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'category' => $mix < 70 ? '611' : ($mix < 85 ? '612' : ($mix < 90 ? '613' : ($mix < 95 ? '623' : ($mix < 98 ? '601' : '643')))),
            'status' => $mix < 80 ? 'ACTB' : ($mix < 88 ? 'ACTN' : ($mix < 94 ? 'DISC' : ($mix < 97 ? 'SUSP' : 'VACN'))),
            'meter_status' => $n % 50 === 0 ? 'F' : ($n % 37 === 0 ? 'N' : 'W'),
            'address' => 'Plot '.$n.', Test Estate',
            'mobile' => '024'.str_pad((string) ($n % 10000000), 7, '0', STR_PAD_LEFT),
            'mobile2' => $n % 4 === 0 ? '020'.str_pad((string) (($n * 7) % 10000000), 7, '0', STR_PAD_LEFT) : null,
            'email' => $n % 5 === 0 ? 'c'.$n.'@example.test' : null,
            'balance' => round((($n * 7919) % 100000) / 100 - ($n % 20 === 0 ? 50 : 0), 2),
            'last_reading' => 100 + $n % 5000,
            'last_read_date' => $asOf->copy()->subDays($n % 200),
            'last_bill_date' => $asOf->copy()->subDays($n % 40),
            'last_bill_amount' => round(20 + (($n * 31) % 300) / 2, 2),
            'last_paid_date' => $asOf->copy()->subDays($n % 120),
            'last_paid_amount' => round(10 + (($n * 17) % 200) / 2, 2),
            'connect_date' => $asOf->copy()->subDays(30 + ($n * 13) % 5000),
            'estimated_consume' => $n % 9 === 0 ? 8 : 0,
            'average_consume' => ($n * 3) % 60,
            'meter_factor' => 1,
        ];
    }

    // ---------------------------------------------------------------- 2. bulk seed

    protected function bulkSeed(): void
    {
        $target = (int) $this->option('customers');
        $have = (int) DB::table('commercial_customers')->count();
        $remaining = max(0, $target - $have);
        $districts = DB::table('districts')->orderBy('id')->pluck('id')->all();
        $bulkDistricts = array_slice($districts, 2);   // the first two took the file loads
        $perDistrict = $bulkDistricts === [] ? 0 : (int) ceil($remaining / count($bulkDistricts));
        $regionId = (int) DB::table('regions')->value('id');

        $ids = [
            'cat' => DB::table('commercial_customer_categories')->whereNotNull('code')->pluck('id', 'code')->all(),
            'st' => DB::table('commercial_customer_statuses')->pluck('id', 'code')->all(),
            'mt' => DB::table('commercial_meter_statuses')->pluck('id', 'code')->all(),
        ];
        $lookups = new CustomerLookups;
        $rollups = app(CustomerRollupService::class);
        $seedStart = microtime(true);
        $rollupSeconds = 0.0;
        $rowsInserted = 0;
        $rollupSteps = [];
        $contactId = (int) DB::table('commercial_customers')->max('id');

        foreach ($bulkDistricts as $index => $districtId) {
            $batch = CommercialCustomerBatch::query()->create([
                'region_id' => $regionId, 'district_id' => $districtId, 'period_type' => 'monthly', 'period_key' => $this->asOf->format('Y-m'), 'as_of_date' => $this->asOf->toDateString(),
                'source_filename' => 'bulk-seed', 'file_hash' => hash('sha256', 'bulk'.$districtId), 'status' => 'imported', 'phase' => 'done', 'rows_read' => $perDistrict,
                'reconciliation_passed' => true, 'imported_at' => now(), 'finished_at' => now(),
            ]);

            $routeIds = [];

            for ($r = 1; $r <= 60; $r++) {
                $routeIds[] = $lookups->route($districtId, 'B'.str_pad((string) $r, 3, '0', STR_PAD_LEFT));
            }

            $base = ($index + 10) * 10000000;
            $customers = [];
            $contacts = [];

            for ($i = 0; $i < $perDistrict; $i++) {
                $n = $base + $i;
                $c = $this->customer($n);
                $balance = (int) round($c['balance'] * 100);
                $bill = (int) round($c['last_bill_amount'] * 100);
                $contactId++;
                $bucket = $balance < 0 ? 0 : ($balance === 0 ? 1 : ($bill <= 0 ? 7 : ($balance / $bill <= 1 ? 2 : ($balance / $bill <= 3 ? 3 : ($balance / $bill <= 6 ? 4 : ($balance / $bill <= 12 ? 5 : 6))))));

                $customers[] = [
                    'id' => $contactId, 'account_no' => $c['account'], 'region_id' => $regionId, 'district_id' => $districtId, 'route_id' => $routeIds[$i % 60],
                    'category_id' => $ids['cat'][$c['category']], 'status_id' => $ids['st'][$c['status']], 'meter_status_id' => $ids['mt'][$c['meter_status']],
                    'meter_no' => $c['meter_no'], 'connect_date' => $c['connect_date']->toDateString(), 'balance' => $balance, 'last_read_date' => $c['last_read_date']->toDateString(),
                    'last_reading' => $c['last_reading'], 'last_bill_date' => $c['last_bill_date']->toDateString(), 'last_bill_amount' => $bill,
                    'last_paid_date' => $c['last_paid_date']->toDateString(), 'last_paid_amount' => (int) round($c['last_paid_amount'] * 100),
                    'estimated_consume' => $c['estimated_consume'], 'average_consume' => $c['average_consume'], 'meter_factor' => 1, 'arrears_bucket' => $bucket,
                    'first_seen_batch_id' => $batch->id, 'updated_batch_id' => $batch->id, 'missing_since_batch_id' => null,
                    'attributes_hash' => substr(hash('xxh3', (string) $n), 0, 16),
                ];
                $contacts[] = [
                    'customer_id' => $contactId, 'account_name' => $c['name'], 'address' => $c['address'], 'mobiles' => $c['mobile'].($c['mobile2'] ? ','.$c['mobile2'] : ''), 'phone_primary' => $c['mobile'], 'phone_secondary' => $c['mobile2'],
                    'email' => $c['email'], 'email_lower' => $c['email'], 'name_search' => strtolower($c['name']), 'contact_hash' => substr(hash('xxh3', 'c'.$n), 0, 16),
                    'quality_flags' => ($c['email'] === null ? 2 : 0) | ($c['mobile2'] ? CustomerRecordBuilder::MULTIPLE_PHONES : 0), 'updated_batch_id' => $batch->id,
                ];

                if (count($customers) >= 1200) {
                    DB::table('commercial_customers')->insert($customers);
                    DB::table('commercial_customer_contacts')->insert($contacts);
                    $rowsInserted += count($customers);
                    $customers = $contacts = [];
                }
            }

            if ($customers !== []) {
                DB::table('commercial_customers')->insert($customers);
                DB::table('commercial_customer_contacts')->insert($contacts);
                $rowsInserted += count($customers);
            }

            $start = microtime(true);
            $rollups->build($batch, $lookups->billingStatusIds());
            $rollupSeconds += microtime(true) - $start;

            foreach ($rollups->timings as $step => $seconds) {
                $rollupSteps[$step] = ($rollupSteps[$step] ?? 0) + $seconds;
            }
            $this->line('  seeded district '.($index + 3).'/'.count($districts).' ('.number_format($rowsInserted).' rows so far)');
        }

        $total = (int) DB::table('commercial_customers')->count();
        $this->report['rollup_steps_s'] = array_map(fn ($s) => round($s, 2), $rollupSteps);
        $this->line('Rollup build by step (s, all bulk districts): '.json_encode($this->report['rollup_steps_s']));
        $this->report['bulk'] = ['rows_inserted' => $rowsInserted, 'seed_s' => round(microtime(true) - $seedStart - $rollupSeconds, 1), 'rollup_s_total' => round($rollupSeconds, 1), 'rollup_s_per_100k' => $rowsInserted ? round($rollupSeconds / $rowsInserted * 100000, 2) : null, 'customers_total' => $total];
        $this->info('Customers in the scratch database: '.number_format($total).'. Rollup build: '.round($rollupSeconds, 1).' s ('.($this->report['bulk']['rollup_s_per_100k'] ?? '-').' s per 100k rows).');
    }

    // ---------------------------------------------------------------- 3. queries

    protected function queries(): void
    {
        $analytics = app(CustomerAnalyticsService::class);
        $lists = app(CustomerListService::class);
        $snapshots = app(CustomerSnapshots::class);
        $batches = $snapshots->current(null);
        $filters = $snapshots->filters($batches, null);
        $oneDistrict = (int) $batches->keys()->last();
        $districtFilters = $snapshots->filters($snapshots->current(null, null, $oneDistrict), null, ['district_id' => $oneDistrict]);
        $sampleAccount = (string) DB::table('commercial_customers')->where('district_id', $oneDistrict)->orderByDesc('id')->value('account_no');
        $batchId = (int) $batches[$oneDistrict]->id;
        $secondPhone = (string) DB::table('commercial_customer_contacts AS ct')->join('commercial_customers AS c', 'c.id', '=', 'ct.customer_id')->where('c.district_id', $oneDistrict)->whereNotNull('ct.phone_secondary')->value('ct.phone_secondary');
        $samplePhone = (string) DB::table('commercial_customer_contacts')->where('customer_id', DB::table('commercial_customers')->where('district_id', $oneDistrict)->value('id'))->value('phone_primary');

        $dashboard = [
            'Overview (company, all districts)' => fn () => $analytics->overview($filters),
            'Meter health by district' => fn () => $analytics->meters($filters, 'district'),
            'Meter health by route (one district)' => fn () => $analytics->meters($districtFilters, 'route'),
            'Receivables' => fn () => $analytics->receivables($filters),
            'Collection by category' => fn () => $analytics->collection($filters),
            'Dormancy' => fn () => $analytics->dormancy($filters),
            'Growth and churn' => fn () => $analytics->growth($filters),
            'Consumption' => fn () => $analytics->consumption($filters),
            'Data quality' => fn () => $analytics->quality($filters),
            'Trend (12 periods)' => fn () => $analytics->trend(null, null, null),
            'League table (all districts)' => fn () => $analytics->league($filters, $filters),
        ];

        $drill = [
            'Top debtors, page 1' => fn () => $lists->page(['district_id' => $oneDistrict, 'sort' => 'balance_desc', 'size' => 50]),
            'Top debtors, page 40 (keyset)' => function () use ($lists, $oneDistrict) {
                $page = ['next' => null];
                $after = null;

                for ($i = 0; $i < 40; $i++) {
                    $page = $lists->page(['district_id' => $oneDistrict, 'sort' => 'balance_desc', 'size' => 50, 'after' => $after]);
                    $after = $page['next'];
                }

                return $page;
            },
            'Route list (district, category, status)' => fn () => $lists->page(['district_id' => $oneDistrict, 'category_id' => 1, 'status_id' => 2, 'size' => 50]),
            'Find by account number' => fn () => $lists->page(['search' => ['type' => 'account', 'value' => $sampleAccount]]),
            'Find by phone number' => fn () => $lists->page(['details' => true, 'search' => ['type' => 'phone', 'value' => $samplePhone]]),
            'Find by phone number (second number)' => fn () => $lists->page(['details' => true, 'search' => ['type' => 'phone', 'value' => $secondPhone]]),
            'Shared-meter drill-down' => fn () => $lists->page(['district_id' => $oneDistrict, 'issue' => 'shared_meter', 'batch_id' => $batchId, 'size' => 50]),
            'Missing-mobile drill-down' => fn () => $lists->page(['district_id' => $oneDistrict, 'issue' => 'missing_mobile', 'batch_id' => $batchId, 'size' => 50]),
            'Shared-mobile drill-down' => fn () => $lists->page(['district_id' => $oneDistrict, 'issue' => 'shared_mobile', 'batch_id' => $batchId, 'size' => 50]),
            'Two-numbers drill-down' => fn () => $lists->page(['district_id' => $oneDistrict, 'issue' => 'multiple_phones', 'batch_id' => $batchId, 'size' => 50]),
        ];

        // Phase 6b before / after: the statements as they were (first number only) next to the ones now in use, on the same data.
        $live = "c2.district_id = {$oneDistrict} AND c2.missing_since_batch_id IS NULL";
        $compare = [
            'Shared mobile, before (first number only)' => fn () => DB::select("SELECT COUNT(*) AS n FROM (SELECT t2.phone_primary AS v FROM commercial_customer_contacts t2 INNER JOIN commercial_customers c2 ON c2.id = t2.customer_id WHERE {$live} AND t2.phone_primary IS NOT NULL GROUP BY t2.phone_primary HAVING COUNT(*) > 1) x"),
            'Shared mobile, after (first or second number)' => fn () => DB::select("WITH base AS (SELECT t.customer_id AS cid, t.phone_primary AS p1, t.phone_secondary AS p2 FROM commercial_customer_contacts t INNER JOIN commercial_customers c2 ON c2.id = t.customer_id WHERE {$live} AND t.phone_primary IS NOT NULL), numbers AS (SELECT cid, p1 AS v FROM base UNION ALL SELECT cid, p2 FROM base WHERE p2 IS NOT NULL), shared AS (SELECT v FROM numbers GROUP BY v HAVING COUNT(*) > 1) SELECT COUNT(*) AS n FROM (SELECT DISTINCT n.cid FROM numbers n INNER JOIN shared s ON s.v = n.v) x"),
            'Search by phone, before (first number only)' => fn () => DB::select('SELECT c.id FROM commercial_customers c INNER JOIN commercial_customer_contacts ct ON ct.customer_id = c.id WHERE c.missing_since_batch_id IS NULL AND ct.phone_primary = ?', [$samplePhone]),
            'Search by phone, after (first or second number)' => fn () => $lists->page(['details' => true, 'search' => ['type' => 'phone', 'value' => $samplePhone]]),
        ];

        $rows = [];

        foreach (['dashboard' => $dashboard, 'drill-down' => $drill, 'phase 6b before/after' => $compare] as $kind => $set) {
            foreach ($set as $label => $run) {
                $times = [];

                for ($i = 0; $i < 3; $i++) {
                    $start = microtime(true);
                    $run();
                    $times[] = (microtime(true) - $start) * 1000;
                }

                sort($times);
                $rows[] = ['kind' => $kind, 'query' => $label, 'median_ms' => (int) round($times[1])];
            }
        }

        $this->report['queries'] = $rows;
        $this->report['batches_in_view'] = $batches->count();
        $this->table(['Kind', 'Query', 'Median ms (of 3)'], array_map(fn ($r) => [$r['kind'], $r['query'], $r['median_ms']], $rows));

        $dash = array_filter($rows, fn ($r) => $r['kind'] === 'dashboard');
        $drillRows = array_filter($rows, fn ($r) => $r['kind'] === 'drill-down');
        $this->line('Slowest dashboard query: '.max(array_column($dash, 'median_ms')).' ms (target ~300). Slowest drill-down: '.max(array_column($drillRows, 'median_ms')).' ms (target ~500).');
    }

    // ---------------------------------------------------------------- EXPLAIN

    protected function explain(): void
    {
        $snapshots = app(CustomerSnapshots::class);
        $lists = app(CustomerListService::class);
        $batches = $snapshots->current(null);
        $ids = implode(',', $batches->pluck('id')->all());
        $d = (int) $batches->keys()->last();
        $b = (int) $batches->last()->id;

        $topDebtors = $lists->query(['district_id' => $d], false);
        $lists->select($topDebtors, false);
        $topDebtors->orderByDesc('c.balance')->orderByDesc('c.id')->limit(51);

        $routeList = $lists->query(['district_id' => $d, 'category_id' => 1, 'status_id' => 2], false);
        $lists->select($routeList, false);
        $routeList->orderBy('c.route_id')->orderBy('c.id')->limit(51);

        $queries = [
            '1. Dashboard: rollups of every effective batch, grouped' => ["SELECT s.id, COALESCE(SUM(r.customer_count),0) AS n FROM commercial_customer_rollups_district r JOIN commercial_customer_statuses s ON s.id = r.status_id WHERE r.batch_id IN ({$ids}) GROUP BY s.id", []],
            '2. Top debtors (district, balance DESC, keyset)' => [$topDebtors->toSql(), $topDebtors->getBindings()],
            '3. Route list (district, category, status)' => [$routeList->toSql(), $routeList->getBindings()],
            '4. Find by account number' => ['SELECT * FROM commercial_customers WHERE account_no = ?', ['100000000001']],
            '5. Merge join: staging rows to current customers' => ["SELECT c.id FROM commercial_customers c INNER JOIN commercial_customer_staging s ON s.account_no = c.account_no WHERE s.batch_id = {$b} AND s.id > 0 AND s.id <= 50000 AND (c.attributes_hash <> s.attributes_hash OR c.missing_since_batch_id IS NOT NULL)", []],
            '6. Rollup build: one district scan' => ["SELECT c.route_id, c.category_id, c.status_id, c.meter_status_id, COUNT(*), SUM(c.balance) FROM commercial_customers c WHERE c.district_id = {$d} AND c.missing_since_batch_id IS NULL GROUP BY c.route_id, c.category_id, c.status_id, c.meter_status_id", []],
        ];

        $report = [];

        foreach ($queries as $label => [$sql, $bindings]) {
            $this->line('');
            $this->info($label);
            $plan = DB::select(($this->option('driver') === 'sqlite' || DB::getDriverName() === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$sql, $bindings);
            $lines = [];

            foreach ($plan as $row) {
                $row = (array) $row;
                $lines[] = DB::getDriverName() === 'sqlite'
                    ? ($row['detail'] ?? json_encode($row))
                    : sprintf('table=%s type=%s key=%s rows=%s extra=%s', $row['table'] ?? '-', $row['type'] ?? '-', $row['key'] ?? 'NULL', $row['rows'] ?? '-', $row['Extra'] ?? '');
            }

            foreach ($lines as $line) {
                $this->line('  '.$line);
            }

            $report[$label] = $lines;
        }

        $this->report['explain'] = $report;
    }
}
