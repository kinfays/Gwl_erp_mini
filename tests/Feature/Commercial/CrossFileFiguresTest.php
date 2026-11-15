<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\Cross\CrossFileReport;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsCrossSet;

/**
 * Phase 8e: every figure of the analyses X1 and X3 to X12 on the invented cross-file set (CrossSampleSet), each worked out BY HAND in the comments
 * from the design of the set (see its doc comment). Districts: Sowutuom (S) and Odorkor (O); routes S1 = 4101, S2 = 4102, S3 = 4103, O1 = 4201,
 * O2 = 4202, O3 = 4203, O4 = 4204, S9 = 4109 (reading only), O0 = 0000.
 *
 * THE SET IN NUMBERS
 *   reading (Oct 2026)    CCA 80001: S1 (strength 10, read 10, skipped 0, visited 10) and S2 (10, 9, 1, 10)       -> visited 20, skipped 1, skip 5%, coverage 100%
 *                         CCA 80002: S3 (10, 8, 2, 10) [and a stale second row on S1: strength 9, no visits]    -> visited 10, skipped 2, skip 20%
 *                         CCA 80003: O1 (10, 7, 3, 10) and O2 (10, 6, 4, 10)                                    -> visited 20, skipped 7, skip 35%
 *                         CCA 80004: O3 (10, 0, 0, 0) and S9 (8, 0, 0, 0): zero visits, unvisited 10 + 8 = 18
 *   billing (Sep 2026)    every billed customer owes 57.20; the routes: S1 11 customers (10 billed, 1 ACTB unbilled), S2 11 (10 billed, 1 HOLD unbilled),
 *                         S3 10, O1 10, O2 10, O3 5, O4 3, O0 2 = 62 customers, 60 billed. Estimated on a WORKING meter: S1 0, S2 1, S3 3, O1 2, O2 4;
 *                         one ESTIMATE on a faulty meter (O1). Unpaid: S1 2, S2 1, O2 2 (5 in all). Account 49 (S3) is DISC but billed.
 *   customer list (15 Nov)  S 48 accounts (4101: 12, 4102: 11, 4103: 10, 4202 printed under S: 10, 4999: 5), O 19 (4201: 10, 4203: 5, 4204: 3, 0000: 1)
 */
class CrossFileFiguresTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsCrossSet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->setUpBillingDetails();
        $this->loadCrossSet();
        config([
            'gwl.commercial_min_visits_for_ranking' => 10, 'gwl.commercial_cross_min_route_customers' => 5, 'gwl.commercial_strength_mismatch_pct' => 15,
            'gwl.commercial_target_skip_rate_pct' => 15, 'gwl.commercial_target_coverage_pct' => 95, 'gwl.commercial_target_collection_pct' => 92,
            'gwl.commercial_exception_high_estimation_pct' => 25, 'gwl.commercial_exception_high_unbilled_pct' => 5,
        ]);
        $this->actingAs($this->crossAll());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function part(string $key, string $part, array $q = []): array
    {
        $r = app(CrossFileReport::class)->build($key, $q);
        $p = $r['parts'][$part];
        $this->assertTrue($p['visible'] && ! $p['empty'], $key.'.'.$part.': '.($p['reason'] ?? ''));

        return $p['data'];
    }

    // ---------------------------------------------------------------- X1

    public function test_x1_each_cca_reading_figures_beside_the_billing_figures_of_the_routes_it_owns(): void
    {
        $rows = collect($this->part('x1', 'combined')['rows'])->keyBy('staff_id');

        // 80001 owns S1 + S2: visited 10 + 10 = 20, skipped 0 + 1 = 1, skip 1 / 20 = 5%, coverage 20 / 20 strength = 100%, unvisited 0, rank 1 (lowest skip rate)
        $this->assertSame([20, 1, 5.0, 100.0, 0, 1], [$rows['80001']['visited'], $rows['80001']['skipped'], $rows['80001']['skip_rate'], $rows['80001']['coverage'], $rows['80001']['unvisited'], $rows['80001']['rank']]);
        // billing on S1 + S2: customers 11 + 11 = 22, billed 10 + 10 = 20; estimated 0 + 1 = 1 of 20 = 5% (all on a working meter: 5%); unbilled 2 of 22 = 9.09%;
        // month total 20 x 57.20 = 1144.00; paid S1 8 + S2 9 = 17 x 57.20 = 972.40, so 972.40 / 1144 = 85%; owing S1 (1, 2 unpaid = 114.40 + acct 11 balance 100) + S2 (22 = 57.20) = 271.60 in 4 accounts
        $b = $rows['80001']['billing'];
        $this->assertSame([22, 20, 5.0, 5.0, 9.09, 85.0, 1144.0, 972.4, 271.6, 4], [$b['customers'], $b['billed'], $b['estimated_share'], $b['wm_share'], $b['unbilled_share'], $b['collection_ratio'], $b['month_total'], $b['paid'], $b['owing_amount'], $b['owing']]);

        // 80002 owns S3 (the stale second row on S1 has no visits and does not count): 10 visits, 2 skipped = 20%, rank 2; billing: 10 customers, 3 estimated on working meters = 30%, all paid
        $this->assertSame([10, 20.0, 2], [$rows['80002']['visited'], $rows['80002']['skip_rate'], $rows['80002']['rank']]);
        $this->assertSame([10, 30.0, 30.0, 100.0, 0.0], [$rows['80002']['billing']['customers'], $rows['80002']['billing']['estimated_share'], $rows['80002']['billing']['wm_share'], $rows['80002']['billing']['collection_ratio'], $rows['80002']['billing']['owing_amount']]);

        // 80003 owns O1 + O2: visited 20, skipped 3 + 4 = 7 = 35%, rank 3; billing: estimated O1 3 (2 working + 1 faulty) + O2 4 = 7 of 20 = 35%; working-meter 2 + 4 = 6 of 20 = 30%;
        // paid O1 10 + O2 8 = 18 x 57.20 = 1029.60 of 1144 = 90%; owing 2 x 57.20 = 114.40
        $this->assertSame([20, 35.0, 3], [$rows['80003']['visited'], $rows['80003']['skip_rate'], $rows['80003']['rank']]);
        $this->assertSame([20, 35.0, 30.0, 90.0, 114.4, 2], [$rows['80003']['billing']['customers'], $rows['80003']['billing']['estimated_share'], $rows['80003']['billing']['wm_share'], $rows['80003']['billing']['collection_ratio'], $rows['80003']['billing']['owing_amount'], $rows['80003']['billing']['owing']]);

        // 80004 owns O3 + S9 and made no visit: no skip rate, no rank, coverage 0 of strength 18, unvisited 18; billing only on O3 (S9 is not billed): 5 customers
        $this->assertSame([0, null, 0.0, 18, null], [$rows['80004']['visited'], $rows['80004']['skip_rate'], $rows['80004']['coverage'], $rows['80004']['unvisited'], $rows['80004']['rank']]);
        $this->assertSame([1, 5], [$rows['80004']['billing']['routes'], $rows['80004']['billing']['customers']]);

        // 80005 has no route: not on the list
        $this->assertArrayNotHasKey('80005', $rows->all());

        // the scatter: one point per CCA with a skip rate and a billing figure: (5, 5), (20, 30), (35, 30)
        $this->assertSame([[5.0, 5.0], [20.0, 30.0], [35.0, 30.0]], array_map(fn ($p) => [$p['x'], $p['y']], $this->part('x1', 'combined')['scatter']));
    }

    // ---------------------------------------------------------------- X3

    public function test_x3_the_correlation_of_skip_rate_with_estimation_route_by_route(): void
    {
        $d = $this->part('x3', 'correlation');

        // the five routes read AND billed with at least 5 customers and a skip rate (O3 has no visits): x = skip rate 0, 10, 20, 30, 40 on S1, S2, S3, O1, O2
        // y (working-meter estimates of the billed) = 0, 10, 30, 20, 40; all estimates z = 0, 10, 30, 30, 40
        // r(x, y): means 20 and 20; sum of dx dy = 400 + 100 + 0 + 0 + 400 = 900; sum dx^2 = sum dy^2 = 1000; r = 900 / 1000 = 0.9
        // r(x, z): mean z = 22; sum dx dz = 440 + 120 + 0 + 80 + 360 = 1000; sum dz^2 = 484 + 144 + 64 + 64 + 324 = 1080; r = 1000 / sqrt(1000 x 1080) = 0.9622
        $this->assertSame(5, $d['overall']['routes']);
        $this->assertSame(0.9, $d['overall']['r_wm']);
        $this->assertSame(0.962, $d['overall']['r_est']);
        $this->assertSame(1, $d['left_out_no_visits'], 'O3 has no visits, so no skip rate');
        $this->assertSame(0, $d['left_out_small']);

        // a district needs 10 routes of its own: Sowutuom has 3 and Odorkor 2, so neither gets a coefficient
        $this->assertSame([false, false], array_column($d['by_district'], 'enough'));
        $this->assertSame([null, null], array_column($d['by_district'], 'r_wm'));
        $this->assertSame([2, 3], array_column($d['by_district'], 'routes'));

        // the smallest route that counts is a setting: 11 customers leaves out the routes of 10 (and fewer)
        config(['gwl.commercial_cross_min_route_customers' => 11]);
        $small = $this->part('x3', 'correlation');
        $this->assertSame(2, $small['overall']['routes'], 'only S1 and S2 have 11 customers');
        $this->assertSame(3, $small['left_out_small']);
        $this->assertNull($small['overall']['r_wm'], 'a coefficient needs at least 3 routes');
    }

    // ---------------------------------------------------------------- X4

    public function test_x4_strength_against_metered_accounts_and_billing_customers(): void
    {
        $d = $this->part('x4', 'reconciliation');
        $rows = collect($d['rows'])->keyBy('label');

        // S1: strength 10; the list holds 12 accounts of which account 5 has NO meter: 11 metered; billing has 11 customers: 10 - 11 = -1 (9.09% of 11)
        $this->assertSame([10, 11, 11, -1, 9.09, -1, 9.09], [$rows['SOWUTUOM 4101']['strength'], $rows['SOWUTUOM 4101']['metered'], $rows['SOWUTUOM 4101']['billing_customers'], $rows['SOWUTUOM 4101']['diff_metered'], $rows['SOWUTUOM 4101']['pct_metered'], $rows['SOWUTUOM 4101']['diff_billing'], $rows['SOWUTUOM 4101']['pct_billing']]);
        $this->assertSame([10, 11, 11], [$rows['SOWUTUOM 4102']['strength'], $rows['SOWUTUOM 4102']['metered'], $rows['SOWUTUOM 4102']['billing_customers']]);
        $this->assertSame([0, 0], [$rows['SOWUTUOM 4103']['diff_metered'], $rows['ODORKOR 4201']['diff_billing']]);
        // O3: strength 10 against 5 metered accounts and 5 billing customers: +5 (100% of 5), beyond the 15% limit
        $this->assertSame([10, 5, 5, 5, 100.0, true], [$rows['ODORKOR 4203']['strength'], $rows['ODORKOR 4203']['metered'], $rows['ODORKOR 4203']['billing_customers'], $rows['ODORKOR 4203']['diff_metered'], $rows['ODORKOR 4203']['pct_metered'], $rows['ODORKOR 4203']['beyond']]);
        // O2's code is printed under two districts: its list accounts may belong to either (flagged), though they are counted under the canonical route (10)
        $this->assertTrue($rows['ODORKOR 4202']['aliased']);
        $this->assertSame(10, $rows['ODORKOR 4202']['metered']);
        // S9 is in reading only: no list or billing count, never a made-up zero
        $this->assertNull($rows['SOWUTUOM 4109']['metered']);
        $this->assertNull($rows['SOWUTUOM 4109']['billing_customers']);
        $this->assertFalse($rows['SOWUTUOM 4109']['beyond']);

        // the three counts over the six routes that have all three: strength 60, metered 11 + 11 + 10 + 10 + 10 + 5 = 57, billing 11 + 11 + 10 + 10 + 10 + 5 = 57
        $this->assertSame([6, 60, 57, 57], [$d['totals']['routes_with_all_three'], $d['totals']['strength'], $d['totals']['metered'], $d['totals']['billing_customers']]);
        // medians over the six routes: differences -1, -1, 0, 0, 0, 5 -> 0; absolute % 9.09, 9.09, 0, 0, 0, 100 -> sorted 0, 0, 0, 9.09, 9.09, 100 -> (0 + 9.09) / 2 = 4.545
        $this->assertSame(0.0, $d['medians']['strength_minus_metered']);
        $this->assertSame(0.0, $d['medians']['strength_minus_billing']);
        $this->assertSame(4.545, $d['medians']['pct_metered']);
        $this->assertSame(['ODORKOR 4203'], array_column($d['beyond'], 'label'));
    }

    // ---------------------------------------------------------------- X5

    public function test_x5_accounts_in_one_file_and_not_the_other_and_what_moved(): void
    {
        $d = $this->part('x5', 'link');
        $byName = fn (array $rows) => collect($rows)->keyBy('district');

        // billing district S: 11 + 11 + 10 = 32 accounts, all in the list; O: 10 + 10 + 5 + 3 + 2 = 30, account 122 is not in the list
        $this->assertSame([32, 32, 0], array_values(array_intersect_key($byName($d['accounts'])['Sowutuom'], array_flip(['accounts', 'in_list', 'not_in_list']))));
        $this->assertSame([30, 29, 1], array_values(array_intersect_key($byName($d['accounts'])['Odorkor'], array_flip(['accounts', 'in_list', 'not_in_list']))));
        // the list's BILLING-status accounts that are not in the file: S 12 (new) and 201 to 204 (list-only route) = 5 (205 is DISC, not a billing status); O none
        $this->assertSame([['district' => 'Sowutuom', 'accounts' => 5, 'district_id' => $this->sowutuom->id]], array_map(fn ($r) => ['district' => $r['district'], 'accounts' => $r['accounts'], 'district_id' => $r['district_id']], $d['list_not_in_file']));

        // compared: S 32, O 29 = 61. Status moved: S accounts 3 (ACTB to DISC) and 49 (DISC to ACTB) = 2; O account 66 (ACTB to DISC) = 1. Category moved: O account 65 (611 to 612) = 1
        $dist = $byName($d['districts']);
        $this->assertSame([32, 2, 0], [$dist['Sowutuom']['compared'], $dist['Sowutuom']['status_changed'], $dist['Sowutuom']['category_changed']]);
        $this->assertSame([29, 1, 1], [$dist['Odorkor']['compared'], $dist['Odorkor']['status_changed'], $dist['Odorkor']['category_changed']]);
        $this->assertSame([61, 3, 1], [$d['totals']['compared'], $d['totals']['status_changed'], $d['totals']['category_changed']]);

        // the from-to matrix of the status, Sowutuom: ACTB to ACTB 29 (32 - 3 others: 3, 49, 31), ACTB to DISC 1, DISC to ACTB 1, HOLD to HOLD 1
        $matrix = collect($d['matrix']['status'])->where('district', 'Sowutuom')->mapWithKeys(fn ($r) => [$r['from'].'>'.$r['to'] => $r['customers']])->all();
        $this->assertEquals(['ACTB>DISC' => 1, 'DISC>ACTB' => 1, 'ACTB>ACTB' => 29, 'HOLD>HOLD' => 1], $matrix);
        // and the service category, Odorkor: account 65 moved 611 to 612, the other 28 compared accounts did not
        $this->assertEquals(['611>612' => 1, '611>611' => 28], collect($d['matrix']['category'])->where('district', 'Odorkor')->mapWithKeys(fn ($r) => [$r['from'].'>'.$r['to'] => $r['customers']])->all());

        // the capped list: accounts 3, 49 and 66 (account numbers only), a route and a district each
        $this->assertSame(['100000000003', '100000000049', '100000000066'], collect($d['changed_accounts'])->pluck('account')->sort()->values()->all());
    }

    // ---------------------------------------------------------------- X6

    public function test_x6_status_exceptions_per_route_and_per_cca(): void
    {
        $d = $this->part('x6', 'exceptions');
        $routes = collect($d['routes'])->keyBy('label');
        $ccas = collect($d['ccas'])->keyBy('staff_id');

        // 60 accounts hold a billing status (62 less the HOLD account 31 and the DISC account 49); one is unbilled (account 11 on S1); one DISC account is billed (49 on S3); one is on HOLD (31 on S2)
        $this->assertSame([60, 1, 1, 1], [$d['totals']['active_billing'], $d['totals']['unbilled_active'], $d['totals']['billed_not_active'], $d['totals']['hold']]);
        // S1: 11 active accounts, 1 unbilled = 9.09% of the active; S3: 9 active (49 is DISC), 1 billed not active = 10% of its 10 billed; S2: 1 HOLD of 11 = 9.09%
        $this->assertSame([11, 1, 9.09], [$routes['SOWUTUOM 4101']['active_billing'], $routes['SOWUTUOM 4101']['unbilled_active'], $routes['SOWUTUOM 4101']['unbilled_active_rate']]);
        $this->assertSame([9, 1, 10.0], [$routes['SOWUTUOM 4103']['active_billing'], $routes['SOWUTUOM 4103']['billed_not_active'], $routes['SOWUTUOM 4103']['billed_not_active_rate']]);
        $this->assertSame([1, 9.09], [$routes['SOWUTUOM 4102']['hold'], $routes['SOWUTUOM 4102']['hold_rate']]);
        // per CCA (RouteCcaResolver: the owner of the route for the bill period): 80001 owns S1 + S2: 21 active, 1 unbilled = 4.76%, 1 HOLD of 22 = 4.55%; 80002 owns S3: 1 billed not active = 10%
        $this->assertSame([21, 1, 4.76, 1, 4.55], [$ccas['80001']['active_billing'], $ccas['80001']['unbilled_active'], $ccas['80001']['unbilled_active_rate'], $ccas['80001']['hold'], $ccas['80001']['hold_rate']]);
        $this->assertSame([1, 10.0], [$ccas['80002']['billed_not_active'], $ccas['80002']['billed_not_active_rate']]);
        // the routes no CCA reads (O4 and 0000) are one row of their own
        $this->assertSame([2, 5], [$ccas['']['routes'], $ccas['']['customers']]);

        // billed as active, now disconnected (from the link): accounts 3 (S1) and 66 (O1) = 2 of the 61 linked
        $m = $this->part('x6', 'moved');
        $this->assertSame([2, 61], [$m['totals']['now_disconnected'], $m['totals']['compared']]);
        $moved = collect($m['routes'])->keyBy('label');
        $this->assertSame([11, 1, 9.09], [$moved['SOWUTUOM 4101']['compared'], $moved['SOWUTUOM 4101']['now_disconnected'], $moved['SOWUTUOM 4101']['now_disconnected_rate']]);
        $this->assertSame([1, 10.0], [$moved['ODORKOR 4201']['now_disconnected'], $moved['ODORKOR 4201']['now_disconnected_rate']]);
        $this->assertSame(0, $moved['SOWUTUOM 4103']['now_disconnected'], 'account 49 went the other way (DISC to ACTB)');
        $byCca = collect($m['ccas'])->keyBy('staff_id');
        $this->assertSame([1, 1], [$byCca['80001']['now_disconnected'], $byCca['80003']['now_disconnected']]);

        // the lists of the billing report: the active unbilled account and the billed disconnected one
        $this->assertSame(['100000000011'], array_column($d['lists']['active_unbilled']['rows'], 'account'));
        $this->assertSame(['100000000049'], array_column($d['lists']['billed_not_active']['rows'], 'account'));
    }

    // ---------------------------------------------------------------- X7 and X11

    public function test_x7_the_district_scorecard_from_the_real_routes_against_the_targets(): void
    {
        $rows = collect($this->part('x7', 'scorecard')['rows'])->keyBy('district');
        $s = $rows['Sowutuom'];
        $o = $rows['Odorkor'];

        // reading, Sowutuom: S1 + S2 + S3 + S9: strength 10 + 10 + 10 + 8 = 38, visited 30, skipped 0 + 1 + 2 = 3: coverage 30 / 38 = 78.95%, skip 3 / 30 = 10%, unvisited 8
        $this->assertSame([78.95, 10.0, 8], [$s['reading']['coverage'], $s['reading']['skip_rate'], $s['reading']['unvisited']]);
        // reading, Odorkor: O1 + O2 + O3: strength 30, visited 20, skipped 7: coverage 66.67%, skip 35%, unvisited 10
        $this->assertSame([66.67, 35.0, 10], [$o['reading']['coverage'], $o['reading']['skip_rate'], $o['reading']['unvisited']]);
        // billing, Sowutuom: 32 customers; billed 30, estimated 0 + 1 + 3 = 4 of 30 = 13.33%; unbilled 2 of 32 = 6.25%; month 30 x 57.20 = 1716; paid (8 + 9 + 10) x 57.20 = 1544.40 = 90%; owing 214.40 + 57.20 = 271.60 = 15.83% of 1716
        $this->assertSame([32, 13.33, 6.25, 90.0, 271.6, 15.83], [$s['billing']['customers'], $s['billing']['estimated_share'], $s['billing']['unbilled_share'], $s['billing']['collection_ratio'], $s['billing']['owing_amount'], $s['billing']['owing_share']]);
        // billing, Odorkor: 30 customers, all billed; estimated 3 + 4 = 7 of 30 = 23.33%; month 1716; paid (10 + 8 + 5 + 3 + 2) x 57.20 = 1601.60 = 93.33%; owing 114.40 = 6.67%
        $this->assertSame([30, 23.33, 0.0, 93.33, 114.4, 6.67], [$o['billing']['customers'], $o['billing']['estimated_share'], $o['billing']['unbilled_share'], $o['billing']['collection_ratio'], $o['billing']['owing_amount'], $o['billing']['owing_share']]);
        // the customer list (by the LIST's district): Sowutuom 48 accounts, 45 with a billing status (DISC 3 and 205, HOLD 31 are not), 47 of 48 contactable (account 1 has no mobile) = 97.9%
        $this->assertSame([48, 45, 97.9], [$s['customers']['base'], $s['customers']['billing'], $s['customers']['contactable_share']]);
        $this->assertSame([19, 18, 100.0], [$o['customers']['base'], $o['customers']['billing'], $o['customers']['contactable_share']]);

        // colour against the targets (skip at most 15, coverage at least 95, collection at least 92, estimated at most 25, unbilled at most 5)
        $this->assertSame(['skip_rate' => 'good', 'coverage' => 'bad', 'collection_ratio' => 'bad', 'estimated_share' => 'good', 'unbilled_share' => 'bad'], $s['judged']);
        $this->assertSame(['skip_rate' => 'bad', 'coverage' => 'bad', 'collection_ratio' => 'good', 'estimated_share' => 'good', 'unbilled_share' => 'good'], $o['judged']);
        $this->assertArrayNotHasKey('score', $s, 'no composite score');
    }

    public function test_x11_the_executive_summary_adds_the_flags_that_need_attention(): void
    {
        $r = app(CrossFileReport::class)->build('x11', []);
        $flags = collect($r['parts']['flags']['data']['flags'])->pluck('count', 'key');

        // routes billed with no CCA reading them: O4 and 0000 = 2; CCAs with routes and no visits: 80004 = 1; no skip outlier (too few CCAs); active unbilled 1 (account 11);
        // no route fails its totals; nothing is unplaced; stale uploads: the reading upload ends 31 Oct (16 days old on 16 Nov, over the 10-day limit) and the billing one 30 Sep (47 days, over 35); the list is 1 day old
        $this->assertSame(2, $flags['routes_no_cca']);
        $this->assertSame(1, $flags['ccas_no_visits']);
        $this->assertSame(0, $flags['skip_outliers']);
        $this->assertSame(1, $flags['active_unbilled']);
        $this->assertSame(0, $flags['routes_failing_totals']);
        $this->assertSame(0, $flags['unplaced_billing']);
        $this->assertSame(2, $flags['stale_uploads']);
        $this->assertCount(2, collect($r['parts']['flags']['data']['flags'])->firstWhere('key', 'stale_uploads')['detail']);
        // the same headline rows as X7
        $this->assertSame(['Odorkor', 'Sowutuom'], collect($r['parts']['districts']['data']['rows'])->pluck('district')->all());
    }

    // ---------------------------------------------------------------- X8

    public function test_x8_arrears_per_cca_portfolio(): void
    {
        $d = $this->part('x8', 'portfolio');
        $rows = collect($d['rows'])->keyBy('staff_id');

        // the owing balance of the routes in view: S1 214.40 + S2 57.20 + O2 114.40 = 386.00 (account 11's previous balance 100 and five unpaid bills of 57.20)
        $this->assertSame(386.0, $d['owing_total']);
        // 80001 (S1 + S2): 271.60 in 4 accounts = 271.60 / 386 = 70.36%; collection 972.40 / 1144 = 85%; its arrears are on S1 (214.40 = 78.94%) and S2 (57.20 = 21.06%)
        $this->assertSame([271.6, 4, 70.36, 85.0], [$rows['80001']['owing_amount'], $rows['80001']['owing'], $rows['80001']['share_of_owing'], $rows['80001']['collection_ratio']]);
        $this->assertSame(['SOWUTUOM 4101', 'SOWUTUOM 4102'], array_column($rows['80001']['top_routes'], 'label'));
        $this->assertSame([78.94, 21.06], array_column($rows['80001']['top_routes'], 'share_of_cca'));
        // 80003 (O1 + O2): 114.40 in 2 accounts = 29.64%, all on O2
        $this->assertSame([114.4, 2, 29.64], [$rows['80003']['owing_amount'], $rows['80003']['owing'], $rows['80003']['share_of_owing']]);
        $this->assertSame(['ODORKOR 4202'], array_column($rows['80003']['top_routes'], 'label'));
        // a CCA with nothing owing has no route to name
        $this->assertSame([0.0, []], [$rows['80002']['owing_amount'], $rows['80002']['top_routes']]);
        // the routes no CCA reads are a portfolio of their own, last
        $this->assertSame('', array_key_last($rows->all()));
    }

    // ---------------------------------------------------------------- X9

    public function test_x9_read_recency_against_visits(): void
    {
        $d = $this->part('x9', 'routes');
        $rows = collect($d['routes'])->keyBy('label');

        // O1: accounts 66 and 67 were last read on 1 June (121 days before the end of September), account 68 never: 2 over 90 days + 1 never = 3 of 10 = 30%; reading coverage 100%
        $this->assertSame([10, 2, 1, 30.0, 100.0, 0], [$rows['ODORKOR 4201']['customers'], $rows['ODORKOR 4201']['read_over_90'], $rows['ODORKOR 4201']['read_never'], $rows['ODORKOR 4201']['stale_share'], $rows['ODORKOR 4201']['coverage'], $rows['ODORKOR 4201']['unvisited']]);
        // O3 was never reached: coverage 0 and 10 unvisited, though its 5 reads are recent
        $this->assertSame([0, 0.0, 10, '80004'], [$rows['ODORKOR 4203']['read_over_90'], $rows['ODORKOR 4203']['coverage'], $rows['ODORKOR 4203']['unvisited'], $rows['ODORKOR 4203']['owner_cca']]);
        // a route with no reading row has billing figures and no coverage
        $this->assertSame([3, null, false], [$rows['ODORKOR 4204']['customers'], $rows['ODORKOR 4204']['coverage'], $rows['ODORKOR 4204']['has_reading']]);

        $ccas = collect($this->part('x9', 'ccas')['rows'])->keyBy('staff_id');
        // 80003 owns O1 + O2 (20 customers): 2 over 90 days + 1 never = 3 of 20 = 15%; its coverage is 100% (20 visits on a strength of 20)
        $this->assertSame([20, 2, 1, 15.0, 100.0], [$ccas['80003']['customers'], $ccas['80003']['read_over_90'], $ccas['80003']['read_never'], $ccas['80003']['stale_share'], $ccas['80003']['coverage']]);
    }

    // ---------------------------------------------------------------- X10

    public function test_x10_the_district_disagreement_matrix_and_the_route_evidence(): void
    {
        $r = app(CrossFileReport::class)->build('x10', []);
        $m = $r['parts']['matrix']['data'];
        $rows = collect($m['rows'])->keyBy('list_district');

        // rows are the LIST's district: Sowutuom 42 linked accounts (4101: 12 less the 0 missing... 1-12 = 12, 4102: 11, 4103: 10, 4202 printed under it: 10 = 43, less account 12 not billed = 42)
        // of which the billing puts 32 in Sowutuom (76.19%) and 10 in Odorkor (route 4202); Odorkor 19, all in Odorkor
        $this->assertSame([42, 32, 76.19], [$rows['Sowutuom']['total'], $rows['Sowutuom']['agree'], $rows['Sowutuom']['agree_share']]);
        $this->assertSame([19, 19, 100.0], [$rows['Odorkor']['total'], $rows['Odorkor']['agree'], $rows['Odorkor']['agree_share']]);
        $off = collect($rows['Sowutuom']['cells'])->firstWhere('billing_district_id', $this->odorkor->id);
        $this->assertSame([10, 23.81, false], [$off['customers'], $off['share'], $off['diagonal']]);
        $this->assertTrue(collect($rows['Sowutuom']['cells'])->firstWhere('billing_district_id', $this->sowutuom->id)['diagonal']);
        $this->assertSame([61, 51, 83.61, 10, 16.39], [$m['total'], $m['agree'], $m['agree_share'], $m['disagree'], $m['disagree_share']]);

        // the route master: the code 4202 is printed under both districts (billing and reading say Odorkor, the list says Sowutuom); route 0000: 2 customers in billing, 1 in the list
        $master = $r['parts']['master']['data'];
        $this->assertSame([['4202', 'Odorkor', 'Sowutuom', 10, 10]], array_map(fn ($c) => [$c['code'], $c['canonical_district'], $c['other_districts_text'], $c['billing_customers'], $c['list_accounts']], $master['codes']));
        $this->assertSame(['billing_customers' => 2, 'list_accounts' => 1], $master['zero']);

        // routes known to one file only
        $c = $r['parts']['coverage']['data'];
        $this->assertEqualsCanonicalizing(['ODORKOR 0000', 'ODORKOR 4204'], array_column($c['billing_without_reading'], 'label'), 'billed, no CCA reads them');
        $this->assertSame(['SOWUTUOM 4109'], array_column($c['reading_without_billing'], 'label'));
        $this->assertSame(['SOWUTUOM 4999'], array_column($c['list_in_neither'], 'label'));
        $this->assertSame(['Sowutuom'], array_column($c['list_in_neither'], 'district'));
    }

    // ---------------------------------------------------------------- X12

    public function test_x12_the_detailed_billing_against_the_billing_summary(): void
    {
        // a summary for September with figures a little different from the details
        $this->seedBilling([
            ['district' => 'SOWUTUOM', 'code' => 'SOWUTUOM 1', 'billed_total' => 29, 'billing_for_period' => 1700.00, 'payment_for_month' => 1544.40, 'closing_balance' => 280.00],
            ['district' => 'ODORKOR', 'code' => 'ODORKOR 1', 'billed_total' => 30, 'billing_for_period' => 1716.00, 'payment_for_month' => 1601.60, 'closing_balance' => 114.40],
        ], '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);

        $d = $this->part('x12', 'compare');
        $this->assertTrue($d['available']);
        $rows = collect($d['rows'])->keyBy('district');

        // details, Sowutuom: billed 30, month total 1716.00, paid 1544.40, balance due 271.60; summary: 29, 1700.00, 1544.40, 280.00
        // differences: +1 customer, +16.00 (0.94% of 1700), 0.00, -8.40 (-3% of 280)
        $s = $rows['Sowutuom']['cells'];
        $this->assertSame([30, 29, 1.0], [$s['billed']['details'], $s['billed']['summary'], $s['billed']['difference']]);
        $this->assertSame([1716.0, 1700.0, 16.0, 0.94], [$s['billing']['details'], $s['billing']['summary'], $s['billing']['difference'], $s['billing']['difference_pct']]);
        $this->assertSame(0.0, $s['payments']['difference']);
        $this->assertSame([271.6, 280.0, -8.4, -3.0], [$s['closing']['details'], $s['closing']['summary'], $s['closing']['difference'], $s['closing']['difference_pct']]);
        // Odorkor agrees to the pesewa
        $this->assertSame([0.0, 0.0, 0.0, 0.0], array_map(fn ($m) => $rows['Odorkor']['cells'][$m]['difference'], ['billed', 'billing', 'payments', 'closing']));
        // the total: billed 60 against 59, billing 3432.00 against 3416.00 (+16.00), payments equal, closing 386.00 against 394.40 (-8.40)
        $t = $d['total']['cells'];
        $this->assertEquals([60, 59, 3432.0, 3416.0, 16.0], [$t['billed']['details'], $t['billed']['summary'], $t['billing']['details'], $t['billing']['summary'], $t['billing']['difference']]);
        $this->assertEquals([386.0, 394.4, -8.4], [$t['closing']['details'], $t['closing']['summary'], $t['closing']['difference']]);
        $this->assertTrue($d['summary_batches'][0]['same_dates']);
    }

    public function test_x12_says_so_when_there_is_no_summary_for_the_period(): void
    {
        $d = $this->part('x12', 'compare');
        $this->assertFalse($d['available']);
        // the details are still shown, with nothing to set them against: a dash, never an invented zero
        $this->assertSame([false, false], array_column($d['rows'], 'both'));
        $this->assertSame([null, null], array_column(array_column(array_column($d['rows'], 'cells'), 'billed'), 'summary'));
        $this->assertNull($d['total']['cells']['billing']['difference']);
    }
}
