<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\Trends\TrendReport;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8f: T5 rank movement and the T2 figures worked from reading uploads built straight in the database (invented CCAs and routes), including a CCA
 * that is new in the later period and one that is below the minimum of visits. Hand arithmetic:
 *
 *   week of 8 to 14 October (R2)             strength read skipped visited   skip rate
 *     C1 81001  S1 (Sowutuom)                  100     70    10      80       12.50
 *     C1 81001  S2                              60     40    20      60       33.33     CCA C1: 140 visited, 30 skipped = 21.43
 *     C2 81002  O1 (Odorkor)                   200    120    30     150       20.00     CCA C2: 20.00
 *     C3 81003  O2                             100     40    40      80       50.00     CCA C3: 50.00
 *     totals: visited 370, skipped 100 = 27.03%, strength 460, coverage 80.43%, unvisited 90
 *
 *   week of 22 to 28 October (R3)
 *     C1 S1 (100, 85, 10, 95) 10.53 and S2 (60, 40, 5, 45) 11.11     CCA C1: 140 visited, 15 skipped = 10.71
 *     C2 O1 (200, 100, 20, 120) 16.67                                CCA C2: 16.67
 *     C3 O2 (100, 52, 4, 56) 7.14                                    CCA C3: 7.14
 *     C4 81004 S3 (80, 50, 5, 55) 9.09                               CCA C4: 9.09, NEW
 *     C5 81005 S4 (80, 20, 1, 21)                                    21 visits: below the minimum of 50, not ranked
 *     totals: visited 392 (95+45+120+56+55+21), skipped 45 = 11.48%, strength 620, coverage 63.23%, unvisited 228
 */
class TrendsRanksTest extends CustomerTestCase
{
    use BuildsRouteReading;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        config(['gwl.commercial_min_visits_for_ranking' => 50]);

        $s = $this->sowutuom;
        $o = $this->odorkor;

        $this->seedRouteReading([
            ['81001', 'C ONE', 'S1', $s, 100, 70, 10, 80], ['81001', 'C ONE', 'S2', $s, 60, 40, 20, 60],
            ['81002', 'C TWO', 'O1', $o, 200, 120, 30, 150], ['81003', 'C THREE', 'O2', $o, 100, 40, 40, 80],
        ], ['from' => '2026-10-08', 'to' => '2026-10-14']);
        $this->seedRouteReading([
            ['81001', 'C ONE', 'S1', $s, 100, 85, 10, 95], ['81001', 'C ONE', 'S2', $s, 60, 40, 5, 45],
            ['81002', 'C TWO', 'O1', $o, 200, 100, 20, 120], ['81003', 'C THREE', 'O2', $o, 100, 52, 4, 56],
            ['81004', 'C FOUR', 'S3', $s, 80, 50, 5, 55], ['81005', 'C FIVE', 'S4', $s, 80, 20, 1, 21],
        ], ['from' => '2026-10-22', 'to' => '2026-10-28']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function viewer(array $extra = [], string $staffId = '900301', bool $districtLimited = false)
    {
        return $this->userWith($staffId, array_merge(['commercial.view_reading', 'commercial.export_reports'], $extra), $this->accraWest, $this->sowutuom, $districtLimited);
    }

    protected function ranks(string $part, array $q = []): array
    {
        $r = app(TrendReport::class)->build('t5', $q + ['region' => $this->accraWest->id]);

        return collect($r['parts'][$part]['data']['rows'])->keyBy('id')->all();
    }

    public function test_rank_movement_has_up_down_same_and_new(): void
    {
        $this->actingAs($this->viewer(['commercial.view_reader_performance']));
        $rows = $this->ranks('ccas');

        // before: C2 20.00 = 1, C1 21.43 = 2, C3 50.00 = 3; now: C3 7.14 = 1, C4 9.09 = 2, C1 10.71 = 3, C2 16.67 = 4
        $this->assertSame([1, 3, 'up', 2], [$rows['81003']['rank_now'], $rows['81003']['rank_then'], $rows['81003']['direction'], $rows['81003']['move']]);
        $this->assertSame([3, 2, 'down', -1], [$rows['81001']['rank_now'], $rows['81001']['rank_then'], $rows['81001']['direction'], $rows['81001']['move']]);
        $this->assertSame([4, 1, 'down', -3], [$rows['81002']['rank_now'], $rows['81002']['rank_then'], $rows['81002']['direction'], $rows['81002']['move']]);
        $this->assertSame([2, null, 'new', null], [$rows['81004']['rank_now'], $rows['81004']['rank_then'], $rows['81004']['direction'], $rows['81004']['move']]);
        $this->assertEquals(10.71, $rows['81001']['value_now']);
        $this->assertEquals(21.43, $rows['81001']['value_then']);
    }

    public function test_a_cca_below_the_minimum_of_visits_is_not_ranked(): void
    {
        $this->actingAs($this->viewer(['commercial.view_reader_performance']));
        $rows = $this->ranks('ccas');

        $this->assertNull($rows['81005']['rank_now'], '21 visits is below the minimum of 50');
        $this->assertSame('new', collect($rows)->get('81004')['direction']);
        $this->assertNotContains('81005', collect($rows)->filter(fn ($r) => $r['rank_now'] !== null)->keys()->all());
        $this->assertSame(4, collect($rows)->filter(fn ($r) => $r['rank_now'] !== null)->count());
    }

    public function test_route_and_district_ranks_and_the_ratio_by_hand(): void
    {
        $this->actingAs($this->viewer(['commercial.view_reader_performance']));
        $routes = collect($this->ranks('routes_skip'))->mapWithKeys(fn ($r) => [substr($r['label'], -2) => $r]);

        // routes now by skip rate (a route has no minimum of visits): S4 4.76, O2 7.14, S3 9.09, S1 10.53, S2 11.11, O1 16.67; before: S1 12.5, O1 20.0, S2 33.33, O2 50.0
        $this->assertSame([2, 4, 'up', 2], [$routes['O2']['rank_now'], $routes['O2']['rank_then'], $routes['O2']['direction'], $routes['O2']['move']]);
        $this->assertSame(['new', 1], [$routes['S4']['direction'], $routes['S4']['rank_now']]);
        $this->assertSame('new', $routes['S3']['direction']);
        $this->assertSame([4, 1, 'down'], [$routes['S1']['rank_now'], $routes['S1']['rank_then'], $routes['S1']['direction']]);
        $this->assertSame([6, 2, 'down'], [$routes['O1']['rank_now'], $routes['O1']['rank_then'], $routes['O1']['direction']]);

        $districts = collect($this->ranks('districts_skip'))->mapWithKeys(fn ($r) => [$r['label'] => $r]);
        // Sowutuom now (10+5+5+1) / (95+45+55+21) = 21 / 216 = 9.72%, before (10+20) / 140 = 21.43%; Odorkor now 24 / 176 = 13.64%, before 70 / 230 = 30.43%
        $this->assertEquals(9.72, $districts['Sowutuom']['value_now']);
        $this->assertEquals(21.43, $districts['Sowutuom']['value_then']);
        $this->assertEquals(13.64, $districts['Odorkor']['value_now']);
        $this->assertEquals(30.43, $districts['Odorkor']['value_then']);
        $this->assertSame([1, 1, 'same'], [$districts['Sowutuom']['rank_now'], $districts['Sowutuom']['rank_then'], $districts['Sowutuom']['direction']]);
    }

    public function test_t2_totals_from_the_two_weeks_by_hand(): void
    {
        $this->actingAs($this->viewer(['commercial.view_reader_performance']));
        $r = app(TrendReport::class)->build('t2', ['region' => $this->accraWest->id, 'kind' => 'reading']);
        $m = collect($r['parts']['reading']['data']['measures'])->keyBy('key');

        $this->assertEquals(11.48, $m['skip_rate']['now']);
        $this->assertEquals(27.03, $m['skip_rate']['then']);
        $this->assertEquals(63.23, $m['coverage']['now']);
        $this->assertEquals(80.43, $m['coverage']['then']);
        $this->assertSame(228, (int) $m['unvisited']['now']);
        $this->assertSame(90, (int) $m['unvisited']['then']);
        $this->assertSame('bad', $m['coverage']['tone']);
        $this->assertSame('bad', $m['unvisited']['tone']);
        $this->assertSame('good', $m['skip_rate']['tone']);

        // the CCA and route rows too: C4 and S3 and S4 are new (no row before: no change)
        $p = $r['parts']['reading']['data'];
        $ccaRows = collect($p['ccas'])->keyBy('id');
        $this->assertEquals(10.71, collect($ccaRows['81001']['measures'])->keyBy('key')['skip_rate']['now']);
        $this->assertNull(collect($ccaRows['81004']['measures'])->keyBy('key')['skip_rate']['then']);
        $this->assertNull(collect($ccaRows['81004']['measures'])->keyBy('key')['skip_rate']['change']);
    }

    public function test_the_ccas_part_needs_the_cca_performance_permission_and_the_rest_stay(): void
    {
        $this->actingAs($this->viewer([], '900302'));
        $r = app(TrendReport::class)->build('t5', ['region' => $this->accraWest->id]);

        $this->assertFalse($r['parts']['ccas']['visible']);
        $this->assertNull($r['parts']['ccas']['data']);
        $this->assertStringContainsString('commercial.view_reader_performance', $r['parts']['ccas']['reason']);
        $this->assertTrue($r['parts']['routes_skip']['visible']);
        $this->assertTrue($r['parts']['districts_skip']['visible']);
        $this->assertFalse($r['parts']['routes_collection']['visible'], 'billing is not held');
        $this->assertSame([], app(TrendReport::class)->build('t2', ['region' => $this->accraWest->id, 'kind' => 'reading'])['parts']['reading']['data']['ccas'], 'no CCA rows in T2 either');
        $this->assertStringNotContainsString('C ONE', json_encode($r));
    }

    public function test_a_district_user_sees_only_their_district_and_no_region_row(): void
    {
        $this->actingAs($this->viewer(['commercial.view_reader_performance'], '900303', true));
        $r = app(TrendReport::class)->build('t5', ['region' => $this->accraWest->id]);
        $rows = collect($r['parts']['districts_skip']['data']['rows']);

        $this->assertSame(['Sowutuom'], $rows->pluck('label')->all());
        $this->assertTrue($r['district_limited']);

        $ccas = collect($r['parts']['ccas']['data']['rows'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains('81001', $ccas);
        $this->assertNotContains('81002', $ccas, 'a CCA of Odorkor is not theirs');
    }

    public function test_t8_covers_the_older_reports_too_with_the_whole_month_as_the_reading_period(): void
    {
        // the by-month reading report keeps the first of the month as its date: its period is the whole month; imported 4 October = 4 days after September ended
        $reading = $this->seedReading(['R1' => ['2026-09-01' => [8, 2]]], null, ['districts' => ['R1' => $this->sowutuom->id]]);
        $reading->forceFill(['imported_at' => '2026-10-04 09:00:00'])->save();
        // a billing summary of September imported on 1 October: one day after its period ended
        $billing = $this->seedBilling([['district' => 'SOWUTUOM', 'code' => '1']], '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id]]);
        $billing->forceFill(['imported_at' => '2026-10-01 09:00:00'])->save();

        $this->actingAs($this->userWith('900311', ['commercial.view_reading', 'commercial.view_billing', 'commercial.export_reports'], $this->accraWest, $this->sowutuom));
        $parts = app(TrendReport::class)->build('t8', ['region' => $this->accraWest->id, 'month' => '2026-09'])['parts'];

        $legacy = $parts['reading_legacy']['data'];
        $this->assertSame(1, $legacy['total']);
        $this->assertSame(4, $legacy['uploads'][0]['lateness']);
        $this->assertTrue($legacy['uploads'][0]['late']);
        $this->assertSame(30, collect($legacy['districts'])->firstWhere('id', $this->sowutuom->id)['covered_days'], 'the whole of September');
        $this->assertSame([], collect($legacy['districts'])->firstWhere('id', $this->sowutuom->id)['gaps']);

        $summary = $parts['billing_summary']['data'];
        $this->assertSame([1, 1], [$summary['total'], $summary['on_time']]);
        $this->assertSame(1, $summary['uploads'][0]['lateness']);

        // the route-grain reading uploads of the class's setUp are there too (two weeks), and detailed billing has none: it says so, it does not invent a row
        $this->assertCount(2, $parts['reading_route']['data']['uploads']);
        $this->assertSame([], $parts['billing_details']['data']['uploads']);
    }
    public function test_the_t7_projection_uses_the_days_covered_and_t4_states_the_days(): void
    {
        $this->actingAs($this->viewer(['commercial.view_reader_performance'], '900304'));
        $p = app(TrendReport::class)->build('t7', ['region' => $this->accraWest->id])['parts']['reading']['data'];

        // 14 days covered (8 to 14 and 22 to 28 October), visited 370 + 392 = 762: 762 / 14 x 31 = 1687.29
        $this->assertSame(14, $p['covered_days']);
        $this->assertEquals(762, $p['measures'][0]['actual']);
        $this->assertEquals(1687.29, $p['measures'][0]['projection']);

        $w = collect(app(TrendReport::class)->build('t4', ['region' => $this->accraWest->id])['parts']['reading']['data']['windows'])->keyBy('days');
        $this->assertSame('14 of 30 days are covered', $w[30]['coverage_text']);
        $this->assertSame('7 of 7 days are covered', $w[7]['coverage_text']);
    }
}
