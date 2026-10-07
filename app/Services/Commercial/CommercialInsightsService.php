<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where reading and billing meet (design 4.3 and 8.12): the district scorecard (C1), estimation against skip rate (C2),
 * month-to-date pace (R14) and the executive summary (C3). It reuses ReadingAnalyticsService and BillingAnalyticsService
 * for every figure and takes already-scoped data, like they do.
 *
 * The two reports are NOT like for like, and the screens say so:
 *  - billing is a customer segment (the samples are "New Service Customers Only") while reading covers everyone;
 *  - reading by district is by the reader's HOME district from the staff directory (the report has no district split);
 *  - a correlation is only worked out from enough paired months and is never called causal;
 *  - the reports print no run date, so a snapshot's time is its UPLOAD time (imported_at).
 */
class CommercialInsightsService
{
    /** Paired months before a coefficient is shown. */
    public const MIN_PAIRS = 6;

    public function __construct(
        protected ReadingAnalyticsService $reading,
        protected BillingAnalyticsService $billing,
    ) {
    }

    /** True when billing is a segment of customers while reading covers all of them, so a note is needed. */
    public static function notLikeForLike(?string $segment): bool
    {
        return $segment !== null && $segment !== CommercialImportBatch::SEGMENT_ALL;
    }

    public static function notLikeForLikeNote(?array $snapshot): string
    {
        return 'Billing covers '.($snapshot['segment_label'] ?? 'a segment of').' customers only; reading covers all customers: not like for like.';
    }

    // ---------------------------------------------------------------- C1

    /**
     * C1: one row per district. Billing figures come from the chosen snapshot; reading figures are the complete months
     * inside the snapshot's period, grouped by the reader's home district. A district seen on one side only shows "–" on
     * the other. Coverage is not shown per district (the strength is region-wide).
     *
     * With $hideSmallGroups (for a user who may not see individual readers) a district's reading figures are withheld when
     * fewer than $minReaders distinct readers had visits in the months shown: in a district of one reader the row would
     * BE that person's figures. The billing columns have no staff in them and are never held back.
     *
     * @param  array<string, mixed>  $snapshot  BillingAnalyticsService::describe()
     * @param  Collection  $routes  the snapshot's routes
     * @param  Collection  $readingStats  effective reader rows of the snapshot's region (district relation loaded)
     * @return array<string, mixed>
     */
    public function districtScorecard(array $snapshot, Collection $routes, Collection $readingStats, Carbon $asOf, bool $hideSmallGroups = false, ?int $minReaders = null): array
    {
        $minReaders ??= max(1, (int) config('gwl.commercial_min_readers_for_district_figures', 3));
        $months = collect($this->reading->completeMonths($readingStats, $asOf))
            ->filter(fn (string $month) => $month >= $snapshot['month'] && $month <= $snapshot['period_to'])
            ->values();

        $stats = $readingStats->filter(fn ($row) => $months->contains(substr((string) $this->monthString($row->month), 0, 10)));

        // Billing side, per district as printed.
        $collections = collect($this->billing->collections($routes)['districts'])->keyBy('key');
        $estimation = collect($this->billing->estimationAndUnbilled($routes)['districts'])->keyBy('key');
        $ids = $routes->groupBy('district_label_raw')->map(fn (Collection $group) => $group->pluck('district_id')->filter()->first());

        // Reading side, per home district (null = reader not in the directory).
        $readingRows = [];

        foreach ($stats->groupBy(fn ($row) => $row->district_id ?? 'none') as $key => $group) {
            $visits = (int) $group->sum('visited_count');
            $active = $group->where('visited_count', '>', 0)->pluck('reader_staff_id')->unique()->count();

            if ($hideSmallGroups && $active < $minReaders) {
                $readingRows[(string) $key] = [
                    'district_id' => $key === 'none' ? null : (int) $key,
                    'name' => $key === 'none' ? 'No home district (reader not in the directory)' : (string) ($group->first()->district?->district_name ?? 'District '.$key),
                    'hidden' => true,
                    'visits' => null, 'read' => null, 'skipped' => null, 'skip_rate' => null, 'active_readers' => null, 'visits_per_reader' => null,
                ];

                continue;
            }

            $readingRows[(string) $key] = [
                'hidden' => false,
                'district_id' => $key === 'none' ? null : (int) $key,
                'name' => $key === 'none' ? 'No home district (reader not in the directory)' : (string) ($group->first()->district?->district_name ?? 'District '.$key),
                'visits' => $visits,
                'read' => (int) $group->sum('read_count'),
                'skipped' => (int) $group->sum('skipped_count'),
                'skip_rate' => ReadingAnalyticsService::rate($group->sum('skipped_count'), $visits),
                'active_readers' => $active,
                'visits_per_reader' => $active > 0 ? round($visits / $active, 1) : null,
            ];
        }

        $rows = [];
        $usedReading = [];

        foreach ($collections as $label => $col) {
            $districtId = $ids[$label] ?? null;
            $match = null;

            // By district id where both sides have one; otherwise by the district label as printed.
            if ($districtId !== null && isset($readingRows[(string) $districtId])) {
                $match = (string) $districtId;
            } else {
                foreach ($readingRows as $key => $row) {
                    if ($row['district_id'] !== null && ! isset($usedReading[$key]) && $this->sameName($row['name'], (string) $label)) {
                        $match = (string) $key;
                        break;
                    }
                }
            }

            if ($match !== null) {
                $usedReading[$match] = true;
            }

            $est = $estimation[$label] ?? null;

            $rows[] = [
                'district' => $col['district'],
                'key' => (string) $label,
                'district_id' => $districtId,
                'billing_side' => true,
                'billing' => $col['billing'],
                'payments' => $col['payments'],
                'cash_ratio' => $col['cash_ratio'],
                'estimation' => $est['estimation_volume'] ?? null,
                'unbilled_rate' => $est['unbilled_rate'] ?? null,
                'reading_side' => $match !== null,
                ...$this->readingColumns($match !== null ? $readingRows[$match] : null),
            ];
        }

        foreach ($readingRows as $key => $row) {
            if (isset($usedReading[$key])) {
                continue;
            }

            $rows[] = [
                'district' => $row['name'],
                'key' => '',
                'district_id' => $row['district_id'],
                'billing_side' => false,
                'billing' => null, 'payments' => null, 'cash_ratio' => null, 'estimation' => null, 'unbilled_rate' => null,
                'reading_side' => true,
                ...$this->readingColumns($row),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['billing'] ?? -INF, $a['district']] <=> [$a['billing'] ?? -INF, $b['district']]);

        return [
            'rows' => $rows,
            'reading_months' => $months->map(fn (string $month) => Carbon::parse($month)->format('M Y'))->all(),
            'reading_available' => $months->isNotEmpty(),
            'segment_note' => self::notLikeForLike($snapshot['segment']),
            'hide_small_groups' => $hideSmallGroups,
            'min_readers' => $minReaders,
        ];
    }

    // ---------------------------------------------------------------- C2

    /**
     * C2: for each month that has BOTH a single-month billing snapshot and a complete reading month, the estimation share
     * (by volume) next to the skip rate. A Pearson coefficient only from at least MIN_PAIRS pairs, and never called causal.
     *
     * @param  list<array{meta: array<string, mixed>, routes: Collection}>  $billingSnapshots  one region and segment
     * @param  Collection  $readingStats  effective reader rows of that region
     * @return array<string, mixed>
     */
    public function estimationVsSkip(array $billingSnapshots, Collection $readingStats, Carbon $asOf): array
    {
        $complete = $this->reading->completeMonths($readingStats, $asOf);
        $pairs = [];

        foreach ($billingSnapshots as $snapshot) {
            if (! $snapshot['meta']['is_single_month'] || ! in_array($snapshot['meta']['month'], $complete, true)) {
                continue;
            }

            $month = $snapshot['meta']['month'];
            $rows = $readingStats->filter(fn ($row) => $this->monthString($row->month) === $month);
            $skipRate = ReadingAnalyticsService::rate($rows->sum('skipped_count'), $rows->sum('visited_count'));
            $estimation = $this->billing->metrics($this->billing->totals($snapshot['routes']))['estimation_volume'];

            if ($skipRate === null || $estimation === null) {
                continue;
            }

            $pairs[$month] = [
                'month' => $month,
                'label' => Carbon::parse($month)->format('M Y'),
                'estimation' => $estimation,
                'skip_rate' => $skipRate,
            ];
        }

        ksort($pairs);
        $pairs = array_values($pairs);

        $coefficient = null;
        $reason = null;

        if (count($pairs) < self::MIN_PAIRS) {
            $reason = 'A coefficient needs at least '.self::MIN_PAIRS.' months with both a single-month billing snapshot and a complete reading month; there '.(count($pairs) === 1 ? 'is' : 'are').' '.count($pairs).'. The two series are shown side by side.';
        } else {
            $coefficient = self::pearson(array_column($pairs, 'estimation'), array_column($pairs, 'skip_rate'));

            if ($coefficient === null) {
                $reason = 'One of the series does not vary, so no coefficient can be worked out.';
            }
        }

        return [
            'pairs' => $pairs,
            'count' => count($pairs),
            'needed' => self::MIN_PAIRS,
            'coefficient' => $coefficient,
            'reason' => $reason,
        ];
    }

    /** Pearson correlation of two equally long series, rounded to 2 dp; null when either does not vary. */
    public static function pearson(array $x, array $y): ?float
    {
        $n = count($x);

        if ($n < 2 || $n !== count($y)) {
            return null;
        }

        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;

        foreach ($x as $i => $value) {
            $sxy += ($value - $mx) * ($y[$i] - $my);
            $sxx += ($value - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }

        return $sxx > 0 && $syy > 0 ? round($sxy / sqrt($sxx * $syy), 2) : null;
    }

    // ---------------------------------------------------------------- R14

    /**
     * R14: a month covered by two or more reading uploads, at each upload (by upload time): visits, reads and skip rate so
     * far, and a straight-line projection = visits so far / the fraction of the month elapsed when it was uploaded. The
     * projection is indicative and is compared with the previous complete month.
     *
     * @param  Collection  $rows  per upload and month: batch_id, read_total, skipped_total, visited_total (sums over readers; voided uploads left out)
     * @param  Collection<int, CommercialImportBatch>  $batches  keyed by id
     * @param  string  $month  first of the month, Y-m-d
     * @return array<string, mixed>
     */
    public function monthToDatePace(Collection $rows, Collection $batches, string $month, ?int $previousVisits, ?string $previousLabel): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $length = $start->diffInSeconds($start->copy()->addMonth());

        $snapshots = $rows
            ->filter(fn ($row) => $batches->has((int) $row->batch_id))
            ->map(function ($row) use ($batches, $start, $length, $previousVisits): array {
                $batch = $batches[(int) $row->batch_id];
                $uploaded = Carbon::parse($batch->imported_at ?? $batch->created_at);
                $fraction = max(0.0, min(1.0, $start->diffInSeconds($uploaded, false) / $length));
                $visits = (int) $row->visited_total;
                $projected = $fraction >= 1.0 ? $visits : ($fraction > 0 ? (int) round($visits / $fraction) : null);

                return [
                    'batch_id' => (int) $row->batch_id,
                    'uploaded_at' => $uploaded,
                    'fraction' => round($fraction * 100, 1),
                    'visits' => $visits,
                    'read' => (int) $row->read_total,
                    'skipped' => (int) $row->skipped_total,
                    'skip_rate' => ReadingAnalyticsService::rate($row->skipped_total, $visits),
                    'projected_visits' => $projected,
                    'vs_previous_pct' => $projected !== null && $previousVisits ? round(($projected - $previousVisits) / $previousVisits * 100, 2) : null,
                ];
            })
            ->sortBy(fn (array $snapshot) => [$snapshot['uploaded_at']->timestamp, $snapshot['batch_id']])
            ->values()
            ->all();

        return [
            'month' => $month,
            'label' => $start->format('M Y'),
            'enough' => count($snapshots) >= 2,
            'snapshots' => $snapshots,
            'previous' => ['label' => $previousLabel, 'visits' => $previousVisits],
        ];
    }

    // ---------------------------------------------------------------- C3

    /**
     * C3: the one-page summary. Each section is null unless its input was passed (the caller only computes what the user
     * may see). No reader names anywhere on it.
     *
     * @param  array{
     *     trend?: list<array<string, mixed>>|null,
     *     unmatched_readers?: int|null,
     *     snapshot?: array<string, mixed>|null,
     *     routes?: Collection|null,
     *     snapshots?: list<array<string, mixed>>|null,
     *     overdue?: list<array<string, mixed>>,
     *     batches?: Collection<int, CommercialImportBatch>
     * }  $in
     * @return array<string, mixed>
     */
    public function executiveSummary(array $in): array
    {
        $targetSkip = (float) config('gwl.commercial_target_skip_rate_pct');
        $targetCoverage = (float) config('gwl.commercial_target_coverage_pct');
        $targetCollection = (float) config('gwl.commercial_target_collection_pct');

        $reading = null;

        if (isset($in['trend'])) {
            $complete = array_values(array_filter($in['trend'], fn (array $row) => ! $row['in_progress']));
            $latest = $complete === [] ? null : $complete[count($complete) - 1];

            $reading = $latest ? [
                'month' => $latest['label'],
                'visited' => $latest['visited'],
                'read' => $latest['read'],
                'skip_rate' => $latest['skip_rate'],
                'coverage' => $latest['coverage'],
                'target_skip' => $targetSkip,
                'target_coverage' => $targetCoverage,
                'skip_met' => $latest['skip_rate'] === null ? null : $latest['skip_rate'] <= $targetSkip,
                'coverage_met' => $latest['coverage'] === null ? null : $latest['coverage'] >= $targetCoverage,
            ] : ['month' => null];
        }

        $billing = null;
        $exceptions = null;

        if (isset($in['snapshot'], $in['routes'])) {
            $totals = $this->billing->totals($in['routes']);
            $metrics = $this->billing->metrics($totals);
            $flags = $this->billing->exceptions($in['routes']);

            $billing = [
                'snapshot' => $in['snapshot'],
                'billing' => $totals['billing_for_period'],
                'payments' => $totals['total_payments'],
                'cash_ratio' => $metrics['cash_ratio'],
                'target_collection' => $targetCollection,
                'collection_met' => $metrics['cash_ratio'] === null ? null : $metrics['cash_ratio'] >= $targetCollection,
                'estimation' => $metrics['estimation_volume'],
                'unbilled_rate' => $metrics['unbilled_rate'],
                'not_like_for_like' => self::notLikeForLike($in['snapshot']['segment']),
            ];

            $exceptions = ['counts' => $flags['counts'], 'flagged' => count($flags['rows']), 'routes' => $flags['routes'], 'thresholds' => $flags['thresholds']];
        }

        $batches = $in['batches'] ?? collect();

        return [
            'reading' => $reading,
            'billing' => $billing,
            'exceptions' => $exceptions,
            'freshness' => [...$this->freshness($batches, $in['snapshots'] ?? null), 'overdue' => $in['overdue'] ?? []],
            'quality' => [
                'unmatched_readers' => $in['unmatched_readers'] ?? null,
                'unresolved_districts' => isset($in['routes']) ? $in['routes']->whereNull('district_id')->pluck('district_label_raw')->unique()->count() : null,
                'multi_month_snapshots' => isset($in['snapshots']) ? count(array_filter($in['snapshots'], fn (array $s) => ! $s['is_single_month'])) : null,
            ],
        ];
    }

    /**
     * What has been loaded and how fresh it is. Times are UPLOAD times: the reports print no run date.
     *
     * @param  Collection<int, CommercialImportBatch>  $batches  non-voided, scoped
     * @param  list<array<string, mixed>>|null  $snapshots  billing snapshots (to list the periods loaded)
     * @return array<string, mixed>
     */
    public function freshness(Collection $batches, ?array $snapshots): array
    {
        $latest = fn (string $type) => $batches->where('report_type', $type)->sortByDesc('id')->first();
        $describe = fn (?CommercialImportBatch $batch) => $batch ? [
            'batch_id' => $batch->id,
            'region' => $batch->region?->region_name ?? $batch->region_label_raw,
            'uploaded_at' => $batch->imported_at,
            'period' => $batch->period_from->format('M Y').($batch->period_from->format('Y-m') === $batch->period_to->format('Y-m') ? '' : ' to '.$batch->period_to->format('M Y')),
        ] : null;

        // Months any reading upload covers, and the months missing between the first and the last of them.
        $covered = [];

        foreach ($batches->where('report_type', CommercialImportBatch::TYPE_READING_SUMMARY) as $batch) {
            for ($month = $batch->period_from->copy()->startOfMonth(); $month <= $batch->period_to; $month->addMonth()) {
                $covered[$month->format('Y-m')] = true;
            }
        }

        ksort($covered);
        $missing = [];

        if ($covered !== []) {
            $keys = array_keys($covered);

            for ($month = Carbon::parse($keys[0].'-01'); $month->format('Y-m') <= end($keys); $month->addMonth()) {
                if (! isset($covered[$month->format('Y-m')])) {
                    $missing[] = $month->format('M Y');
                }
            }
        }

        return [
            'reading' => $describe($latest(CommercialImportBatch::TYPE_READING_SUMMARY)),
            'billing' => $describe($latest(CommercialImportBatch::TYPE_BILLING_SUMMARY)),
            'reading_months' => array_map(fn (string $key) => Carbon::parse($key.'-01')->format('M Y'), array_keys($covered)),
            'reading_months_missing' => $missing,
            'billing_periods' => $snapshots === null ? null : array_map(fn (array $s) => $s['period_label'].' ('.$s['segment_label'].')', $snapshots),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>
     */
    protected function readingColumns(?array $row): array
    {
        return [
            'reading_hidden' => (bool) ($row['hidden'] ?? false),
            'visits' => $row['visits'] ?? null,
            'read' => $row['read'] ?? null,
            'skip_rate' => $row['skip_rate'] ?? null,
            'active_readers' => $row['active_readers'] ?? null,
            'visits_per_reader' => $row['visits_per_reader'] ?? null,
        ];
    }

    protected function monthString(mixed $month): string
    {
        return $month instanceof \DateTimeInterface ? $month->format('Y-m-d') : substr((string) $month, 0, 10);
    }

    protected function sameName(string $a, string $b): bool
    {
        $normalize = fn (string $value) => preg_replace('/\s*([\/-])\s*/', '$1', mb_strtoupper(trim(preg_replace('/\s+/', ' ', $value))));

        return $normalize($a) === $normalize($b);
    }
}
