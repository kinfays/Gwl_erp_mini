<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8d, memory: the Detailed Billing Report is read streamed and aggregated, so the PHP memory the import needs depends on the number of
 * ROUTES and distinct keys, never on the number of customers. A normal test proves it on two sizes; the 70,000-row run the design quotes is a
 * benchmark that is skipped unless GWL_BENCH_BILLING_DETAILS=1:
 *
 *     GWL_BENCH_BILLING_DETAILS=1 php artisan test --filter=BillingDetailsMemoryTest::test_benchmark
 *
 * "Memory" is PHP's own (memory_get_peak_usage above the start of the import); the in-memory SQLite database of the tests lives outside it.
 */
class BillingDetailsMemoryTest extends CommercialTestCase
{
    use BuildsBillingDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBillingDetails();
        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_reconcile_max_route_failures_pct' => 100.0]);
    }

    /**
     * A spec of $customers customers in routes of $perRoute, over the three test districts. The customers are a generator: a 70,000-row file
     * is written in flat memory too.
     *
     * @return array<string, mixed>
     */
    protected function streamed(int $customers, int $perRoute = 400, string $period = 'September-2026 - September-2026'): array
    {
        $districts = ['SOWUTUOM', 'ODORKOR', 'AMASAMAN'];
        $routes = [];

        for ($r = 0; $r < (int) ceil($customers / $perRoute); $r++) {
            $from = $r * $perRoute + 1;
            $to = min($customers, ($r + 1) * $perRoute);
            $routes[] = [
                'label' => $districts[$r % 3].' '.(4100 + $r + 1),
                'customers' => (function () use ($from, $to) {
                    for ($n = $from; $n <= $to; $n++) {
                        // a mix of categories, statuses and bill statuses so the rollups have real variety (a few dozen keys per route)
                        yield BillingDetailsSampleFile::customer($n, [
                            'category' => ['611', '612', '613'][$n % 3], 'status' => ['ACTB', 'ACTB', 'ACTB', 'DISC'][$n % 4], 'meter_status' => ['W', 'W', 'F', 'N'][$n % 4],
                            'bill_status' => ['REGULAR', 'REGULAR', 'REGULAR', 'ESTIMATED', 'ESTIMATE'][$n % 5], 'paid' => $n % 7 === 0 ? 0 : null, 'consume' => 5 + $n % 40,
                        ]);
                    }
                })(),
            ];
        }

        return $this->spec($routes, $period);
    }

    /** @return array{customers: int, seconds: float, growth: int, rollups: int, file: int, written: float} */
    protected function measure(int $customers): array
    {
        $t = microtime(true);
        $path = ReportWorkbooks::billingDetails($this->streamed($customers));
        $written = microtime(true) - $t;
        $this->workbooks[] = $path;

        ['batch' => $batch] = app(BillingDetailsImportService::class)->register($path, 'bench-'.$customers.'.xlsx', null);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $t = microtime(true);
        $done = app(BillingDetailsImportService::class)->process($batch);
        $seconds = microtime(true) - $t;
        $growth = memory_get_peak_usage() - $base;

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $done->status, (string) $done->error_message);
        $this->assertSame($customers, $done->control_totals['scan']['customers']);
        $this->assertSame($customers, (int) $done->detailRollups()->sum('customers'));

        return ['customers' => $customers, 'seconds' => round($seconds, 2), 'growth' => $growth, 'rollups' => $done->detailRollups()->count(), 'file' => (int) filesize($path), 'written' => round($written, 2)];
    }

    public function test_memory_does_not_follow_the_number_of_customers(): void
    {
        $small = $this->measure(3000);
        // the second import is a different period so it is its own batch; the staging table of the first is already emptied
        DB::table('commercial_import_batches')->update(['status' => CommercialImportBatch::STATUS_VOIDED]);
        $large = $this->measure(12000);

        $mb = fn (int $bytes) => round($bytes / 1048576, 1).' MB';
        fwrite(STDERR, sprintf("\n[billing details memory] 3,000 rows: %s, %d rollups, %ss | 12,000 rows: %s, %d rollups, %ss\n", $mb($small['growth']), $small['rollups'], $small['seconds'], $mb($large['growth']), $large['rollups'], $large['seconds']));

        $this->assertLessThan(48 * 1048576, $large['growth'], 'the import of 12,000 customers needed '.$mb($large['growth']));
        // four times the customers must not need four times the memory: the growth is the keys (about the same) plus a chunk, not the rows
        $this->assertLessThan(12 * 1048576, $large['growth'] - $small['growth'], '12,000 rows needed '.$mb($large['growth'] - $small['growth']).' more than 3,000');
        // a few dozen keys per route, not a row per customer
        $this->assertLessThan($large['customers'] / 4, $large['rollups']);
    }

    public function test_benchmark_a_file_of_seventy_thousand_customers(): void
    {
        if (getenv('GWL_BENCH_BILLING_DETAILS') !== '1') {
            $this->markTestSkipped('Set GWL_BENCH_BILLING_DETAILS=1 to run the 70,000-row benchmark.');
        }

        $r = $this->measure(70000);
        $mb = fn (int $bytes) => round($bytes / 1048576, 1);

        fwrite(STDERR, sprintf(
            "\n[billing details benchmark] %s customers in %d routes | file %s MB, written in %ss | import %ss (%s rows/s) | PHP memory growth %s MB | %s rollups\n",
            number_format($r['customers']), (int) ceil($r['customers'] / 400), $mb($r['file']), $r['written'], $r['seconds'], number_format((int) ($r['customers'] / max($r['seconds'], 0.01))), $mb($r['growth']), number_format($r['rollups'])
        ));

        $this->assertLessThan(30 * 1048576, $r['growth'], 'flat memory: about 10-15 MB for 70,000 rows');
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count(), 'the staging table is emptied when the batch finishes');
    }
}
