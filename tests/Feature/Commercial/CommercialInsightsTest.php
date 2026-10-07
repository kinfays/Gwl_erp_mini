<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\District;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\CommercialInsightsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** C1, C2, R14 and C3, hand-computed. "Today" is 15 Oct 2026: January to September are complete months, October is in progress. */
class CommercialInsightsTest extends CommercialTestCase
{
    private CommercialInsightsService $insights;

    private BillingAnalyticsService $billing;

    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billing = new BillingAnalyticsService;
        $this->insights = new CommercialInsightsService(new \App\Services\Commercial\ReadingAnalyticsService, $this->billing);
        $this->asOf = Carbon::parse('2026-10-15');
    }

    protected function stats(): Collection
    {
        return CommercialReadingStat::query()->effective()->readers()->with(['district', 'employee'])->get();
    }

    protected function pack(CommercialImportBatch $batch): array
    {
        return ['meta' => $this->billing->describe($batch), 'routes' => CommercialBillingRoute::query()->where('batch_id', $batch->id)->get()];
    }

    // ---------------------------------------------------------------- C1

    public function test_the_scorecard_joins_by_district_id_then_by_label_and_one_sided_districts_show_nothing_on_the_other_side(): void
    {
        $kaneshie = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Kaneshie']);

        $batch = $this->seedBilling([
            // Billing districts: SOWUTUOM carries a district id, ODORKOR only the label as printed, KANESHIE is billing-only.
            ['district' => 'SOWUTUOM', 'code' => 'S1', 'billing_for_period' => 1000, 'total_payments' => 800, 'volume_total' => 100, 'volume_average' => 40, 'billed_total' => 8, 'unbilled_total' => 2],
            ['district' => 'ODORKOR', 'code' => 'O1', 'billing_for_period' => 500, 'total_payments' => 250, 'volume_total' => 50, 'volume_average' => 0, 'billed_total' => 5, 'unbilled_total' => 0],
            ['district' => 'KANESHIE', 'code' => 'K1', 'billing_for_period' => 200, 'total_payments' => 20, 'volume_total' => 10, 'volume_average' => 10, 'billed_total' => 1, 'unbilled_total' => 3],
        ], '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id, 'KANESHIE' => $kaneshie->id]]);

        $this->seedReading([
            'R1' => ['2026-09-01' => [400, 100]],   // home district Sowutuom
            'R2' => ['2026-09-01' => [200, 100]],   // Sowutuom
            'R3' => ['2026-09-01' => [90, 10]],     // Odorkor, which billing knows only by its printed label
            'R4' => ['2026-09-01' => [40, 10]],     // Head Office: reading only
            'R5' => ['2026-09-01' => [8, 2]],       // not in the directory: no home district
            'R6' => ['2026-10-01' => [999, 1]],     // October: in progress, outside the period anyway
        ], options: ['districts' => ['R1' => $this->sowutuom->id, 'R2' => $this->sowutuom->id, 'R3' => $this->odorkor->id, 'R4' => $this->headOffice->id, 'R6' => $this->sowutuom->id]]);

        $result = $this->insights->districtScorecard($this->billing->describe($batch), $this->pack($batch)['routes'], $this->stats(), $this->asOf);
        $rows = collect($result['rows'])->keyBy(fn (array $row) => $row['key'] !== '' ? $row['key'] : $row['district']);

        $this->assertTrue($result['reading_available']);
        $this->assertSame(['Sep 2026'], $result['reading_months']);

        $sowutuom = $rows['SOWUTUOM'];
        $this->assertTrue($sowutuom['billing_side'] && $sowutuom['reading_side']);
        $this->assertSame([1000.0, 80.0, 40.0, 20.0], [$sowutuom['billing'], $sowutuom['cash_ratio'], $sowutuom['estimation'], $sowutuom['unbilled_rate']]);
        $this->assertSame([800, 600, 25.0, 2, 400.0], [$sowutuom['visits'], $sowutuom['read'], $sowutuom['skip_rate'], $sowutuom['active_readers'], $sowutuom['visits_per_reader']], 'joined by district id: 500 + 300 visits, 2 readers');

        $odorkor = $rows['ODORKOR'];
        $this->assertTrue($odorkor['reading_side'], 'billing has no district id for it, so it joins by the label as printed');
        $this->assertSame([100, 10.0, 1], [$odorkor['visits'], $odorkor['skip_rate'], $odorkor['active_readers']]);
        $this->assertSame(50.0, $odorkor['cash_ratio']);

        $kaneshieRow = $rows['KANESHIE'];
        $this->assertTrue($kaneshieRow['billing_side']);
        $this->assertFalse($kaneshieRow['reading_side']);
        $this->assertNull($kaneshieRow['visits']);
        $this->assertNull($kaneshieRow['skip_rate']);
        $this->assertNull($kaneshieRow['visits_per_reader']);

        $headOffice = $rows['Head Office'];
        $this->assertFalse($headOffice['billing_side'], 'a district present on the reading side only');
        $this->assertNull($headOffice['billing']);
        $this->assertNull($headOffice['cash_ratio']);
        $this->assertSame(50, $headOffice['visits']);

        $unknown = $rows['No home district (reader not in the directory)'];
        $this->assertSame(10, $unknown['visits']);
        $this->assertFalse($unknown['billing_side']);

        $this->assertSame(['SOWUTUOM', 'ODORKOR', 'KANESHIE'], array_slice(array_column($result['rows'], 'key'), 0, 3), 'billing districts first, by billing');
        $this->assertSame('Sowutuom', $sowutuom['district'], 'the directory name is shown when the district is matched');
    }

    public function test_the_scorecard_has_no_reading_columns_when_no_complete_month_falls_in_the_period(): void
    {
        $batch = $this->seedBilling($this->billingFixture(), '2026-10-01');   // October is in progress
        $this->seedReading(['R1' => ['2026-10-01' => [10, 0]]], options: ['districts' => ['R1' => $this->sowutuom->id]]);

        $result = $this->insights->districtScorecard($this->billing->describe($batch), $this->pack($batch)['routes'], $this->stats(), $this->asOf);

        $this->assertFalse($result['reading_available']);
        $this->assertSame([], array_values(array_filter(array_column($result['rows'], 'visits'), fn ($v) => $v !== null)));
    }

    public function test_the_not_like_for_like_note_follows_the_segment(): void
    {
        $this->assertTrue(CommercialInsightsService::notLikeForLike('new_service'));
        $this->assertFalse(CommercialInsightsService::notLikeForLike('all'));
        $this->assertFalse(CommercialInsightsService::notLikeForLike(null));

        $this->assertStringContainsString('Billing covers New Service customers only; reading covers all customers: not like for like.', CommercialInsightsService::notLikeForLikeNote(['segment_label' => 'New Service']));
    }

    // ---------------------------------------------------------------- C2

    public function test_the_estimation_against_skip_pairs_only_complete_months_with_a_single_month_snapshot(): void
    {
        $months = ['2026-06-01', '2026-07-01', '2026-08-01'];
        $snapshots = [];

        foreach ($months as $i => $month) {
            // Estimation share by volume 20, 30, 40 %.
            $snapshots[] = $this->pack($this->seedBilling([['district' => 'D', 'code' => 'R', 'volume_total' => 100, 'volume_average' => 20 + $i * 10]], $month));
        }

        $snapshots[] = $this->pack($this->seedBilling([['district' => 'D', 'code' => 'R', 'volume_total' => 100, 'volume_average' => 90]], '2026-05-01'));   // no reading for May
        $snapshots[] = $this->pack($this->seedBilling([['district' => 'D', 'code' => 'R', 'volume_total' => 100, 'volume_average' => 80]], '2026-09-01', ['period_to' => '2026-10-31']));   // a period, not a month
        $snapshots[] = $this->pack($this->seedBilling([['district' => 'D', 'code' => 'R', 'volume_total' => 100, 'volume_average' => 70]], '2026-10-01'));   // reading for October is in progress

        $this->seedReading(['R1' => [
            '2026-06-01' => [900, 100],   // 10%
            '2026-07-01' => [800, 200],   // 20%
            '2026-08-01' => [700, 300],   // 30%
            '2026-10-01' => [100, 100],
        ]]);

        $result = $this->insights->estimationVsSkip($snapshots, $this->stats(), $this->asOf);

        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026'], array_column($result['pairs'], 'label'));
        $this->assertSame([20.0, 30.0, 40.0], array_column($result['pairs'], 'estimation'));
        $this->assertSame([10.0, 20.0, 30.0], array_column($result['pairs'], 'skip_rate'));
        $this->assertSame(3, $result['count']);
        $this->assertNull($result['coefficient'], 'three pairs is not enough');
        $this->assertStringContainsString('at least 6 months', $result['reason']);
        $this->assertStringContainsString('there are 3', $result['reason']);
    }

    public function test_a_coefficient_appears_only_from_six_pairs(): void
    {
        $months = ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'];
        $snapshots = [];
        $reading = [];

        foreach ($months as $i => $month) {
            $snapshots[] = $this->pack($this->seedBilling([['district' => 'D', 'code' => 'R', 'volume_total' => 100, 'volume_average' => 10 + $i * 10]], $month));
            $reading[$month] = [1000 - 50 * ($i + 1), 50 * ($i + 1)];   // skip 5, 10, 15 ... 30 %: a perfect line against estimation
        }

        $this->seedReading(['R1' => $reading]);

        $five = $this->insights->estimationVsSkip(array_slice($snapshots, 0, 5), $this->stats(), $this->asOf);
        $this->assertNull($five['coefficient']);
        $this->assertSame(5, $five['count']);

        $six = $this->insights->estimationVsSkip($snapshots, $this->stats(), $this->asOf);
        $this->assertSame(6, $six['count']);
        $this->assertSame(1.0, $six['coefficient']);
        $this->assertNull($six['reason']);
    }

    public function test_pearson_handles_negative_flat_and_too_short_series(): void
    {
        $this->assertSame(-1.0, CommercialInsightsService::pearson([1, 2, 3, 4], [8, 6, 4, 2]));
        $this->assertSame(1.0, CommercialInsightsService::pearson([1, 2, 3], [10, 20, 30]));
        $this->assertNull(CommercialInsightsService::pearson([1, 2, 3], [5, 5, 5]), 'a flat series has no correlation');
        $this->assertNull(CommercialInsightsService::pearson([1], [2]));
        $this->assertSame(0.0, CommercialInsightsService::pearson([1, 2, 3, 4], [1, 3, 3, 1]));
    }

    // ---------------------------------------------------------------- R14

    public function test_month_to_date_pace_is_hand_computed_and_a_voided_upload_is_ignored(): void
    {
        // October 2026 has 31 days. Upload 1 on 8 Oct 00:00 = 7/31 of the month; upload 2 on 16 Oct 00:00 = 15/31.
        $first = $this->seedBilling([], '2026-09-01');   // a throwaway batch so ids differ from the reading ones
        $one = $this->seedReading(['R1' => ['2026-10-01' => [150, 50]]]);                    // 200 visits
        $two = $this->seedReading(['R1' => ['2026-10-01' => [420, 100]], 'R2' => ['2026-10-01' => [0, 0]]]);   // 520 visits
        $voided = $this->seedReading(['R1' => ['2026-10-01' => [9999, 1]]]);

        $one->update(['imported_at' => '2026-10-08 00:00:00']);
        $two->update(['imported_at' => '2026-10-16 00:00:00']);
        $voided->update(['imported_at' => '2026-10-20 00:00:00', 'status' => CommercialImportBatch::STATUS_VOIDED]);

        $rows = CommercialReadingStat::query()->readers()
            ->whereHas('batch', fn ($batch) => $batch->notVoided())
            ->selectRaw('batch_id, month, sum(read_count) as read_total, sum(skipped_count) as skipped_total, sum(visited_count) as visited_total')
            ->groupBy('batch_id', 'month')->get();

        $pace = $this->insights->monthToDatePace($rows, CommercialImportBatch::query()->get()->keyBy('id'), '2026-10-01', 1000, 'Sep 2026');

        $this->assertTrue($pace['enough']);
        $this->assertSame('Oct 2026', $pace['label']);
        $this->assertCount(2, $pace['snapshots'], 'the voided upload is left out');

        [$a, $b] = $pace['snapshots'];
        $this->assertSame($one->id, $a['batch_id']);
        $this->assertSame([22.6, 200, 150, 25.0], [$a['fraction'], $a['visits'], $a['read'], $a['skip_rate']]);
        $this->assertSame(886, $a['projected_visits'], '200 / (7/31)');
        $this->assertSame(-11.4, $a['vs_previous_pct'], '886 against 1,000 in September');

        $this->assertSame([48.4, 520, 420], [$b['fraction'], $b['visits'], $b['read']]);
        $this->assertSame(1075, $b['projected_visits'], '520 / (15/31)');
        $this->assertSame(7.5, $b['vs_previous_pct']);
        $this->assertNotNull($first);
    }

    public function test_pace_after_the_month_has_ended_is_the_actual_figure_and_without_a_previous_month_there_is_no_comparison(): void
    {
        $late = $this->seedReading(['R1' => ['2026-09-01' => [80, 20]]]);
        $early = $this->seedReading(['R1' => ['2026-09-01' => [40, 10]]]);
        $late->update(['imported_at' => '2026-10-05 09:00:00']);   // uploaded after September ended
        $early->update(['imported_at' => '2026-08-31 12:00:00']);  // uploaded before it began

        $rows = CommercialReadingStat::query()->readers()
            ->selectRaw('batch_id, month, sum(read_count) as read_total, sum(skipped_count) as skipped_total, sum(visited_count) as visited_total')
            ->groupBy('batch_id', 'month')->get();

        $pace = $this->insights->monthToDatePace($rows, CommercialImportBatch::query()->get()->keyBy('id'), '2026-09-01', null, null);

        $this->assertSame($early->id, $pace['snapshots'][0]['batch_id']);
        $this->assertNull($pace['snapshots'][0]['projected_visits'], 'nothing of the month had elapsed, so no projection');
        $this->assertSame(0.0, $pace['snapshots'][0]['fraction']);
        $this->assertSame([100.0, 100, 100], [$pace['snapshots'][1]['fraction'], $pace['snapshots'][1]['visits'], $pace['snapshots'][1]['projected_visits']], 'the month is over: projected = actual');
        $this->assertNull($pace['snapshots'][1]['vs_previous_pct']);
    }

    // ---------------------------------------------------------------- C3

    public function test_the_executive_summary_has_the_headlines_exceptions_freshness_and_quality_and_no_reader_names(): void
    {
        $this->seedReading([
            'R1' => ['2026-06-01' => [400, 100], '2026-07-01' => [450, 50], '2026-09-01' => [400, 100]],
            'R2' => ['2026-06-01' => [100, 0], '2026-07-01' => [100, 0], '2026-09-01' => [100, 0]],
            'R3' => ['2026-09-01' => [10, 0]],
        ], options: ['names' => ['R1' => 'ZEBEDEE SECRETNAME', 'R2' => 'PRISCILLA HIDDEN'], 'status' => ['R2' => 'unmatched', 'R3' => 'unmatched'], 'strength' => 1000]);

        $multi = $this->seedBilling([['district' => 'D', 'code' => 'P']], '2026-06-01', ['period_to' => '2026-08-31']);
        $single = $this->seedBilling($this->billingFixture(), '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id]]);

        $stats = $this->stats();
        $strengths = \App\Models\CommercialReadingStrength::query()->effective()->get();
        $snapshots = $this->billing->snapshots(CommercialImportBatch::query());

        $summary = $this->insights->executiveSummary([
            'trend' => (new \App\Services\Commercial\ReadingAnalyticsService)->monthlyTrend($stats, $strengths, $this->asOf),
            'unmatched_readers' => $stats->where('match_status', 'unmatched')->pluck('reader_staff_id')->unique()->count(),
            'snapshot' => $this->billing->describe($single),
            'routes' => $this->pack($single)['routes'],
            'snapshots' => $snapshots,
            'batches' => CommercialImportBatch::query()->notVoided()->with('region')->get(),
        ]);

        // Reading headline: September, complete.
        $r = $summary['reading'];
        $this->assertSame('Sep 2026', $r['month']);
        $this->assertSame(610, $r['visited']);
        $this->assertSame(16.39, $r['skip_rate']);
        $this->assertSame(61.0, $r['coverage']);
        $this->assertFalse($r['skip_met'], '16.39% is above the configured target of at most 10%');
        $this->assertFalse($r['coverage_met'], '61% is below the configured 90%');
        $this->assertStringNotContainsString('ZEBEDEE', json_encode($summary));
        $this->assertStringNotContainsString('PRISCILLA', json_encode($summary));
        $this->assertSame(2, $summary['quality']['unmatched_readers'], 'R2 and R3 are not in the directory');
    }

    public function test_the_summary_headline_flags_follow_the_configured_targets(): void
    {
        config(['gwl.commercial_target_skip_rate_pct' => 10, 'gwl.commercial_target_coverage_pct' => 90, 'gwl.commercial_target_collection_pct' => 95]);

        $this->seedReading(['R1' => ['2026-09-01' => [900, 100]]], options: ['strength' => 1000]);
        $single = $this->seedBilling($this->billingFixture(), '2026-09-01');

        $summary = $this->insights->executiveSummary([
            'trend' => (new \App\Services\Commercial\ReadingAnalyticsService)->monthlyTrend($this->stats(), \App\Models\CommercialReadingStrength::query()->effective()->get(), $this->asOf),
            'snapshot' => $this->billing->describe($single),
            'routes' => $this->pack($single)['routes'],
        ]);

        $this->assertTrue($summary['reading']['skip_met'], '10.0% is within "at most 10%"');
        $this->assertTrue($summary['reading']['coverage_met'], '100% against at least 90%');
        $this->assertFalse($summary['billing']['collection_met'], '75% cash ratio against 95%');
        $this->assertSame(75.0, $summary['billing']['cash_ratio']);
        $this->assertSame(['zero_activity' => 1, 'heavy_credit' => 1, 'high_unbilled' => 1, 'high_estimation' => 0], $summary['exceptions']['counts']);
        $this->assertTrue($summary['billing']['not_like_for_like'], 'the fixture segment is new_service');
    }

    public function test_the_summary_freshness_and_quality_sections(): void
    {
        $this->seedReading(['R1' => ['2026-06-01' => [1, 0], '2026-07-01' => [1, 0]]]);
        $this->seedReading(['R1' => ['2026-09-01' => [1, 0], '2026-10-01' => [1, 0]]], options: ['status' => ['R1' => 'unmatched']]);
        $this->seedBilling([['district' => 'D', 'code' => 'P']], '2026-06-01', ['period_to' => '2026-08-31']);
        $single = $this->seedBilling([['district' => 'ODORKOR', 'code' => 'A'], ['district' => 'ODORKOR', 'code' => 'B'], ['district' => 'SOWUTUOM', 'code' => 'C']], '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id]]);

        $summary = $this->insights->executiveSummary([
            'unmatched_readers' => 1,
            'snapshot' => $this->billing->describe($single),
            'routes' => $this->pack($single)['routes'],
            'snapshots' => $this->billing->snapshots(CommercialImportBatch::query()),
            'batches' => CommercialImportBatch::query()->notVoided()->with('region')->get(),
        ]);

        $f = $summary['freshness'];
        $this->assertSame(['Jun 2026', 'Jul 2026', 'Sep 2026', 'Oct 2026'], $f['reading_months']);
        $this->assertSame(['Aug 2026'], $f['reading_months_missing'], 'August falls between the uploads and has nothing');
        $this->assertSame('Oct 2026', explode(' to ', $f['reading']['period'])[1] ?? $f['reading']['period']);
        $this->assertNotNull($f['billing']['uploaded_at']);
        $this->assertCount(2, $f['billing_periods']);

        $this->assertSame(1, $summary['quality']['unmatched_readers']);
        $this->assertSame(1, $summary['quality']['unresolved_districts'], 'ODORKOR has no district id; SOWUTUOM has');
        $this->assertSame(1, $summary['quality']['multi_month_snapshots']);
        $this->assertNull($summary['reading'], 'no trend was passed, so no reading section');
    }

    public function test_sections_are_null_when_their_input_is_not_passed(): void
    {
        $summary = $this->insights->executiveSummary(['batches' => collect()]);

        $this->assertNull($summary['reading']);
        $this->assertNull($summary['billing']);
        $this->assertNull($summary['exceptions']);
        $this->assertNull($summary['quality']['unmatched_readers']);
        $this->assertNull($summary['quality']['unresolved_districts']);
        $this->assertSame([], $summary['freshness']['reading_months']);
    }
}
