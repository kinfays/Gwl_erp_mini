<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingAnalyticsService;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Characterisation test for Phase 8a (district scope, route master, period model): on the existing monthly sample data every
 * analysis and export must give exactly what it gave before. The fixture is built once, every export workbook and the billing
 * analyses are reduced to a hash of their cells / figures, and the hashes were taken BEFORE the phase-8a code was written.
 * A hash that moves means a figure moved: look at the JSON the failure prints.
 */
class ExistingFiguresPinnedTest extends CommercialTestCase
{
    /** @var array<string, string> filled in from the pre-phase-8a run */
    private const PINNED = [
        'export:summary' => 'f1c9e4ce736010d6d0c2b7af32334a62',
        'export:reading-trend' => '141d79b878b92d28a5470e469036cb87',
        'export:readers' => '6824d34b1a20dca0b8970bd19e78213e',
        'export:billing' => '0d180ea672f8fa0f3499b38ce88919ae',
        'export:kpis' => '3e7200183eb9a0f582c0a91db4880b0b',
        'export:scorecard' => 'f20f01b13febe2df1e83c662670598de',
        'snapshots' => '6bfea39f4192cca4f5cf9f8c796c03db',
        'billing:7' => '694a5928cec301392a12396b6295fa9c',
        'billing:5' => 'f68b6bac389aedfbeefd907559f85afc',
        'billing:3' => '698a198c0e4a057327b53326616b92d5',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function fixture(): void
    {
        $names = ['R1' => 'ADWOA BOATENG', 'R2' => 'KWESI PAINTSIL', 'R3' => 'AMA SERWAA', 'R4' => 'KOFI MENSAH'];
        $districts = ['R1' => $this->sowutuom->id, 'R2' => $this->odorkor->id, 'R3' => $this->sowutuom->id, 'R4' => $this->odorkor->id];

        // Jun to Sep, then a refresh covering Jul to Oct (Jul to Sep are replaced by the newer batch, Oct is in progress)
        $this->seedReading([
            'R1' => ['2026-06-01' => [400, 100], '2026-07-01' => [410, 90], '2026-08-01' => [420, 80], '2026-09-01' => [450, 50]],
            'R2' => ['2026-06-01' => [200, 100], '2026-07-01' => [210, 110], '2026-08-01' => [190, 120], '2026-09-01' => [220, 80]],
            'R3' => ['2026-06-01' => [300, 20], '2026-07-01' => [310, 25], '2026-08-01' => [305, 30], '2026-09-01' => [320, 22]],
            'R4' => ['2026-06-01' => [0, 0], '2026-07-01' => [150, 10], '2026-08-01' => [160, 12], '2026-09-01' => [0, 0]],
        ], options: ['strength' => ['2026-06-01' => 1200, '2026-07-01' => 1210, '2026-08-01' => 1220, '2026-09-01' => 1230], 'names' => $names, 'districts' => $districts]);

        $this->seedReading([
            'R1' => ['2026-07-01' => [415, 85], '2026-08-01' => [421, 79], '2026-09-01' => [455, 45], '2026-10-01' => [120, 10]],
            'R2' => ['2026-07-01' => [212, 108], '2026-08-01' => [191, 119], '2026-09-01' => [222, 78], '2026-10-01' => [60, 30]],
            'R3' => ['2026-07-01' => [311, 24], '2026-08-01' => [306, 29], '2026-09-01' => [321, 21], '2026-10-01' => [90, 5]],
        ], options: ['strength' => ['2026-07-01' => 1210, '2026-08-01' => 1220, '2026-09-01' => 1230, '2026-10-01' => 1240], 'names' => $names, 'districts' => $districts]);

        $labels = ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id];
        $bands = [['<=5', 80, 410.5, 3450.25], ['>5', 20, 300.75, 3460.0]];

        $scale = fn (float $factor) => array_map(function (array $r) use ($factor) {
            foreach (['volume_actual', 'volume_average', 'volume_total', 'billing_for_period', 'payment_for_month', 'total_payments'] as $f) {
                $r[$f] = ($r[$f] ?? 0) * $factor;
            }

            $r['total_receivable'] = $r['opening_balance'] + $r['billing_for_period'] + $r['revenue_adjustment'];
            $r['closing_balance'] = $r['total_receivable'] - $r['total_payments'];

            return $r;
        }, $this->billingFixture());

        // in the order of the sample pack: the three-month period Jun to Aug first, then the single months Jul, Aug, Sep, then a
        // replacement of September (the same period again: the newest wins)
        $this->seedBilling($scale(2.9), '2026-06-01', ['districts' => $labels, 'bands' => $bands, 'period_to' => '2026-08-31']);
        $this->seedBilling($scale(0.9), '2026-07-01', ['districts' => $labels, 'bands' => $bands]);
        $this->seedBilling($scale(1.0), '2026-08-01', ['districts' => $labels, 'bands' => $bands]);
        $this->seedBilling($scale(1.1), '2026-09-01', ['districts' => $labels, 'bands' => $bands]);
        $this->seedBilling($scale(1.2), '2026-09-01', ['districts' => $labels, 'bands' => $bands]);
    }

    /** @return array<string, mixed> */
    protected function figures(): array
    {
        $officer = $this->officer();
        $out = [];

        foreach ([['summary', 'excel'], ['reading-trend', 'excel'], ['readers', 'excel'], ['billing', 'excel'], ['kpis', 'excel'], ['scorecard', 'excel']] as [$report, $format]) {
            $response = $this->actingAs($officer)->get(route('commercial.export', ['report' => $report, 'format' => $format]));
            $status = $response->getStatusCode();

            $this->assertSame(200, $status, "export {$report} should download");

            $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
            $cells = [];

            foreach ($book->getAllSheets() as $sheet) {
                $cells[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
            }

            $out['export:'.$report] = $cells;
        }

        // the billing analyses of the default snapshot and the two before it
        $billing = app(BillingAnalyticsService::class);
        $snapshots = $billing->snapshots(CommercialImportBatch::query());
        $out['snapshots'] = array_map(fn (array $s) => [$s['id'], $s['period_from'], $s['period_to'], $s['is_single_month']], $snapshots);

        foreach (array_slice($snapshots, 0, 3) as $snapshot) {
            $routes = \App\Models\CommercialBillingRoute::query()->where('batch_id', $snapshot['id'])->with('district:id,district_name')->get();
            $out['billing:'.$snapshot['id']] = [
                'overview' => $billing->overview($routes, 'billing'), 'roll' => $billing->rollForward($routes), 'collections' => $billing->collections($routes),
                'balances' => $billing->balances($routes), 'estimation' => $billing->estimationAndUnbilled($routes), 'exceptions' => $billing->exceptions($routes),
            ];
        }

        return $out;
    }

    private function normalise(mixed $value): mixed
    {
        // objects (Carbon, models) would not hash the same twice: reduce everything to scalars and arrays
        return json_decode((string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }

    public function test_the_existing_figures_are_exactly_what_they_were_before_phase_8a(): void
    {
        $this->fixture();

        $hashes = [];

        foreach ($this->figures() as $key => $figures) {
            $hashes[$key] = md5((string) json_encode($this->normalise($figures)));
        }

        if (self::PINNED === []) {
            fwrite(STDERR, "\nPINNED = ".var_export($hashes, true)."\n");
            $this->markTestIncomplete('Pin the hashes printed above.');
        }

        foreach ($hashes as $key => $hash) {
            $this->assertSame(self::PINNED[$key] ?? null, $hash, "The figures of {$key} changed.");
        }

        $this->assertSame(array_keys(self::PINNED), array_keys($hashes));
    }
}
