<?php

namespace App\Services\Commercial;

use App\Models\CommercialReadingStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind the reading screens (design section 4.1, R1-R13), worked out in PHP from the rows it is handed.
 *
 * The caller owns scoping and filters: it passes the already-scoped EFFECTIVE stats (readers only, the system account
 * is never in them) and the effective strengths, exactly as AssetSummaryService does. About 70 readers x a handful of
 * months, so every method is a pass over a collection: no per-row queries and no database-specific SQL.
 *
 * Rules that keep screen and numbers in step:
 *  - Rates are recomputed from counts, as PERCENTAGES (skip rate = skipped / visited x 100) and are null when the
 *    denominator is zero. Nothing ever divides by zero.
 *  - The current calendar month is "in progress" (weekly uploads fill it gradually). It is shown in the trend and the
 *    league table, but movement, inactive readers, consistency, workload, outliers and the scorecard use COMPLETE
 *    months only.
 */
class ReadingAnalyticsService
{
    /** A reader needs this many monthly rows before their consistency (coefficient of variation) is worked out. */
    public const MIN_MONTHS_FOR_CONSISTENCY = 3;

    /** A change in skip rate smaller than this many percentage points is "steady". */
    public const STEADY_BAND_PP = 0.5;

    public static function rate(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : null;
    }

    /**
     * Whether a reader has enough volume to be judged on their skip rate: at least $minVisits visits per month they were
     * active, so a small reader cannot qualify just because the range is long. For a one-month range this is simply
     * "at least $minVisits visits".
     */
    public static function hasEnoughVisits(int $visited, int $activeMonths, int $minVisits): bool
    {
        return $activeMonths > 0 && $visited / $activeMonths >= $minVisits;
    }

    /** True for the current calendar month (and any later one): its figures are still being filled in. */
    public static function isInProgress(string $month, Carbon $asOf): bool
    {
        return substr($month, 0, 7) >= $asOf->format('Y-m');
    }

    /**
     * R1 + R2 + R3 + R4: every month with its volumes, skip rate, read rate and, when asked, coverage.
     *
     * @param  Collection<int, CommercialReadingStat>  $stats
     * @param  Collection<int, \App\Models\CommercialReadingStrength>  $strengths  one row per region per month
     * @param  bool  $withCoverage  false when the stats are filtered to part of the region: the strength is region-wide
     * @return list<array<string, mixed>>
     */
    public function monthlyTrend(Collection $stats, Collection $strengths, Carbon $asOf, bool $withCoverage = true): array
    {
        $strengthByMonth = $strengths->groupBy(fn ($row) => $this->monthKey($row->month))->map(fn (Collection $rows) => (int) $rows->sum('verified_strength'));
        $trend = [];

        foreach ($stats->groupBy(fn ($row) => $this->monthKey($row->month))->sortKeys() as $month => $rows) {
            $visited = (int) $rows->sum('visited_count');
            $read = (int) $rows->sum('read_count');
            $skipped = (int) $rows->sum('skipped_count');
            $strength = $strengthByMonth[$month] ?? null;

            $trend[] = [
                'month' => $month,
                'label' => Carbon::parse($month)->format('M Y'),
                'in_progress' => self::isInProgress($month, $asOf),
                'visited' => $visited,
                'read' => $read,
                'skipped' => $skipped,
                'skip_rate' => self::rate($skipped, $visited),
                'read_rate' => self::rate($read, $visited),
                'readers' => $rows->where('visited_count', '>', 0)->pluck('reader_staff_id')->unique()->count(),
                'strength' => $strength,
                'coverage' => $withCoverage && $strength ? self::rate($visited, $strength) : null,
            ];
        }

        return $trend;
    }

    /**
     * R2: coverage = visited / verified strength, per month.
     *
     * @return list<array{month: string, label: string, in_progress: bool, visited: int, strength: int|null, coverage: float|null}>
     */
    public function coverage(Collection $stats, Collection $strengths, Carbon $asOf): array
    {
        return array_map(fn (array $row) => [
            'month' => $row['month'],
            'label' => $row['label'],
            'in_progress' => $row['in_progress'],
            'visited' => $row['visited'],
            'strength' => $row['strength'],
            'coverage' => $row['coverage'],
        ], $this->monthlyTrend($stats, $strengths, $asOf));
    }

    /**
     * R5: how the verified customer strength moves month on month.
     *
     * @return list<array{month: string, label: string, strength: int, change: int|null, change_pct: float|null}>
     */
    public function strengthGrowth(Collection $strengths): array
    {
        $byMonth = $strengths->groupBy(fn ($row) => $this->monthKey($row->month))->map(fn (Collection $rows) => (int) $rows->sum('verified_strength'))->sortKeys();
        $growth = [];
        $previous = null;

        foreach ($byMonth as $month => $strength) {
            $growth[] = [
                'month' => $month,
                'label' => Carbon::parse($month)->format('M Y'),
                'strength' => $strength,
                'change' => $previous === null ? null : $strength - $previous,
                'change_pct' => $previous ? round(($strength - $previous) / $previous * 100, 2) : null,
            ];
            $previous = $strength;
        }

        return $growth;
    }

    /**
     * R6: the league table, busiest reader first, over every month in the rows (the in-progress month included).
     *
     * @return list<array<string, mixed>>
     */
    public function readerTable(Collection $stats): array
    {
        $total = (int) $stats->sum('visited_count');
        $rows = [];

        foreach ($stats->groupBy('reader_staff_id') as $staffId => $readerRows) {
            $visited = (int) $readerRows->sum('visited_count');
            $read = (int) $readerRows->sum('read_count');
            $skipped = (int) $readerRows->sum('skipped_count');

            $rows[] = [
                ...$this->identity((string) $staffId, $readerRows),
                'visited' => $visited,
                'read' => $read,
                'skipped' => $skipped,
                'skip_rate' => self::rate($skipped, $visited),
                'read_rate' => self::rate($read, $visited),
                'share' => self::rate($visited, $total),
                'months_active' => $readerRows->where('visited_count', '>', 0)->pluck('month')->map(fn ($m) => $this->monthKey($m))->unique()->count(),
                'months' => $readerRows->pluck('month')->map(fn ($m) => $this->monthKey($m))->unique()->count(),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['visited'], $a['staff_id']] <=> [$a['visited'], $b['staff_id']]);

        return $rows;
    }

    /**
     * R7: skip rate against visits. Only readers with at least $minVisits visits are ranked (a reader with 40 visits and
     * a 50% skip rate is noise); the best and worst tenth by skip rate are named.
     *
     * @param  list<array<string, mixed>>  $readerTable
     * @return array{min_visits: int, points: list<array<string, mixed>>, best: list<array<string, mixed>>, worst: list<array<string, mixed>>, decile_size: int}
     */
    public function quality(array $readerTable, ?int $minVisits = null): array
    {
        $minVisits ??= (int) config('gwl.commercial_min_visits_for_outlier', 200);

        $points = array_values(array_filter($readerTable, fn (array $row) => $row['skip_rate'] !== null));
        $ranked = array_values(array_filter($points, fn (array $row) => self::hasEnoughVisits($row['visited'], $row['months_active'], $minVisits)));
        usort($ranked, fn (array $a, array $b) => [$a['skip_rate'], $a['staff_id']] <=> [$b['skip_rate'], $b['staff_id']]);

        $size = count($ranked) < 2 ? count($ranked) : (int) max(1, ceil(count($ranked) / 10));

        return [
            'min_visits' => $minVisits,
            'points' => $points,
            'best' => array_slice($ranked, 0, $size),
            'worst' => array_reverse(array_slice($ranked, count($ranked) - $size)),
            'decile_size' => $size,
        ];
    }

    /**
     * R8: how steady a reader's monthly visits are (coefficient of variation = standard deviation / mean), COMPLETE
     * months only, and how many months fell below the reader's own average.
     *
     * @return array{enough_months: bool, complete_months: int, min_months: int, rows: list<array<string, mixed>>}
     */
    public function consistency(Collection $stats, Carbon $asOf): array
    {
        $complete = $this->completeMonths($stats, $asOf);
        $enough = count($complete) >= self::MIN_MONTHS_FOR_CONSISTENCY;
        $rows = [];

        if ($enough) {
            foreach ($this->completeRows($stats, $asOf)->groupBy('reader_staff_id') as $staffId => $readerRows) {
                $row = $this->consistencyRow((string) $staffId, $readerRows);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            usort($rows, fn (array $a, array $b) => [$a['cv'] ?? INF, $a['staff_id']] <=> [$b['cv'] ?? INF, $b['staff_id']]);
        }

        return ['enough_months' => $enough, 'complete_months' => count($complete), 'min_months' => self::MIN_MONTHS_FOR_CONSISTENCY, 'rows' => $rows];
    }

    /**
     * R9: what changed for each reader between the last two complete months.
     *
     * @return array{enough_months: bool, from_month: string|null, to_month: string|null, rows: list<array<string, mixed>>}
     */
    public function movement(Collection $stats, Carbon $asOf): array
    {
        $complete = $this->completeMonths($stats, $asOf);

        if (count($complete) < 2) {
            return ['enough_months' => false, 'from_month' => null, 'to_month' => null, 'rows' => []];
        }

        [$from, $to] = array_slice($complete, -2);
        $rows = [];

        foreach ($stats->groupBy('reader_staff_id') as $staffId => $readerRows) {
            $before = $readerRows->first(fn ($row) => $this->monthKey($row->month) === $from);
            $after = $readerRows->first(fn ($row) => $this->monthKey($row->month) === $to);

            if (! $before || ! $after) {
                continue;
            }

            $skipBefore = self::rate($before->skipped_count, $before->visited_count);
            $skipAfter = self::rate($after->skipped_count, $after->visited_count);
            $skipChange = $skipBefore !== null && $skipAfter !== null ? round($skipAfter - $skipBefore, 2) : null;

            $rows[] = [
                ...$this->identity((string) $staffId, $readerRows),
                'visited_before' => (int) $before->visited_count,
                'visited_after' => (int) $after->visited_count,
                'visited_change' => (int) $after->visited_count - (int) $before->visited_count,
                'skip_rate_before' => $skipBefore,
                'skip_rate_after' => $skipAfter,
                'skip_rate_change' => $skipChange,
                'trend' => match (true) {
                    $skipChange === null => 'n/a',
                    $skipChange < -self::STEADY_BAND_PP => 'improving',
                    $skipChange > self::STEADY_BAND_PP => 'declining',
                    default => 'steady',
                },
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['visited_change'], $a['staff_id']] <=> [$a['visited_change'], $b['staff_id']]);

        return ['enough_months' => true, 'from_month' => $from, 'to_month' => $to, 'rows' => $rows];
    }

    /**
     * R10: in each complete month, readers with no visits at all and readers well below the median of the active readers.
     *
     * @return array{enough_months: bool, low_pct: int, months: list<array<string, mixed>>}
     */
    public function inactive(Collection $stats, Carbon $asOf): array
    {
        $lowPct = (int) config('gwl.commercial_workload_low_pct', 50);
        $months = [];
        $complete = $this->completeMonths($stats, $asOf);

        foreach ($complete as $month) {
            $rows = $stats->filter(fn ($row) => $this->monthKey($row->month) === $month)->unique('reader_staff_id');
            $median = $this->median($rows->where('visited_count', '>', 0)->pluck('visited_count')->all());
            $threshold = $median === null ? null : $median * $lowPct / 100;

            $zero = [];
            $low = [];

            foreach ($rows as $row) {
                $entry = [...$this->identity((string) $row->reader_staff_id, collect([$row])), 'visited' => (int) $row->visited_count];

                if ($row->visited_count == 0) {
                    $zero[] = $entry;
                } elseif ($threshold !== null && $row->visited_count < $threshold) {
                    $low[] = $entry + ['pct_of_median' => self::rate($row->visited_count, $median)];
                }
            }

            $months[] = [
                'month' => $month,
                'label' => Carbon::parse($month)->format('M Y'),
                'median' => $median,
                'threshold' => $threshold,
                'zero' => $zero,
                'low' => $low,
            ];
        }

        return ['enough_months' => $complete !== [], 'low_pct' => $lowPct, 'months' => $months];
    }

    /**
     * R11: how the work is spread. Visits per reader over the complete months, against the median of the ACTIVE readers.
     *
     * @return array<string, mixed>
     */
    public function workload(Collection $stats, Carbon $asOf): array
    {
        $lowPct = (int) config('gwl.commercial_workload_low_pct', 50);
        $highPct = (int) config('gwl.commercial_workload_high_pct', 150);
        $complete = $this->completeMonths($stats, $asOf);

        if ($complete === []) {
            return ['enough_months' => false, 'low_pct' => $lowPct, 'high_pct' => $highPct, 'median' => null, 'max' => null, 'max_over_median' => null, 'counts' => [], 'rows' => []];
        }

        $rows = [];

        foreach ($this->completeRows($stats, $asOf)->groupBy('reader_staff_id') as $staffId => $readerRows) {
            $rows[] = [...$this->identity((string) $staffId, $readerRows), 'visited' => (int) $readerRows->sum('visited_count')];
        }

        $median = $this->median(array_column(array_filter($rows, fn (array $row) => $row['visited'] > 0), 'visited'));

        foreach ($rows as $index => $row) {
            $pct = $median ? self::rate($row['visited'], $median) : null;

            $rows[$index]['pct_of_median'] = $pct;
            $rows[$index]['band'] = match (true) {
                $row['visited'] === 0 => 'inactive',
                $pct === null => 'normal',
                $pct < $lowPct => 'low',
                $pct > $highPct => 'high',
                default => 'normal',
            };
        }

        usort($rows, fn (array $a, array $b) => [$b['visited'], $a['staff_id']] <=> [$a['visited'], $b['staff_id']]);

        $max = $rows === [] ? null : max(array_column($rows, 'visited'));

        return [
            'enough_months' => true,
            'low_pct' => $lowPct,
            'high_pct' => $highPct,
            'median' => $median,
            'max' => $max,
            'max_over_median' => $median ? round($max / $median, 2) : null,
            'counts' => array_count_values(array_column($rows, 'band')),
            'rows' => $rows,
        ];
    }

    /**
     * R12: readers whose skip rate is unusually high against the other readers (z-score), complete months only, and only
     * readers with at least commercial_min_visits_for_outlier visits, so a reader with 197 visits is not flagged on noise.
     *
     * @return array<string, mixed>
     */
    public function outliers(Collection $stats, Carbon $asOf, ?int $minVisits = null, ?float $zThreshold = null): array
    {
        $minVisits ??= (int) config('gwl.commercial_min_visits_for_outlier', 200);
        $zThreshold ??= (float) config('gwl.commercial_outlier_zscore', 2.0);
        $complete = $this->completeMonths($stats, $asOf);

        $result = ['enough_months' => $complete !== [], 'min_visits' => $minVisits, 'threshold' => $zThreshold, 'eligible' => 0, 'excluded' => 0, 'mean' => null, 'stdev' => null, 'rows' => [], 'flagged' => []];

        if ($complete === []) {
            return $result;
        }

        $eligible = [];

        foreach ($this->completeRows($stats, $asOf)->groupBy('reader_staff_id') as $staffId => $readerRows) {
            $visited = (int) $readerRows->sum('visited_count');
            $activeMonths = $readerRows->where('visited_count', '>', 0)->pluck('month')->map(fn ($m) => $this->monthKey($m))->unique()->count();

            if (! self::hasEnoughVisits($visited, $activeMonths, $minVisits)) {
                $result['excluded']++;

                continue;
            }

            $eligible[] = [
                ...$this->identity((string) $staffId, $readerRows),
                'visited' => $visited,
                'skipped' => (int) $readerRows->sum('skipped_count'),
                'skip_rate' => self::rate($readerRows->sum('skipped_count'), $visited),
            ];
        }

        $result['eligible'] = count($eligible);
        $rates = array_column($eligible, 'skip_rate');
        $mean = $rates === [] ? null : array_sum($rates) / count($rates);
        $stdev = count($rates) < 2 ? null : sqrt(array_sum(array_map(fn ($rate) => ($rate - $mean) ** 2, $rates)) / count($rates));

        foreach ($eligible as $index => $row) {
            $eligible[$index]['z'] = $stdev ? round(($row['skip_rate'] - $mean) / $stdev, 2) : null;
            $eligible[$index]['flagged'] = $eligible[$index]['z'] !== null && $eligible[$index]['z'] > $zThreshold;
        }

        usort($eligible, fn (array $a, array $b) => [$b['z'] ?? -INF, $a['staff_id']] <=> [$a['z'] ?? -INF, $b['staff_id']]);

        $result['mean'] = $mean === null ? null : round($mean, 2);
        $result['stdev'] = $stdev === null ? null : round($stdev, 2);
        $result['rows'] = $eligible;
        $result['flagged'] = array_values(array_filter($eligible, fn (array $row) => $row['flagged']));

        return $result;
    }

    /**
     * R13: an indicative 0-100 score per reader: the weighted percentile rank of volume (more is better), skip rate
     * (lower is better) and consistency (lower coefficient of variation is better) over the complete months. Where a
     * reader's consistency cannot be worked out the remaining weights are re-normalised.
     *
     * @return array{enough_months: bool, weights: array<string, float>, rows: list<array<string, mixed>>}
     */
    public function scorecard(Collection $stats, Carbon $asOf): array
    {
        $weights = array_merge(['volume' => 0.4, 'skip' => 0.4, 'consistency' => 0.2], (array) config('gwl.commercial_scorecard_weights', []));
        $complete = $this->completeMonths($stats, $asOf);

        if ($complete === []) {
            return ['enough_months' => false, 'weights' => $weights, 'rows' => []];
        }

        $cvs = collect($this->consistency($stats, $asOf)['rows'])->keyBy('staff_id');
        $readers = [];

        foreach ($this->completeRows($stats, $asOf)->groupBy('reader_staff_id') as $staffId => $readerRows) {
            $visited = (int) $readerRows->sum('visited_count');

            if ($visited === 0) {
                continue;
            }

            $readers[$staffId] = [
                ...$this->identity((string) $staffId, $readerRows),
                'visited' => $visited,
                'skip_rate' => self::rate($readerRows->sum('skipped_count'), $visited),
                'cv' => $cvs[(string) $staffId]['cv'] ?? null,
            ];
        }

        $volumePct = $this->percentiles(array_map(fn ($r) => $r['visited'], $readers), higherIsBetter: true);
        $skipPct = $this->percentiles(array_map(fn ($r) => $r['skip_rate'], $readers), higherIsBetter: false);
        $cvPct = $this->percentiles(array_filter(array_map(fn ($r) => $r['cv'], $readers), fn ($cv) => $cv !== null), higherIsBetter: false);

        $rows = [];

        foreach ($readers as $staffId => $reader) {
            $parts = ['volume' => $volumePct[$staffId] ?? null, 'skip' => $skipPct[$staffId] ?? null, 'consistency' => $cvPct[$staffId] ?? null];
            $used = array_filter($parts, fn ($value) => $value !== null);
            $weightSum = array_sum(array_intersect_key($weights, $used));

            $score = $weightSum > 0
                ? round(array_sum(array_map(fn ($key) => $used[$key] * $weights[$key], array_keys($used))) / $weightSum, 1)
                : null;

            $rows[] = [...$reader, 'volume_pct' => $parts['volume'], 'skip_pct' => $parts['skip'], 'consistency_pct' => $parts['consistency'], 'score' => $score];
        }

        usort($rows, fn (array $a, array $b) => [$b['score'] ?? -1, $a['staff_id']] <=> [$a['score'] ?? -1, $b['staff_id']]);

        return ['enough_months' => true, 'weights' => $weights, 'rows' => $rows];
    }

    /**
     * One reader across the months in the rows: their monthly figures and totals (the reader detail screen).
     *
     * @return array{identity: array<string, mixed>, months: list<array<string, mixed>>}
     */
    public function readerMonths(Collection $readerRows, Carbon $asOf): array
    {
        $months = [];

        foreach ($readerRows->sortBy(fn ($row) => $this->monthKey($row->month)) as $row) {
            $month = $this->monthKey($row->month);

            $months[] = [
                'month' => $month,
                'label' => Carbon::parse($month)->format('M Y'),
                'in_progress' => self::isInProgress($month, $asOf),
                'visited' => (int) $row->visited_count,
                'read' => (int) $row->read_count,
                'skipped' => (int) $row->skipped_count,
                'skip_rate' => self::rate($row->skipped_count, $row->visited_count),
                'read_rate' => self::rate($row->read_count, $row->visited_count),
            ];
        }

        return ['identity' => $this->identity((string) $readerRows->first()->reader_staff_id, $readerRows), 'months' => $months];
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<string> complete months present in the rows, ascending */
    public function completeMonths(Collection $stats, Carbon $asOf): array
    {
        return $stats->pluck('month')
            ->map(fn ($month) => $this->monthKey($month))
            ->unique()
            ->reject(fn (string $month) => self::isInProgress($month, $asOf))
            ->sort()
            ->values()
            ->all();
    }

    protected function completeRows(Collection $stats, Carbon $asOf): Collection
    {
        return $stats->reject(fn ($row) => self::isInProgress($this->monthKey($row->month), $asOf));
    }

    /** @return array<string, mixed>|null */
    protected function consistencyRow(string $staffId, Collection $readerRows): ?array
    {
        $visits = $readerRows->sortBy(fn ($row) => $this->monthKey($row->month))->pluck('visited_count')->map(fn ($v) => (int) $v)->values()->all();

        // The months before a reader's first visit are months they had not started, not months they were inconsistent.
        while ($visits !== [] && $visits[0] === 0) {
            array_shift($visits);
        }

        if (count($visits) < self::MIN_MONTHS_FOR_CONSISTENCY) {
            return null;
        }

        $mean = array_sum($visits) / count($visits);
        $stdev = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $visits)) / count($visits));

        return [
            ...$this->identity($staffId, $readerRows),
            'months' => count($visits),
            'mean' => round($mean, 1),
            'stdev' => round($stdev, 1),
            'cv' => $mean > 0 ? round($stdev / $mean * 100, 2) : null,
            'min' => min($visits),
            'max' => max($visits),
            'months_below_average' => count(array_filter($visits, fn ($v) => $v < $mean)),
        ];
    }

    /**
     * Who a reader is, from their rows (the latest row's name, the directory name when matched).
     *
     * @return array{staff_id: string, name: string, employee_id: int|null, district_id: int|null, district: string|null, match_status: string}
     */
    protected function identity(string $staffId, Collection $rows): array
    {
        $latest = $rows->sortByDesc(fn ($row) => $this->monthKey($row->month))->first();

        return [
            'staff_id' => $staffId,
            'name' => (string) ($latest->employee?->full_name ?: $latest->reader_name_raw ?: $staffId),
            'employee_id' => $latest->employee_id,
            'district_id' => $latest->district_id,
            'district' => $latest->district?->district_name,
            'match_status' => (string) $latest->match_status,
        ];
    }

    /**
     * 0-100 percentile rank of each value among the others: the share of other readers it beats, a tie counting half.
     * A single value scores 100.
     *
     * @param  array<string|int, float|int|null>  $values
     * @return array<string|int, float>
     */
    protected function percentiles(array $values, bool $higherIsBetter): array
    {
        $values = array_filter($values, fn ($value) => $value !== null);
        $count = count($values);
        $out = [];

        foreach ($values as $key => $value) {
            if ($count === 1) {
                $out[$key] = 100.0;

                continue;
            }

            $beaten = 0.0;

            foreach ($values as $otherKey => $other) {
                if ($otherKey === $key) {
                    continue;
                }

                if ($other == $value) {
                    $beaten += 0.5;
                } elseif ($higherIsBetter ? $value > $other : $value < $other) {
                    $beaten += 1;
                }
            }

            $out[$key] = round($beaten / ($count - 1) * 100, 1);
        }

        return $out;
    }

    /** @param  list<int|float>  $values */
    protected function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    protected function monthKey(mixed $month): string
    {
        return $month instanceof \DateTimeInterface ? $month->format('Y-m-d') : substr((string) $month, 0, 10);
    }
}
