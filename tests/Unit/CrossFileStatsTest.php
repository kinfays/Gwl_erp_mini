<?php

namespace Tests\Unit;

use App\Services\Commercial\Cross\CrossFileAnalytics;
use App\Services\Commercial\Cross\CrossStats;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8e: the statistics of the cross-file analyses on cases with a KNOWN answer, and the X3 passes on handmade routes (no database).
 */
class CrossFileStatsTest extends TestCase
{
    public function test_pearson_on_a_case_with_a_known_coefficient(): void
    {
        // x = 1..5, y = 2, 4, 5, 4, 5: means 3 and 4; sum of dx dy = (-2)(-2) + (-1)(0) + 0(1) + 1(0) + 2(1) = 6; sum dx^2 = 10; sum dy^2 = 4 + 0 + 1 + 0 + 1 = 6;
        // r = 6 / sqrt(10 x 6) = 6 / 7.746 = 0.7746
        $this->assertSame(0.775, CrossStats::pearson([1, 2, 3, 4, 5], [2, 4, 5, 4, 5]));
        $this->assertSame(0.7746, CrossStats::pearson([1, 2, 3, 4, 5], [2, 4, 5, 4, 5], 4));
        // a perfect line, and its mirror
        $this->assertSame(1.0, CrossStats::pearson([1, 2, 3], [10, 20, 30]));
        $this->assertSame(-1.0, CrossStats::pearson([1, 2, 3], [30, 20, 10]));
        // the case of the cross set: x = 0, 10, 20, 30, 40 and y = 0, 10, 30, 20, 40 give 900 / 1000
        $this->assertSame(0.9, CrossStats::pearson([0, 10, 20, 30, 40], [0, 10, 30, 20, 40]));
    }

    public function test_pearson_is_null_when_it_cannot_be_worked_out(): void
    {
        $this->assertNull(CrossStats::pearson([1, 2], [1, 2]), 'fewer than 3 pairs');
        $this->assertNull(CrossStats::pearson([1, 2, 3], [5, 5, 5]), 'one series does not vary');
        $this->assertNull(CrossStats::pearson([], []));
    }

    public function test_percentage_median_and_percentile(): void
    {
        $this->assertSame(25.0, CrossStats::pct(1, 4));
        $this->assertNull(CrossStats::pct(1, 0));
        $this->assertSame(3.0, CrossStats::median([5, 1, 3]));
        $this->assertSame(2.5, CrossStats::median([1, 2, 3, 4]));
        $this->assertNull(CrossStats::median([]));
        // 8 values 0, 5, ..., 35: the 25th percentile is at position 0.25 x 7 = 1.75, i.e. 5 + 0.75 x 5 = 8.75; the 75th at 5.25: 25 + 0.25 x 5 = 26.25
        $values = [0, 5, 10, 15, 20, 25, 30, 35];
        $this->assertSame(8.75, CrossStats::percentile($values, 0.25));
        $this->assertSame(26.25, CrossStats::percentile($values, 0.75));
        $this->assertSame(0.0, (float) CrossStats::percentile($values, 0));
        $this->assertSame(35.0, (float) CrossStats::percentile($values, 1));
    }

    /**
     * @param  list<array{0: string, 1: int, 2: float, 3: int, 4: int}>  $routes  [district, id, skip rate, billed customers, estimated on a working meter]
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function sides(array $routes): array
    {
        $reading = ['routes' => []];
        $billing = ['routes' => []];

        foreach ($routes as [$district, $id, $skip, $billed, $wm]) {
            $reading['routes'][$id] = ['skip_rate' => $skip];
            $billing['routes'][$id] = [
                'label' => $district.' '.$id, 'district_id' => $district === 'A' ? 1 : 2, 'district' => $district, 'customers' => $billed, 'billed_customers' => $billed, 'estimated_customers' => $wm,
                'est' => ['W' => ['customers' => $wm], 'F' => ['customers' => 0], 'N' => ['customers' => 0], 'O' => ['customers' => 0]],
            ];
        }

        return [$reading, $billing];
    }

    public function test_x3_a_district_needs_ten_routes_for_a_coefficient_of_its_own(): void
    {
        // district A: ten routes on an exact line (working-meter share = 2 x skip rate: 10 billed, so wm = skip / 5 customers... here skip 5, 10, ..., 50 and wm 1, 2, ..., 10 of 10 = 10%, 20%, ...)
        $routes = [];

        foreach (range(1, 10) as $n) {
            $routes[] = ['A', $n, (float) ($n * 5), 10, $n];
        }

        // district B: three routes (an exact line too, but too few to be called on their own)
        foreach ([[11, 10.0, 1], [12, 20.0, 2], [13, 30.0, 3]] as [$id, $skip, $wm]) {
            $routes[] = ['B', $id, $skip, 10, $wm];
        }

        [$reading, $billing] = $this->sides($routes);
        $d = (new CrossFileAnalytics)->x3($reading, $billing, 5);

        $this->assertSame(13, $d['overall']['routes']);
        $by = collect($d['by_district'])->keyBy('district');
        $this->assertTrue($by['A']['enough']);
        $this->assertSame(1.0, $by['A']['r_wm'], 'ten routes on an exact line');
        $this->assertSame(1.0, $by['A']['r_est']);
        $this->assertSame(10, $by['A']['routes']);
        $this->assertFalse($by['B']['enough']);
        $this->assertNull($by['B']['r_wm'], 'three routes: no coefficient of its own');
        $this->assertSame(3, $by['B']['routes']);
    }

    public function test_x3_routes_high_on_one_figure_and_low_on_the_other(): void
    {
        // eight routes: skip rate 0, 5, ..., 35 and the working-meter share the other way round (35, 30, ..., 0): a perfect negative line
        $routes = [];

        foreach (range(0, 7) as $i) {
            $routes[] = ['A', $i + 1, (float) ($i * 5), 100, 35 - $i * 5];
        }

        [$reading, $billing] = $this->sides($routes);
        $d = (new CrossFileAnalytics)->x3($reading, $billing, 5);

        $this->assertSame(-1.0, $d['overall']['r_wm']);
        // the quarters are 8.75 and 26.25 on both figures: skip rate at least 26.25 is 30 and 35; a working-meter share at most 8.75 is 5 and 0
        $this->assertSame([8.75, 26.25, 8.75, 26.25], [$d['divergent']['thresholds']['skip_q1'], $d['divergent']['thresholds']['skip_q3'], $d['divergent']['thresholds']['wm_q1'], $d['divergent']['thresholds']['wm_q3']]);
        $this->assertSame([[30.0, 5.0], [35.0, 0.0]], array_map(fn ($p) => [$p['skip_rate'], $p['wm_share']], $d['divergent']['skip_high_estimate_low']));
        $this->assertSame([[0.0, 35.0], [5.0, 30.0]], array_map(fn ($p) => [$p['skip_rate'], $p['wm_share']], $d['divergent']['skip_low_estimate_high']));
    }

    public function test_x3_with_fewer_than_four_routes_calls_none_high_or_low(): void
    {
        [$reading, $billing] = $this->sides([['A', 1, 10.0, 50, 5], ['A', 2, 20.0, 50, 10], ['A', 3, 30.0, 50, 15]]);
        $d = (new CrossFileAnalytics)->x3($reading, $billing, 5);

        $this->assertNull($d['divergent']['thresholds']);
        $this->assertSame([], $d['divergent']['skip_high_estimate_low']);
        $this->assertSame(1.0, $d['overall']['r_wm'], 'three routes are enough for a coefficient');
    }

    public function test_x3_leaves_out_routes_with_too_few_customers_or_no_visits_and_never_invents_a_point(): void
    {
        [$reading, $billing] = $this->sides([['A', 1, 10.0, 50, 5], ['A', 2, 20.0, 4, 1], ['A', 3, 30.0, 50, 15]]);
        $reading['routes'][4] = ['skip_rate' => null];
        $billing['routes'][4] = $billing['routes'][1];

        $d = (new CrossFileAnalytics)->x3($reading, $billing, 5);

        $this->assertSame(2, $d['overall']['routes']);
        $this->assertSame(1, $d['left_out_small'], 'route 2 has 4 customers');
        $this->assertSame(1, $d['left_out_no_visits'], 'route 4 has no visits, so no skip rate');
        $this->assertNull($d['overall']['r_wm'], 'two routes are too few');
    }
}
