<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\PeriodComparison;
use App\Services\Commercial\Trends\TrendFigures;
use Tests\TestCase;

/**
 * Phase 8f: which upload another is compared with (PeriodComparison) and the arithmetic of a change, a rank and a projection (TrendFigures). Pure: no database.
 * The tolerance is 3 days (commercial_trend_length_tolerance_days).
 */
class PeriodComparisonTest extends TestCase
{
    /** @return array{id: int, from: string, to: string, order: int} */
    protected function unit(int $id, string $from, string $to): array
    {
        return ['id' => $id, 'from' => $from, 'to' => $to, 'order' => $id];
    }

    protected function comparison(int $tolerance = 3): PeriodComparison
    {
        return new PeriodComparison($tolerance);
    }

    public function test_a_period_is_compared_with_the_latest_earlier_upload_of_the_same_length(): void
    {
        $units = [$this->unit(1, '2026-10-01', '2026-10-07'), $this->unit(2, '2026-10-08', '2026-10-14'), $this->unit(3, '2026-10-22', '2026-10-28')];
        $r = $this->comparison()->resolve($units);

        $this->assertSame('2026-10-22', $r['current']['from']);
        $this->assertSame(7, $r['current']['length']);
        $this->assertSame(2, $r['previous']['id'], 'the gap of 15 to 21 October does not matter: the latest earlier week');
        $this->assertNull($r['previous_note']);
    }

    public function test_a_different_length_is_refused_a_week_is_never_set_against_a_month(): void
    {
        $units = [$this->unit(1, '2026-09-01', '2026-09-30'), $this->unit(2, '2026-10-01', '2026-10-07')];
        $r = $this->comparison()->resolve($units);

        $this->assertSame(2, $r['current']['batch_ids'][0]);
        $this->assertNull($r['previous']);
        $this->assertStringContainsString('No comparable previous period', $r['previous_note']);
        $this->assertStringContainsString('7 days', $r['previous_note']);

        // and the other way round: a month is not compared with the weeks before it
        $r = $this->comparison()->resolve([$this->unit(1, '2026-10-01', '2026-10-07'), $this->unit(2, '2026-10-08', '2026-10-14'), $this->unit(3, '2026-10-15', '2026-11-14')]);
        $this->assertSame(31, $r['current']['length']);
        $this->assertNull($r['previous']);
    }

    public function test_the_tolerance_is_in_days_and_both_ends_count(): void
    {
        // a 7-day upload against a 10-day one is within 3 days, against an 11-day one it is not
        $within = $this->comparison()->resolve([$this->unit(1, '2026-09-01', '2026-09-10'), $this->unit(2, '2026-10-01', '2026-10-07')]);
        $this->assertSame(1, $within['previous']['id']);

        $outside = $this->comparison()->resolve([$this->unit(1, '2026-09-01', '2026-09-11'), $this->unit(2, '2026-10-01', '2026-10-07')]);
        $this->assertNull($outside['previous']);

        // the tolerance is the setting's
        config(['gwl.commercial_trend_length_tolerance_days' => 0]);
        $this->assertNull((new PeriodComparison)->resolve([$this->unit(1, '2026-09-01', '2026-09-08'), $this->unit(2, '2026-10-01', '2026-10-07')])['previous']);
    }

    public function test_an_upload_that_overlaps_or_follows_is_not_previous(): void
    {
        $units = [$this->unit(1, '2026-10-01', '2026-10-07'), $this->unit(2, '2026-10-05', '2026-10-11')];   // the second starts before the first ends
        $this->assertNull($this->comparison()->previous($units, '2026-10-05', '2026-10-11'), 'the first ends on the 7th, after the 5th');
        $this->assertSame(1, $this->comparison()->previous($units, '2026-10-08', '2026-10-14')['id']);
    }

    public function test_a_shadowed_upload_is_never_compared_with(): void
    {
        // upload 2 repeats the dates of upload 1 and is newer: only 2 counts
        $units = [$this->unit(1, '2026-10-01', '2026-10-07'), $this->unit(2, '2026-10-01', '2026-10-07'), $this->unit(3, '2026-10-08', '2026-10-14')];
        $this->assertSame(2, $this->comparison()->resolve($units)['previous']['id']);
    }

    public function test_no_upload_inside_the_range_gives_nothing_to_compare(): void
    {
        $r = $this->comparison()->resolve([$this->unit(1, '2026-10-01', '2026-10-07')], '2026-11-01', '2026-11-30');

        $this->assertNull($r['current']);
        $this->assertNull($r['previous']);
        $this->assertNotNull($r['previous_note']);
        $this->assertNull($this->comparison()->resolve([])['current']);
    }

    public function test_a_typed_range_is_the_uploads_inside_it(): void
    {
        $units = [$this->unit(1, '2026-10-01', '2026-10-07'), $this->unit(2, '2026-10-08', '2026-10-14'), $this->unit(3, '2026-10-22', '2026-10-28')];
        $c = $this->comparison()->current($units, '2026-10-01', '2026-10-14');

        $this->assertSame(['2026-10-01', '2026-10-14', 14, true], [$c['from'], $c['to'], $c['length'], $c['typed']]);
        $this->assertEqualsCanonicalizing([1, 2], $c['batch_ids']);
        $this->assertSame(14, $c['covered_days']);
    }

    public function test_the_same_period_last_year_is_the_upload_on_the_same_dates_or_none(): void
    {
        $units = [$this->unit(1, '2025-10-01', '2025-10-07'), $this->unit(2, '2025-10-20', '2025-10-26'), $this->unit(3, '2026-10-01', '2026-10-07'), $this->unit(4, '2026-10-22', '2026-10-28')];

        $this->assertSame(1, $this->comparison()->resolve($units, '2026-10-01', '2026-10-07')['last_year']['id']);

        // 22 to 28 October 2026 against 20 to 26 October 2025: the start is 2 days off, inside the tolerance
        $this->assertSame(2, $this->comparison()->resolve($units)['last_year']['id']);

        // nothing a year before
        $none = $this->comparison()->resolve([$this->unit(3, '2026-10-01', '2026-10-07')]);
        $this->assertNull($none['last_year']);
        $this->assertStringContainsString('the same dates a year earlier', $none['last_year_note']);

        // a year-earlier upload of another length is not the same period
        $this->assertNull($this->comparison()->resolve([$this->unit(1, '2025-10-01', '2025-10-31'), $this->unit(3, '2026-10-01', '2026-10-07')])['last_year']);
    }

    // ---------------------------------------------------------------- the arithmetic

    public function test_a_ratio_changes_in_points_a_count_in_absolute_and_percent_and_the_tone_follows_the_target_direction(): void
    {
        $skip = TrendFigures::measure('skip_rate', TrendFigures::MEASURES['reading']['skip_rate'], 16.67, 23.33);
        $this->assertEquals(-6.66, $skip['change']);
        $this->assertNull($skip['change_pct'], 'a ratio has no percentage of a percentage');
        $this->assertSame('good', $skip['tone']);

        $coverage = TrendFigures::measure('coverage', TrendFigures::MEASURES['reading']['coverage'], 70.0, 80.0);
        $this->assertSame('bad', $coverage['tone']);

        $owing = TrendFigures::measure('owing_amount', TrendFigures::MEASURES['billing']['owing_amount'], 629.2, 858.0);
        $this->assertEquals(-228.8, $owing['change']);
        $this->assertEquals(-26.67, $owing['change_pct']);
        $this->assertSame('good', $owing['tone']);

        $total = TrendFigures::measure('month_total', TrendFigures::MEASURES['billing']['month_total'], 120.0, 100.0);
        $this->assertSame('neutral', $total['tone'], 'no direction is set for a month total');
        $this->assertEquals(20.0, $total['change_pct']);

        $zero = TrendFigures::measure('billing_accounts', TrendFigures::MEASURES['customers']['billing_accounts'], 5, 0);
        $this->assertNull($zero['change_pct'], 'no percentage on a zero denominator');
        $this->assertNull(TrendFigures::measure('skip_rate', TrendFigures::MEASURES['reading']['skip_rate'], 10.0, null)['change']);
    }

    public function test_ranks_move_up_down_stay_the_same_or_are_new(): void
    {
        $then = TrendFigures::ranks(['a' => ['value' => 10.0, 'label' => 'A'], 'b' => ['value' => 20.0, 'label' => 'B'], 'c' => ['value' => 30.0, 'label' => 'C'], 'z' => ['value' => null, 'label' => 'Z']], true);
        $now = TrendFigures::ranks(['a' => ['value' => 25.0, 'label' => 'A'], 'b' => ['value' => 20.0, 'label' => 'B'], 'c' => ['value' => 5.0, 'label' => 'C'], 'd' => ['value' => 1.0, 'label' => 'D']], true);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $then, 'an item with no value is not ranked');
        $this->assertSame(['d' => 1, 'c' => 2, 'b' => 3, 'a' => 4], $now);
        $this->assertSame(['move' => 1, 'direction' => 'up'], TrendFigures::move($now['c'], $then['c']));
        $this->assertSame(['move' => -3, 'direction' => 'down'], TrendFigures::move($now['a'], $then['a']));
        $this->assertSame(['move' => -1, 'direction' => 'down'], TrendFigures::move($now['b'], $then['b']));
        $this->assertSame(['move' => null, 'direction' => 'new'], TrendFigures::move($now['d'], null));
        $this->assertSame(['move' => null, 'direction' => 'gone'], TrendFigures::move(null, 2));
        $this->assertSame(['move' => 0, 'direction' => 'same'], TrendFigures::move(2, 2));

        // a tie is broken by the label, so a rank never depends on the order the rows came in
        $tie = TrendFigures::ranks(['y' => ['value' => 5.0, 'label' => 'Y'], 'x' => ['value' => 5.0, 'label' => 'X']], true);
        $this->assertSame(['x' => 1, 'y' => 2], $tie);
        $this->assertSame(['b' => 1, 'a' => 2], TrendFigures::ranks(['a' => ['value' => 1.0, 'label' => 'A'], 'b' => ['value' => 9.0, 'label' => 'B']], false), 'descending: the highest is rank 1');
    }

    public function test_the_projection_is_a_run_rate_withheld_under_the_minimum_and_never_when_complete(): void
    {
        $this->assertSame(['state' => 'projected', 'projection' => 265.71, 'per_day' => 8.5714], TrendFigures::projection(180, 21, 31, 7));
        $this->assertSame('withheld', TrendFigures::projection(180, 6, 31, 7)['state']);
        $this->assertSame('projected', TrendFigures::projection(180, 7, 31, 7)['state'], 'exactly the minimum is enough');
        $this->assertNull(TrendFigures::projection(180, 6, 31, 7)['projection']);
        $this->assertSame('complete', TrendFigures::projection(900, 30, 30, 7)['state']);
        $this->assertNull(TrendFigures::projection(900, 30, 30, 7)['projection']);
        $this->assertSame('none', TrendFigures::projection(0, 0, 31, 7)['state']);
    }
}
