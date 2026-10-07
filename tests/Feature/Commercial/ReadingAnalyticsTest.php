<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Services\Commercial\ReadingAnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers, hand-computed. "Today" is 15 Oct 2026, so June-September 2026 are complete months and October is in progress.
 */
class ReadingAnalyticsTest extends CommercialTestCase
{
    private const COMPLETE = ['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'];

    private ReadingAnalyticsService $analytics;

    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = new ReadingAnalyticsService;
        $this->asOf = Carbon::parse('2026-10-15');
    }

    protected function stats(): Collection
    {
        return CommercialReadingStat::query()->effective()->readers()->with(['employee', 'district'])->get();
    }

    protected function strengths(): Collection
    {
        return CommercialReadingStrength::query()->effective()->get();
    }

    // ---------------------------------------------------------------- R1-R5

    public function test_the_monthly_trend_and_coverage_match_a_hand_computed_fixture(): void
    {
        $this->seedReading([
            '1001' => ['2026-06-01' => [400, 100], '2026-07-01' => [450, 50], '2026-10-01' => [100, 25]],
            '1002' => ['2026-06-01' => [200, 200], '2026-07-01' => [300, 100], '2026-10-01' => [0, 0]],
        ], options: ['strength' => ['2026-06-01' => 10000, '2026-07-01' => 10500, '2026-10-01' => 10800]]);

        $trend = collect($this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf))->keyBy('month');

        $june = $trend['2026-06-01'];
        $this->assertSame(900, $june['visited']);
        $this->assertSame(600, $june['read']);
        $this->assertSame(300, $june['skipped']);
        $this->assertSame(33.33, $june['skip_rate']);
        $this->assertSame(66.67, $june['read_rate']);
        $this->assertSame(10000, $june['strength']);
        $this->assertSame(9.0, $june['coverage']);
        $this->assertSame(2, $june['readers']);
        $this->assertFalse($june['in_progress']);

        $this->assertTrue($trend['2026-10-01']['in_progress']);
    }

    public function test_july_and_the_in_progress_month(): void
    {
        $this->seedReading([
            '1001' => ['2026-07-01' => [450, 50], '2026-10-01' => [100, 25]],
            '1002' => ['2026-07-01' => [300, 100], '2026-10-01' => [0, 0]],
        ], options: ['strength' => ['2026-07-01' => 10500, '2026-10-01' => 10800]]);

        $trend = collect($this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf))->keyBy('month');

        $this->assertSame(900, $trend['2026-07-01']['visited']);
        $this->assertSame(16.67, $trend['2026-07-01']['skip_rate']);
        $this->assertSame(8.57, $trend['2026-07-01']['coverage']);

        $october = $trend['2026-10-01'];
        $this->assertTrue($october['in_progress'], 'the current calendar month is still filling up');
        $this->assertSame(125, $october['visited']);
        $this->assertSame(1, $october['readers'], 'a reader with no visits is not active');
    }

    public function test_division_by_zero_returns_null_never_an_error(): void
    {
        $this->seedReading(['1001' => ['2026-06-01' => [0, 0], '2026-07-01' => [10, 0]]], options: ['strength' => ['2026-06-01' => 0]]);

        $trend = collect($this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf))->keyBy('month');

        $this->assertNull($trend['2026-06-01']['skip_rate']);
        $this->assertNull($trend['2026-06-01']['read_rate']);
        $this->assertNull($trend['2026-06-01']['coverage'], 'a zero strength is not a divisor');
        $this->assertNull($trend['2026-07-01']['strength'], 'no strength row for July');
        $this->assertNull($trend['2026-07-01']['coverage']);
        $this->assertSame(0.0, $trend['2026-07-01']['skip_rate']);

        $table = $this->analytics->readerTable($this->stats());
        $this->assertSame(100.0, $table[0]['share']);

        $this->assertNull(ReadingAnalyticsService::rate(5, 0));
        $this->assertSame([], $this->analytics->monthlyTrend(collect(), collect(), $this->asOf));
        $this->assertSame([], $this->analytics->readerTable(collect()));
        $this->assertSame([], $this->analytics->scorecard(collect(), $this->asOf)['rows']);
    }

    public function test_coverage_is_left_out_when_the_stats_are_only_part_of_the_region(): void
    {
        $this->seedReading(['1001' => ['2026-06-01' => [90, 10]]], options: ['strength' => 1000]);

        $with = $this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf);
        $without = $this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf, withCoverage: false);

        $this->assertSame(10.0, $with[0]['coverage']);
        $this->assertNull($without[0]['coverage']);
    }

    public function test_strength_growth_is_month_on_month(): void
    {
        $this->seedReading(['1001' => $this->everyMonth(['2026-06-01', '2026-07-01', '2026-08-01'], 1, 1)], options: ['strength' => ['2026-06-01' => 10000, '2026-07-01' => 10100, '2026-08-01' => 10050]]);

        $growth = $this->analytics->strengthGrowth($this->strengths());

        $this->assertSame([null, 100, -50], array_column($growth, 'change'));
        $this->assertSame([null, 1.0, -0.5], array_column($growth, 'change_pct'));
    }

    // ---------------------------------------------------------------- R6, R7

    public function test_the_league_table_ranks_by_visits_with_shares_and_flags_unmatched_readers(): void
    {
        $this->seedReading([
            '1001' => ['2026-06-01' => [300, 100]],
            '1002' => ['2026-06-01' => [100, 100]],
            '1003' => ['2026-06-01' => [0, 0]],
        ], options: ['status' => ['1002' => 'unmatched']]);

        $table = $this->analytics->readerTable($this->stats());

        $this->assertSame(['1001', '1002', '1003'], array_column($table, 'staff_id'));
        $this->assertSame([400, 200, 0], array_column($table, 'visited'));
        $this->assertSame([66.67, 33.33, 0.0], array_column($table, 'share'));
        $this->assertSame([25.0, 50.0, null], array_column($table, 'skip_rate'));
        $this->assertSame('unmatched', $table[1]['match_status'], 'an unmatched reader is ranked, and flagged');
        $this->assertSame([1, 1, 0], array_column($table, 'months_active'));
    }

    public function test_the_system_account_never_appears(): void
    {
        $this->seedReading([
            '1001' => ['2026-06-01' => [100, 0]],
            '00000' => ['2026-06-01' => [9999, 1]],
        ], options: ['status' => ['00000' => 'system_account'], 'names' => ['00000' => 'System Administrator']]);

        $table = $this->analytics->readerTable($this->stats());

        $this->assertSame(['1001'], array_column($table, 'staff_id'));
        $this->assertSame(100, $this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf)[0]['visited']);
    }

    public function test_quality_ranks_only_readers_with_enough_visits_and_names_the_best_and_worst_tenth(): void
    {
        $readers = [];

        foreach (range(1, 10) as $i) {
            // Reader i: 1000 visits, i x 5 % skipped.
            $skipped = $i * 50;
            $readers[(string) (2000 + $i)] = ['2026-06-01' => [1000 - $skipped, $skipped]];
        }

        $readers['3000'] = ['2026-06-01' => [20, 80]]; // 80% skipped but only 100 visits: not ranked
        $this->seedReading($readers);

        $quality = $this->analytics->quality($this->analytics->readerTable($this->stats()), 200);

        $this->assertSame(1, $quality['decile_size']);
        $this->assertSame(['2001'], array_column($quality['best'], 'staff_id'));
        $this->assertSame(['2010'], array_column($quality['worst'], 'staff_id'));
        $this->assertCount(11, $quality['points'], 'the scatter still shows every reader');
    }

    // ---------------------------------------------------------------- R8 consistency

    public function test_consistency_is_the_coefficient_of_variation_and_ignores_the_in_progress_month(): void
    {
        $this->seedReading([
            // 400, 500, 600 and a tiny October that must not count: mean 500, population stdev sqrt(20000/3).
            '1001' => ['2026-06-01' => [400, 0], '2026-07-01' => [500, 0], '2026-08-01' => [600, 0], '2026-10-01' => [1, 0]],
            '1002' => $this->everyMonth(['2026-06-01', '2026-07-01', '2026-08-01', '2026-10-01'], 300, 0),
        ]);

        $result = $this->analytics->consistency($this->stats(), $this->asOf);

        $this->assertTrue($result['enough_months']);
        $this->assertSame(3, $result['complete_months']);

        $rows = collect($result['rows'])->keyBy('staff_id');
        $this->assertSame(3, $rows['1001']['months']);
        $this->assertSame(500.0, $rows['1001']['mean']);
        $this->assertSame(16.33, $rows['1001']['cv']);
        $this->assertSame(1, $rows['1001']['months_below_average']);
        $this->assertSame(0.0, $rows['1002']['cv']);
        $this->assertSame('1002', $result['rows'][0]['staff_id'], 'steadiest first');
    }

    public function test_a_new_starters_months_before_their_first_visit_do_not_count_towards_consistency(): void
    {
        $this->seedReading([
            // Started in August: only two months worked, so no coefficient of variation yet.
            'NEW' => ['2026-06-01' => [0, 0], '2026-07-01' => [0, 0], '2026-08-01' => [300, 50], '2026-09-01' => [1000, 100]],
            // Worked every month but was idle in September: that IS inconsistency and stays in.
            'IDLE' => ['2026-06-01' => [400, 0], '2026-07-01' => [400, 0], '2026-08-01' => [400, 0], '2026-09-01' => [0, 0]],
        ]);

        $rows = collect($this->analytics->consistency($this->stats(), $this->asOf)['rows'])->keyBy('staff_id');

        $this->assertArrayNotHasKey('NEW', $rows->all());
        $this->assertSame(4, $rows['IDLE']['months']);
        $this->assertSame(1, $rows['IDLE']['months_below_average']);
    }

    public function test_consistency_says_not_enough_months_under_three_complete_months(): void
    {
        // June and July are complete; August is the month in progress on 15 Aug.
        $this->seedReading(['1001' => $this->everyMonth(['2026-06-01', '2026-07-01', '2026-08-01'], 100, 0)]);

        $result = $this->analytics->consistency($this->stats(), Carbon::parse('2026-08-15'));

        $this->assertFalse($result['enough_months']);
        $this->assertSame(2, $result['complete_months']);
        $this->assertSame([], $result['rows']);
    }

    // ---------------------------------------------------------------- R9 movement

    public function test_movement_compares_the_last_two_complete_months_not_the_one_in_progress(): void
    {
        $this->seedReading([
            '1001' => ['2026-08-01' => [400, 100], '2026-09-01' => [450, 50], '2026-10-01' => [1, 9]],
            '1002' => ['2026-08-01' => [300, 100], '2026-09-01' => [200, 200], '2026-10-01' => [0, 0]],
            '1003' => ['2026-08-01' => [100, 0], '2026-09-01' => [100, 0], '2026-10-01' => [0, 0]],
        ]);

        $movement = $this->analytics->movement($this->stats(), $this->asOf);
        $rows = collect($movement['rows'])->keyBy('staff_id');

        $this->assertTrue($movement['enough_months']);
        $this->assertSame('2026-08-01', $movement['from_month']);
        $this->assertSame('2026-09-01', $movement['to_month']);
        $this->assertSame(0, $rows['1001']['visited_change']);
        $this->assertSame(-10.0, $rows['1001']['skip_rate_change']);
        $this->assertSame('improving', $rows['1001']['trend']);
        $this->assertSame(-0, $rows['1002']['visited_change']);
        $this->assertSame(25.0, $rows['1002']['skip_rate_change']);
        $this->assertSame('declining', $rows['1002']['trend']);
        $this->assertSame('steady', $rows['1003']['trend']);
    }

    public function test_movement_needs_two_complete_months(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [10, 0], '2026-10-01' => [10, 0]]]);

        $this->assertFalse($this->analytics->movement($this->stats(), $this->asOf)['enough_months']);
    }

    // ---------------------------------------------------------------- R10-R12

    public function test_inactive_readers_are_those_with_no_visits_or_far_below_the_median_of_active_readers(): void
    {
        $this->seedReading([
            '1001' => ['2026-06-01' => [100, 0], '2026-10-01' => [0, 0]],
            '1002' => ['2026-06-01' => [100, 0], '2026-10-01' => [0, 0]],
            '1003' => ['2026-06-01' => [100, 0], '2026-10-01' => [0, 0]],
            '1004' => ['2026-06-01' => [0, 0], '2026-10-01' => [0, 0]],
            '1005' => ['2026-06-01' => [20, 0], '2026-10-01' => [0, 0]],
        ]);

        $result = $this->analytics->inactive($this->stats(), $this->asOf);

        $this->assertTrue($result['enough_months']);
        $this->assertCount(1, $result['months'], 'October is in progress: nobody is "inactive" in it yet');

        $june = $result['months'][0];
        $this->assertSame(100.0, $june['median'], 'median of the ACTIVE readers 20, 100, 100, 100');
        $this->assertSame(['1004'], array_column($june['zero'], 'staff_id'));
        $this->assertSame(['1005'], array_column($june['low'], 'staff_id'));
        $this->assertSame(20.0, $june['low'][0]['pct_of_median']);
    }

    public function test_workload_bands_use_the_median_of_active_readers(): void
    {
        $this->seedReading([
            '1' => ['2026-06-01' => [100, 0]], '2' => ['2026-06-01' => [100, 0]], '3' => ['2026-06-01' => [100, 0]], '4' => ['2026-06-01' => [100, 0]],
            '5' => ['2026-06-01' => [40, 0]], '6' => ['2026-06-01' => [200, 0]], '7' => ['2026-06-01' => [0, 0]],
        ]);

        $result = $this->analytics->workload($this->stats(), $this->asOf);

        $this->assertSame(100.0, $result['median'], 'the inactive reader does not drag the median down');
        $this->assertSame(200, $result['max']);
        $this->assertSame(2.0, $result['max_over_median']);
        $this->assertEquals(['inactive' => 1, 'low' => 1, 'normal' => 4, 'high' => 1], $result['counts']);

        $bands = collect($result['rows'])->pluck('band', 'staff_id');
        $this->assertSame('high', $bands['6']);
        $this->assertSame('low', $bands['5']);
        $this->assertSame('inactive', $bands['7']);
    }

    public function test_a_reader_below_the_minimum_visits_is_not_flagged_as_an_outlier_even_with_a_high_skip_rate(): void
    {
        $readers = [];

        foreach (range(1, 6) as $i) {
            $readers[(string) (100 + $i)] = ['2026-06-01' => [900, 100]]; // 10%
        }

        $readers['107'] = ['2026-06-01' => [600, 400]];  // 40%, 1000 visits: a real outlier
        $readers['108'] = ['2026-06-01' => [30, 120]];   // 80% but 150 visits: noise
        $this->seedReading($readers);

        $result = $this->analytics->outliers($this->stats(), $this->asOf, minVisits: 200, zThreshold: 2.0);

        $this->assertSame(7, $result['eligible']);
        $this->assertSame(1, $result['excluded']);
        $this->assertSame(['107'], array_column($result['flagged'], 'staff_id'));
        $this->assertSame(14.29, $result['mean']);
        $this->assertSame(2.45, collect($result['rows'])->firstWhere('staff_id', '107')['z']);
        $this->assertNull(collect($result['rows'])->firstWhere('staff_id', '108'), 'a reader with 150 visits is not judged at all');
    }

    public function test_the_minimum_visits_rule_is_per_active_month_so_a_long_range_does_not_let_a_small_reader_in(): void
    {
        $readers = [];

        foreach (range(1, 6) as $i) {
            $readers[(string) (100 + $i)] = $this->everyMonth(self::COMPLETE, 900, 100); // 10%
        }

        $readers['107'] = $this->everyMonth(self::COMPLETE, 600, 400);  // 1,000 visits a month at 40%: judged and flagged
        $readers['108'] = $this->everyMonth(self::COMPLETE, 100, 60);   // 160 a month at 37.5%: 640 in total, still too small
        $this->seedReading($readers);

        $result = $this->analytics->outliers($this->stats(), $this->asOf, minVisits: 200, zThreshold: 2.0);

        $this->assertSame(['107'], array_column($result['flagged'], 'staff_id'));
        $this->assertSame(1, $result['excluded']);
        $this->assertNull(collect($result['rows'])->firstWhere('staff_id', '108'));

        $quality = $this->analytics->quality($this->analytics->readerTable($this->stats()), 200);
        $this->assertNotContains('108', array_merge(array_column($quality['best'], 'staff_id'), array_column($quality['worst'], 'staff_id')));
    }

    public function test_the_in_progress_month_is_left_out_of_outliers_and_inactive_alike(): void
    {
        $readers = [];

        foreach (range(1, 6) as $i) {
            $readers[(string) (100 + $i)] = ['2026-06-01' => [900, 100], '2026-10-01' => [900, 100]];
        }

        // 107 is perfectly normal in June; only the unfinished October looks terrible.
        $readers['107'] = ['2026-06-01' => [900, 100], '2026-10-01' => [10, 990]];
        $this->seedReading($readers);

        $this->assertSame([], $this->analytics->outliers($this->stats(), $this->asOf, 200, 2.0)['flagged']);
    }

    // ---------------------------------------------------------------- R13

    public function test_the_scorecard_is_a_weighted_percentile_rank_and_shows_unmatched_readers(): void
    {
        $this->seedReading([
            // A: most visits, lowest skip rate, perfectly steady. C: the opposite on all three.
            'A' => ['2026-06-01' => [90, 10], '2026-07-01' => [90, 10], '2026-08-01' => [90, 10]],
            'B' => ['2026-06-01' => [48, 12], '2026-07-01' => [40, 10], '2026-08-01' => [32, 8]],
            'C' => ['2026-06-01' => [25, 25], '2026-07-01' => [15, 15], '2026-08-01' => [5, 5]],
        ], options: ['status' => ['C' => 'unmatched']]);

        $result = $this->analytics->scorecard($this->stats(), $this->asOf);
        $rows = collect($result['rows'])->keyBy('staff_id');

        $this->assertSame(['A', 'B', 'C'], array_column($result['rows'], 'staff_id'));
        $this->assertSame(100.0, $rows['A']['score']);
        $this->assertSame(50.0, $rows['B']['score']);
        $this->assertSame(0.0, $rows['C']['score']);
        $this->assertSame('unmatched', $rows['C']['match_status']);
        $this->assertEqualsWithDelta(1.0, array_sum($result['weights']), 0.0001);
    }

    public function test_the_scorecard_rescales_the_weights_when_consistency_is_unknown(): void
    {
        // Two complete months only: consistency (needs 3) is unknown for everyone.
        $this->seedReading([
            'A' => ['2026-08-01' => [90, 10], '2026-09-01' => [90, 10]],
            'B' => ['2026-08-01' => [40, 40], '2026-09-01' => [40, 40]],
        ]);

        $rows = collect($this->analytics->scorecard($this->stats(), $this->asOf)['rows'])->keyBy('staff_id');

        $this->assertNull($rows['A']['consistency_pct']);
        $this->assertSame(100.0, $rows['A']['score']);
        $this->assertSame(0.0, $rows['B']['score']);
    }

    // ---------------------------------------------------------------- effective data

    public function test_effective_data_feeds_the_numbers_a_newer_batch_changes_a_month_and_a_voided_one_is_ignored(): void
    {
        $this->seedReading(['1001' => ['2026-06-01' => [100, 0], '2026-07-01' => [100, 0]]]);
        $newer = $this->seedReading(['1001' => ['2026-07-01' => [150, 50]]]);

        $trend = collect($this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf))->keyBy('month');
        $this->assertSame(100, $trend['2026-06-01']['visited']);
        $this->assertSame(200, $trend['2026-07-01']['visited'], 'July now comes from the newer batch');

        $newer->update(['status' => CommercialImportBatch::STATUS_VOIDED]);

        $trend = collect($this->analytics->monthlyTrend($this->stats(), $this->strengths(), $this->asOf))->keyBy('month');
        $this->assertSame(100, $trend['2026-07-01']['visited'], 'a voided batch is ignored');
    }
}
