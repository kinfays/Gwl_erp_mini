<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingAnalyticsService;
use Illuminate\Support\Collection;

/**
 * The billing numbers, hand-computed from the fixture in CommercialTestCase::billingFixture():
 *
 *   SOWUTUOM 1: billing 1000, volume 100 (60 actual, 40 average), billed 10 (4 + 1 average, 5 actual), paid 800 (600 + 200 earlier), closing 200
 *   SOWUTUOM 2: billing 100,  volume 10 (all average), billed 2 (average), unbilled 8, paid 0, closing 100
 *   ODORKOR 1:  billing 500,  volume 50 (all actual), billed 5 (actual), paid 400 (100 + 300 earlier), opening -400, closing -300
 *   ODORKOR 2:  nothing billed, opening -50, closing -50
 *
 *   Whole snapshot: billing 1600, paid 1200 (700 this period, 500 earlier), volume 160 (50 average), billed 17 (7 estimated), unbilled 8.
 */
class BillingAnalyticsTest extends CommercialTestCase
{
    private BillingAnalyticsService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billing = new BillingAnalyticsService;
    }

    protected function routes(CommercialImportBatch $batch): Collection
    {
        return CommercialBillingRoute::query()->where('batch_id', $batch->id)->with('district')->get();
    }

    protected function fixtureRoutes(): Collection
    {
        return $this->routes($this->seedBilling($this->billingFixture()));
    }

    // ---------------------------------------------------------------- B1 + B8

    public function test_overview_totals_ratios_and_ranking(): void
    {
        $overview = $this->billing->overview($this->fixtureRoutes());

        $t = $overview['totals'];
        $this->assertSame(1600.0, $t['billing_for_period']);
        $this->assertSame(160.0, $t['volume_total']);
        $this->assertSame(17.0, $t['billed_total']);
        $this->assertSame(94.12, $t['per_customer'], '1600 / 17');
        $this->assertSame(10.0, $t['per_m3'], '1600 / 160: thousand litres are m3');
        $this->assertSame(4, $t['routes']);

        $districts = collect($overview['districts'])->keyBy('key');
        $this->assertSame(['SOWUTUOM', 'ODORKOR'], array_column($overview['districts'], 'key'), 'ranked by billing');
        $this->assertSame(1100.0, $districts['SOWUTUOM']['billing']);
        $this->assertSame(68.75, $districts['SOWUTUOM']['share']);
        $this->assertSame(91.67, $districts['SOWUTUOM']['per_customer'], 'district per-customer is billing / billed of the district: 1100 / 12');
        $this->assertSame(10.0, $districts['SOWUTUOM']['per_m3'], '1100 / 110');
        $this->assertSame(100.0, $districts['ODORKOR']['per_customer'], '500 / 5');

        $this->assertSame(['SOWUTUOM 1', 'ODORKOR 1', 'SOWUTUOM 2', 'ODORKOR 2'], array_column($overview['routes'], 'route'));
        $this->assertSame(62.5, $overview['routes'][0]['share']);
        $this->assertNull(collect($overview['routes'])->firstWhere('route', 'ODORKOR 2')['per_customer'], 'a route with nobody billed has no per-customer figure');
        $this->assertNull(collect($overview['routes'])->firstWhere('route', 'ODORKOR 2')['per_m3']);
    }

    public function test_routes_and_districts_can_be_ranked_by_volume_or_customers_billed(): void
    {
        $routes = $this->fixtureRoutes();

        $byVolume = $this->billing->overview($routes, 'volume');
        $this->assertSame(['SOWUTUOM 1', 'ODORKOR 1', 'SOWUTUOM 2', 'ODORKOR 2'], array_column($byVolume['routes'], 'route'));

        $byBilled = $this->billing->overview($routes, 'billed');
        $this->assertSame('SOWUTUOM', $byBilled['districts'][0]['key']);
        $this->assertSame(['SOWUTUOM 1', 'ODORKOR 1', 'SOWUTUOM 2', 'ODORKOR 2'], array_column($byBilled['routes'], 'route'));
    }

    public function test_pareto_is_the_billing_share_of_the_top_ten_routes(): void
    {
        $routes = [];

        foreach (range(1, 12) as $i) {
            // Routes 1-10 bill 100 each, routes 11 and 12 bill 50 each: 1,100 in all, the top ten carry 1,000.
            $routes[] = ['district' => 'D', 'code' => 'R'.$i, 'billing_for_period' => $i <= 10 ? 100 : 50];
        }

        $pareto = $this->billing->overview($this->routes($this->seedBilling($routes)))['pareto'];

        $this->assertSame(10, $pareto['routes']);
        $this->assertSame(12, $pareto['total_routes']);
        $this->assertSame(1000.0, $pareto['billing']);
        $this->assertSame(90.91, $pareto['share']);

        $small = $this->billing->overview($this->fixtureRoutes())['pareto'];
        $this->assertSame(4, $small['routes']);
        $this->assertSame(100.0, $small['share']);
    }

    // ---------------------------------------------------------------- B2

    public function test_the_roll_forward_follows_opening_billing_adjustment_payments_closing(): void
    {
        $roll = collect($this->billing->rollForward($this->fixtureRoutes())['districts'])->keyBy('key');

        $this->assertSame([0.0, 1100.0, 0.0, 1100.0, 800.0, 300.0], [$roll['SOWUTUOM']['opening'], $roll['SOWUTUOM']['billing'], $roll['SOWUTUOM']['adjustment'], $roll['SOWUTUOM']['receivable'], $roll['SOWUTUOM']['payments'], $roll['SOWUTUOM']['closing']]);
        $this->assertSame([-450.0, 500.0, 50.0, 400.0, -350.0], [$roll['ODORKOR']['opening'], $roll['ODORKOR']['billing'], $roll['ODORKOR']['receivable'], $roll['ODORKOR']['payments'], $roll['ODORKOR']['closing']]);
        $this->assertTrue($roll['SOWUTUOM']['balances']);
        $this->assertTrue($roll['ODORKOR']['balances']);

        $total = $this->billing->rollForward($this->fixtureRoutes())['total'];
        $this->assertSame(-450.0 + 0.0, $total['opening']);
        $this->assertSame(-50.0, $total['closing']);
    }

    // ---------------------------------------------------------------- B3 + B4

    public function test_collection_ratios_come_from_the_sums_not_from_the_average_of_route_ratios(): void
    {
        $collections = $this->billing->collections($this->fixtureRoutes());
        $total = $collections['total'];

        $this->assertSame(75.0, $total['cash_ratio'], '1200 / 1600');
        $this->assertSame(43.75, $total['current_ratio'], '700 / 1600');
        $this->assertSame(41.67, $total['prior_share'], '500 / 1200 of the cash was arrears from earlier months');
        $this->assertSame(95.0, $collections['target']);

        // The routes' own ratios are 80%, 0%, 80% (and undefined): their average is 53.33, not 75.
        $routeRatios = $this->fixtureRoutes()->map(fn ($r) => (float) $r->billing_for_period > 0 ? (float) $r->total_payments / (float) $r->billing_for_period * 100 : null)->filter(fn ($v) => $v !== null);
        $this->assertSame(53.33, round($routeRatios->avg(), 2));
        $this->assertNotSame($routeRatios->avg(), $total['cash_ratio']);

        $districts = collect($collections['districts'])->keyBy('key');
        $this->assertSame(72.73, $districts['SOWUTUOM']['cash_ratio'], '800 / 1100');
        $this->assertSame(80.0, $districts['ODORKOR']['cash_ratio']);
        $this->assertSame(75.0, $districts['ODORKOR']['prior_share'], '300 of the 400 paid came from earlier months');
    }

    public function test_zero_denominators_return_null_never_an_error(): void
    {
        $routes = $this->routes($this->seedBilling([['district' => 'D', 'code' => 'ZERO']]));

        $collections = $this->billing->collections($routes)['total'];
        $this->assertNull($collections['cash_ratio']);
        $this->assertNull($collections['current_ratio']);
        $this->assertNull($collections['prior_share']);

        $estimation = $this->billing->estimationAndUnbilled($routes)['total'];
        $this->assertNull($estimation['unbilled_rate']);
        $this->assertNull($estimation['estimation_volume']);
        $this->assertNull($estimation['estimation_count']);
        $this->assertNull($estimation['mix']['actual']);

        $overview = $this->billing->overview($routes);
        $this->assertNull($overview['totals']['per_customer']);
        $this->assertNull($overview['pareto']['share']);

        $this->assertNull(BillingAnalyticsService::ratio(5, 0));
        $this->assertSame([], $this->billing->overview(collect())['routes']);
        $this->assertSame([], $this->billing->exceptions(collect())['rows']);
        $this->assertFalse($this->billing->bands(collect())['has_bands']);
    }

    // ---------------------------------------------------------------- B5 + B6 + B10

    public function test_estimation_unbilled_and_the_billed_mix(): void
    {
        $result = $this->billing->estimationAndUnbilled($this->fixtureRoutes());
        $total = $result['total'];

        $this->assertSame(31.25, $total['estimation_volume'], '50 average / 160 total');
        $this->assertSame(41.18, $total['estimation_count'], '(4 + 1 + 2) / 17');
        $this->assertSame(32.0, $total['unbilled_rate'], '8 / (17 + 8)');
        $this->assertSame(8, $total['reasons']['unbilled_suspense_metered']['count']);
        $this->assertSame(100.0, $total['reasons']['unbilled_suspense_metered']['share']);
        $this->assertSame(0.0, $total['reasons']['unbilled_other']['share']);
        $this->assertSame([6, 1, 10], [$total['avg_metered'], $total['avg_unmetered'], $total['actual']]);
        $this->assertSame([35.29, 5.88, 58.82], [$total['mix']['avg_metered'], $total['mix']['avg_unmetered'], $total['mix']['actual']], '6, 1 and 10 of 17 customers billed');
        $this->assertSame(110.0, $total['volume_actual']);
        $this->assertSame(50.0, $total['volume_average']);

        $districts = collect($result['districts'])->keyBy('key');
        $this->assertSame(45.45, $districts['SOWUTUOM']['estimation_volume'], '50 / 110');
        $this->assertSame(58.33, $districts['SOWUTUOM']['estimation_count'], '7 / 12');
        $this->assertSame(40.0, $districts['SOWUTUOM']['unbilled_rate'], '8 / (12 + 8)');
        $this->assertSame(0.0, $districts['ODORKOR']['estimation_volume']);
        $this->assertSame(0.0, $districts['ODORKOR']['unbilled_rate']);
    }

    // ---------------------------------------------------------------- B7

    public function test_credits_count_negative_closing_balances_only(): void
    {
        $balances = $this->billing->balances($this->fixtureRoutes());

        $this->assertSame(2, $balances['total']['credit_routes'], 'ODORKOR 1 (-300) and ODORKOR 2 (-50); the +200 and +100 closings are not credits');
        $this->assertSame(350.0, $balances['total']['credit_amount']);
        $this->assertSame(-50.0, $balances['total']['closing'], '200 + 100 - 300 - 50');
        $this->assertSame(50.0, $balances['credit_share']);
        $this->assertSame(['ODORKOR 1', 'ODORKOR 2'], array_column($balances['largest'], 'route'));
        $this->assertSame([300.0, 50.0], array_column($balances['largest'], 'credit'));

        $districts = collect($balances['districts'])->keyBy('key');
        $this->assertSame(0, $districts['SOWUTUOM']['credit_routes']);
        $this->assertSame(0.0, $districts['SOWUTUOM']['credit_amount']);
    }

    public function test_only_the_ten_largest_credits_are_listed(): void
    {
        $routes = array_map(fn ($i) => ['district' => 'D', 'code' => 'C'.$i, 'closing_balance' => -$i], range(1, 12));

        $balances = $this->billing->balances($this->routes($this->seedBilling($routes)));

        $this->assertCount(10, $balances['largest']);
        $this->assertSame('C12', $balances['largest'][0]['route']);
        $this->assertSame(12, $balances['total']['credit_routes']);
    }

    // ---------------------------------------------------------------- B9

    public function test_band_metrics_shares_and_gh_per_m3(): void
    {
        $batch = $this->seedBilling($this->billingFixture(), options: ['bands' => [['<=5', 80, 400, 3000], ['>5', 20, 600, 7000]]]);

        $bands = $this->billing->bands(CommercialBillingBand::query()->where('batch_id', $batch->id)->get());

        $this->assertTrue($bands['has_bands']);
        $this->assertSame('611', $bands['category']);

        [$low, $high] = $bands['rows'];
        $this->assertSame([80.0, 40.0, 30.0], [$low['customer_share'], $low['volume_share'], $low['amount_share']]);
        $this->assertSame([20.0, 60.0, 70.0], [$high['customer_share'], $high['volume_share'], $high['amount_share']]);
        $this->assertSame(7.5, $low['per_m3'], '3000 / 400');
        $this->assertSame(11.67, $high['per_m3'], '7000 / 600: the higher tariff band');
        $this->assertSame(37.5, $low['per_customer']);
        $this->assertSame(350.0, $high['per_customer']);

        $this->assertSame([100, 1000.0, 10000.0, 10.0, 100.0], [$bands['total']['customers'], $bands['total']['volume'], $bands['total']['amount'], $bands['total']['per_m3'], $bands['total']['per_customer']]);
    }

    public function test_a_snapshot_without_a_band_table_says_so(): void
    {
        $batch = $this->seedBilling($this->billingFixture(), options: ['bands' => false]);

        $this->assertFalse($this->billing->bands(CommercialBillingBand::query()->where('batch_id', $batch->id)->get())['has_bands']);
    }

    // ---------------------------------------------------------------- B11

    public function test_exceptions_use_the_thresholds_including_the_minimum_customers_rule(): void
    {
        $result = $this->billing->exceptions($this->fixtureRoutes());
        $rows = collect($result['rows'])->keyBy('route');

        // SOWUTUOM 2: 8 of 10 customers unbilled (80% > 25%). Its bills are all estimated, but only 2 customers are
        // billed (< 3), so the estimation percentage is not judged.
        $this->assertSame(['high_unbilled'], $rows['SOWUTUOM 2']['flags']);
        // ODORKOR 1: closing -300 is exactly the heavy-credit amount (300), which counts.
        $this->assertSame(['heavy_credit'], $rows['ODORKOR 1']['flags']);
        // ODORKOR 2: nothing billed and no volume; its -50 is not heavy.
        $this->assertSame(['zero_activity'], $rows['ODORKOR 2']['flags']);
        // SOWUTUOM 1 is 50% estimated and nothing unbilled: clean.
        $this->assertArrayNotHasKey('SOWUTUOM 1', $rows->all());

        $this->assertSame(['zero_activity' => 1, 'heavy_credit' => 1, 'high_unbilled' => 1, 'high_estimation' => 0], $result['counts']);
        $this->assertSame(4, $result['routes']);
    }

    public function test_a_one_customer_route_is_never_flagged_on_a_percentage(): void
    {
        $routes = $this->routes($this->seedBilling([
            // 100% estimated, one customer billed.
            ['district' => 'D', 'code' => 'ONE-BILLED', 'volume_total' => 5, 'billed_average_metered' => 1, 'billed_total' => 1],
            // 100% unbilled, one customer.
            ['district' => 'D', 'code' => 'ONE-UNBILLED', 'volume_total' => 5, 'unbilled_suspense_metered' => 1, 'unbilled_total' => 1],
            // Four customers, all estimated: a real flag.
            ['district' => 'D', 'code' => 'FOUR-ESTIMATED', 'volume_total' => 5, 'billed_average_metered' => 4, 'billed_total' => 4],
            // Two billed, one unbilled = three customers behind the unbilled rate (33%): judged, flagged.
            ['district' => 'D', 'code' => 'THREE-BEHIND', 'volume_total' => 5, 'billed_average_metered' => 1, 'billed_actual_reading' => 1, 'billed_total' => 2, 'unbilled_other' => 1, 'unbilled_total' => 1],
        ]));

        $rows = collect($this->billing->exceptions($routes)['rows'])->keyBy('route');

        $this->assertArrayNotHasKey('ONE-BILLED', $rows->all());
        $this->assertArrayNotHasKey('ONE-UNBILLED', $rows->all());
        $this->assertSame(['high_estimation'], $rows['FOUR-ESTIMATED']['flags']);
        $this->assertSame(['high_unbilled'], $rows['THREE-BEHIND']['flags']);
    }

    public function test_the_thresholds_come_from_config(): void
    {
        config(['gwl.commercial_exception_credit_amount' => 40, 'gwl.commercial_exception_high_unbilled_pct' => 90]);

        $result = $this->billing->exceptions($this->fixtureRoutes());
        $rows = collect($result['rows'])->keyBy('route');

        $this->assertSame(['zero_activity', 'heavy_credit'], $rows['ODORKOR 2']['flags'], '-50 is a heavy credit once the amount is 40');
        $this->assertArrayNotHasKey('SOWUTUOM 2', $rows->all(), '80% unbilled is under a 90% threshold');
        $this->assertSame(40.0, $result['thresholds']['credit_amount']);
    }

    // ---------------------------------------------------------------- snapshots

    public function test_snapshots_pick_the_newest_batch_per_key_and_ignore_voided_and_superseded_ones(): void
    {
        $this->seedBilling([['district' => 'D', 'code' => 'OLD']], '2026-09-01');                                               // replaced below
        $replacement = $this->seedBilling([['district' => 'D', 'code' => 'NEW']], '2026-09-01');
        $this->seedBilling([['district' => 'D', 'code' => 'VOID']], '2026-08-01', ['status' => CommercialImportBatch::STATUS_VOIDED]);
        $superseded = $this->seedBilling([['district' => 'D', 'code' => 'SUP']], '2026-07-01', ['status' => CommercialImportBatch::STATUS_SUPERSEDED]);
        $july = $this->seedBilling([['district' => 'D', 'code' => 'JULY']], '2026-07-01');
        $this->seedBilling([['district' => 'D', 'code' => 'ALL']], '2026-09-01', ['segment' => 'all']);
        $this->seedBilling([['district' => 'D', 'code' => 'ASH']], '2026-09-01', ['region' => $this->ashanti]);

        $snapshots = collect($this->billing->snapshots(CommercialImportBatch::query()->where('region_id', $this->accraWest->id)));

        $ids = $snapshots->pluck('id')->all();
        $this->assertContains($replacement->id, $ids);
        $this->assertContains($july->id, $ids);
        $this->assertNotContains($superseded->id, $ids, 'a superseded batch never shows');
        $this->assertCount(3, $snapshots, 'Sep new_service, Sep all, Jul new_service: not the old Sep, the voided Aug, the superseded Jul or Ashanti');
        $this->assertSame($replacement->id, $snapshots->first(fn ($s) => $s['segment'] === 'new_service' && $s['month'] === '2026-09-01')['id']);
        $this->assertSame('2026-07-01', $snapshots->last()['month'], 'newest period first');
    }

    public function test_the_default_snapshot_is_the_latest_single_month_else_the_latest(): void
    {
        $this->seedBilling([['district' => 'D', 'code' => 'P']], '2026-06-01', ['period_to' => '2026-08-31']);
        $single = $this->seedBilling([['district' => 'D', 'code' => 'S']], '2026-07-01');

        $snapshots = $this->billing->snapshots(CommercialImportBatch::query());
        $this->assertSame($single->id, $this->billing->defaultSnapshot($snapshots)['id'], 'the single month beats the later-ending period');

        $onlyPeriod = $this->billing->snapshots(CommercialImportBatch::query()->where('id', '!=', $single->id));
        $this->assertFalse($this->billing->defaultSnapshot($onlyPeriod)['is_single_month']);

        $this->assertNull($this->billing->defaultSnapshot([]));
    }

    public function test_a_multi_month_snapshot_is_flagged_and_labelled_as_a_period(): void
    {
        $this->seedBilling([['district' => 'D', 'code' => 'P']], '2026-06-01', ['period_to' => '2026-08-31']);

        $snapshot = $this->billing->snapshots(CommercialImportBatch::query())[0];

        $this->assertFalse($snapshot['is_single_month']);
        $this->assertSame('Jun to Aug 2026', $snapshot['period_label']);
        $this->assertStringContainsString('(period)', $snapshot['label']);
        $this->assertStringContainsString('New Service', $snapshot['label']);
    }

    // ---------------------------------------------------------------- B12

    public function test_compare_works_for_two_single_month_snapshots_of_the_same_region_and_segment(): void
    {
        $august = $this->seedBilling([
            ['district' => 'SOWUTUOM', 'code' => 'S1', 'billing_for_period' => 1000, 'total_payments' => 500, 'volume_total' => 100, 'volume_average' => 50, 'billed_total' => 9, 'unbilled_total' => 1],
        ], '2026-08-01');
        $september = $this->seedBilling([
            ['district' => 'SOWUTUOM', 'code' => 'S1', 'billing_for_period' => 1200, 'total_payments' => 900, 'volume_total' => 100, 'volume_average' => 20, 'billed_total' => 8, 'unbilled_total' => 2],
            ['district' => 'ODORKOR', 'code' => 'O1', 'billing_for_period' => 300, 'total_payments' => 0],
        ], '2026-09-01');

        $a = ['meta' => $this->billing->describe($august), 'routes' => $this->routes($august)];
        $b = ['meta' => $this->billing->describe($september), 'routes' => $this->routes($september)];

        foreach ([$this->billing->compare($a, $b), $this->billing->compare($b, $a)] as $result) {
            $this->assertTrue($result['ok'], 'the earlier month is the baseline whichever order they are passed');
            $this->assertSame($august->id, $result['base']['id']);
            $this->assertSame($september->id, $result['latest']['id']);

            $districts = collect($result['districts'])->keyBy('key');
            $s = $districts['SOWUTUOM'];
            $this->assertSame([1000.0, 1200.0, 200.0, 20.0], [$s['billing_before'], $s['billing_after'], $s['billing_change'], $s['billing_change_pct']]);
            $this->assertSame([400.0], [$s['payments_change']]);
            $this->assertSame([50.0, 75.0, 25.0], [$s['cash_ratio_before'], $s['cash_ratio_after'], $s['cash_ratio_change']]);
            $this->assertSame(-30.0, $s['estimation_change'], '50% -> 20% estimated by volume');
            $this->assertSame(10.0, $s['unbilled_change'], '10% -> 20% unbilled');

            $o = $districts['ODORKOR'];
            $this->assertSame([0.0, 300.0, 300.0], [$o['billing_before'], $o['billing_after'], $o['billing_change']]);
            $this->assertNull($o['billing_change_pct'], 'no percentage change from nothing');
            $this->assertNull($o['cash_ratio_before']);
            $this->assertNull($o['cash_ratio_change']);

            $this->assertSame(1500.0, $result['total']['billing_after']);
        }
    }

    public function test_compare_refuses_multi_month_other_segments_other_regions_and_the_same_snapshot(): void
    {
        $sep = $this->seedBilling([['district' => 'D', 'code' => 'A']], '2026-09-01');
        $aug = $this->seedBilling([['district' => 'D', 'code' => 'A']], '2026-08-01');
        $period = $this->seedBilling([['district' => 'D', 'code' => 'A']], '2026-06-01', ['period_to' => '2026-08-31']);
        $otherSegment = $this->seedBilling([['district' => 'D', 'code' => 'A']], '2026-08-01', ['segment' => 'all']);
        $otherRegion = $this->seedBilling([['district' => 'D', 'code' => 'A']], '2026-08-01', ['region' => $this->ashanti]);

        $pack = fn (CommercialImportBatch $batch) => ['meta' => $this->billing->describe($batch), 'routes' => $this->routes($batch)];

        $this->assertTrue($this->billing->compare($pack($sep), $pack($aug))['ok']);

        $this->assertStringContainsString('cannot be compared or trended', $this->billing->compare($pack($sep), $pack($period))['reason']);
        $this->assertStringContainsString('same region and customer segment', $this->billing->compare($pack($sep), $pack($otherSegment))['reason']);
        $this->assertStringContainsString('same region and customer segment', $this->billing->compare($pack($sep), $pack($otherRegion))['reason']);
        $this->assertStringContainsString('two different snapshots', $this->billing->compare($pack($sep), $pack($sep))['reason']);
    }

    public function test_the_trend_needs_three_single_month_snapshots_and_leaves_out_multi_month_periods(): void
    {
        $pack = fn (CommercialImportBatch $batch) => ['meta' => $this->billing->describe($batch), 'routes' => $this->routes($batch)];
        $make = fn (string $month, float $billing, float $paid, array $options = []) => $pack($this->seedBilling([['district' => 'D', 'code' => 'R', 'billing_for_period' => $billing, 'total_payments' => $paid, 'payment_for_month' => $paid / 2, 'billed_total' => 9, 'unbilled_total' => 1]], $month, $options));

        $period = $make('2026-06-01', 999, 999, ['period_to' => '2026-08-31']);
        $jul = $make('2026-07-01', 1000, 500);
        $aug = $make('2026-08-01', 1000, 800);

        $short = $this->billing->trend([$period, $jul, $aug]);
        $this->assertFalse($short['ok'], 'two single months and a period is not three months');
        $this->assertStringContainsString('at least 3 single-month snapshots', $short['reason']);
        $this->assertStringContainsString('there are 2', $short['reason']);

        $sep = $make('2026-09-01', 1000, 900);
        $trend = $this->billing->trend([$sep, $period, $jul, $aug]);

        $this->assertTrue($trend['ok']);
        $this->assertSame(['Jul 2026', 'Aug 2026', 'Sep 2026'], array_column($trend['months'], 'label'), 'ascending, without the multi-month period');
        $this->assertSame([50.0, 80.0, 90.0], array_column($trend['months'], 'cash_ratio'));
        $this->assertSame([25.0, 40.0, 45.0], array_column($trend['months'], 'current_ratio'));
        $this->assertSame([10.0, 10.0, 10.0], array_column($trend['months'], 'unbilled_rate'));
    }

    // ---------------------------------------------------------------- the customers_count rule

    public function test_customers_count_is_never_part_of_any_analysis(): void
    {
        $this->assertNotContains('customers_count', BillingAnalyticsService::SUM_FIELDS);

        $routes = $this->fixtureRoutes();
        $this->assertSame(7777, $routes->first()->customers_count, 'sanity: the column is stored');

        foreach ([
            $this->billing->overview($routes), $this->billing->rollForward($routes), $this->billing->collections($routes),
            $this->billing->balances($routes), $this->billing->estimationAndUnbilled($routes), $this->billing->exceptions($routes),
        ] as $result) {
            $this->assertStringNotContainsString('7777', json_encode($result));
            $this->assertStringNotContainsString('customers_count', json_encode($result));
        }
    }
}
