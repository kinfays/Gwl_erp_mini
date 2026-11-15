<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\CommercialCustomerUpload;
use App\Models\District;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Support\Carbon;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * A MEASUREMENT, not a check: it is skipped unless GWL_BENCH_REGION_WIDE=1 is set, so the normal suite never pays for it.
 *
 *   GWL_BENCH_REGION_WIDE=1 php artisan test --filter=CustomerRegionWideBenchmarkTest
 *
 * It imports a synthetic 70,000-row file once as ONE district and once as FIVE districts (the same rows), through the real pipeline on the
 * in-memory SQLite of the test environment, and prints the time of each phase and the memory growth. The numbers are recorded in
 * docs/commercial-module-design.md section 8.33; they say how the pipeline scales, not how fast a production MySQL server is.
 */
class CustomerRegionWideBenchmarkTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GWL_BENCH_REGION_WIDE') !== '1') {
            $this->markTestSkipped('A measurement: set GWL_BENCH_REGION_WIDE=1 to run it.');
        }
    }

    /** @return array{seconds: float, memory: float, timings: array<string, float>, reads: int, stage_reads: int, rows: int, districts: int} */
    protected function run70k(string $path, string $asOf): array
    {
        $imports = app(CustomerImportService::class);
        ['batch' => $batch, 'upload' => $upload] = $imports->register($path, 'bench.xlsx', 'monthly', Carbon::parse($asOf), null);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = microtime(true);
        $imports->process($batch);
        $seconds = microtime(true) - $start;
        $growth = (memory_get_peak_usage() - $before) / 1048576;

        $upload->refresh();
        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status, json_encode([$upload->errors, $upload->unrecognised, $upload->warnings]));

        return [
            'seconds' => $seconds, 'memory' => $growth, 'timings' => $imports->timings, 'reads' => $imports->sheetReads, 'stage_reads' => $imports->stageReads,
            'rows' => (int) $upload->batches()->sum('rows_read'), 'districts' => $upload->batches()->count(),
        ];
    }

    public function test_a_seventy_thousand_row_file_as_one_district_and_as_five(): void
    {
        foreach (['Kaneshie', 'Bench Four', 'Bench Five'] as $name) {
            District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => $name]);
        }

        $started = microtime(true);
        $one = ReportWorkbooks::customerListStreamed(70000, 'SOWUTUOM', 'ACCRA WEST', 400, 1);
        $five = ReportWorkbooks::customerListRegionStreamed(70000, ['SOWUTUOM', 'ODORKOR', 'KANESHIE', 'BENCH FOUR', 'BENCH FIVE'], 'ACCRA WEST', 400, 1000000);
        $this->workbooks[] = $one;
        $this->workbooks[] = $five;
        fwrite(STDERR, sprintf("\nfiles written in %.1f s (%.1f MB and %.1f MB)\n", microtime(true) - $started, filesize($one) / 1048576, filesize($five) / 1048576));

        $a = $this->run70k($one, '2026-10-05');
        $b = $this->run70k($five, '2026-10-05');

        foreach (['one district (a file with a DISTRICT: line)' => $a, 'five districts (region-wide, no DISTRICT: line)' => $b] as $label => $r) {
            fwrite(STDERR, sprintf(
                "\n%s: %s rows, %d batch(es)\n  total %.1f s, memory growth %.1f MB, workbook opened %d times (%d for staging)\n  phases: %s\n",
                $label, number_format($r['rows']), $r['districts'], $r['seconds'], $r['memory'], $r['reads'], $r['stage_reads'],
                implode(', ', array_map(fn (string $k, float $v) => sprintf('%s %.1f s', $k, $v), array_keys($r['timings']), $r['timings']))
            ));
        }

        $this->assertSame(1, $b['stage_reads']);
        $this->assertSame(70000, $b['rows']);
        $this->assertSame(5, $b['districts']);
    }
}
