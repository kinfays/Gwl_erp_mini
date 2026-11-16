<?php

namespace App\Services\Commercial;

use Illuminate\Support\Carbon;

/**
 * COMPARABLE PERIODS (Phase 8f, design 8.45): the one place that decides which upload another is compared with, so every trend (period on period,
 * rank movement, year on year, the projection's "same month") uses the same rules and they cannot disagree.
 *
 * Uploads of the route-grain reports cover ANY dates the uploader chose, and each is one POINT with its own dates. A 7-day upload must never be set
 * against a 31-day one, so:
 *
 *   previous      the live upload of the same report type and region that ENDS BEFORE the current period STARTS and whose LENGTH (days, both ends counted)
 *                 is the same within the tolerance (commercial_trend_length_tolerance_days); the latest such one. None: "no comparable previous period".
 *   last year     the upload that covers the same CALENDAR dates a year earlier: it starts within the tolerance of the current start less one year and
 *                 its length is the same within the tolerance (the nearest start first, then the newest). None: no upload covers them.
 *
 * Units are what CommercialPeriods uses: ['id', 'from', 'to', 'order'] (Y-m-d) of the live uploads of ONE type and region. A unit that a newer
 * unit contains or repeats is shadowed (CommercialPeriods rules 1 and 2) and is never compared with.
 */
class PeriodComparison
{
    public function __construct(protected ?int $tolerance = null) {}

    public function tolerance(): int
    {
        return max(0, $this->tolerance ?? (int) config('gwl.commercial_trend_length_tolerance_days', 3));
    }

    /** Days in a period, both ends counted. */
    public static function length(string $from, string $to): int
    {
        return (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1;
    }

    /**
     * The units that count: those no newer unit contains or repeats.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return list<array{id: int|string, from: string, to: string, order: int}>
     */
    public function effective(array $units): array
    {
        $shadowed = array_column((new CommercialPeriods)->select($units)['shadowed'], 'id');

        return array_values(array_filter($units, fn (array $u) => ! in_array($u['id'], $shadowed, true)));
    }

    /**
     * The period asked for: with no range typed the newest upload (the later period end, then the later id); with a range, the uploads inside it that
     * do not overlap (the period model), from the first one's start to the last one's end. Null when nothing qualifies.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return array{from: string, to: string, length: int, batch_ids: list<int|string>, typed: bool, covered_days: int|null, range_days: int|null}|null
     */
    public function current(array $units, ?string $from = null, ?string $to = null): ?array
    {
        $units = $this->effective($units);
        $start = CommercialPeriods::bound($from, false);
        $end = CommercialPeriods::bound($to, true);

        if ($units === []) {
            return null;
        }

        if ($start === null && $end === null) {
            usort($units, fn (array $a, array $b) => [$b['to'], $b['order']] <=> [$a['to'], $a['order']]);
            $u = $units[0];

            return ['from' => $u['from'], 'to' => $u['to'], 'length' => self::length($u['from'], $u['to']), 'batch_ids' => [$u['id']], 'typed' => false, 'covered_days' => null, 'range_days' => null];
        }

        $selection = (new CommercialPeriods)->select($units, $start?->toDateString(), $end?->toDateString());

        if ($selection['chosen'] === []) {
            return null;
        }

        $chosen = array_values(array_filter($units, fn (array $u) => in_array($u['id'], $selection['chosen'], true)));
        $first = min(array_column($chosen, 'from'));
        $last = max(array_column($chosen, 'to'));

        return ['from' => $first, 'to' => $last, 'length' => self::length($first, $last), 'batch_ids' => array_column($chosen, 'id'), 'typed' => true, 'covered_days' => $selection['covered_days'], 'range_days' => $selection['range_days']];
    }

    /**
     * The previous comparable upload: it ends before $from, its length is the same within the tolerance; the latest such one.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return array{id: int|string, from: string, to: string, order: int}|null
     */
    public function previous(array $units, string $from, string $to): ?array
    {
        $length = self::length($from, $to);
        $best = null;

        foreach ($this->effective($units) as $u) {
            if ($u['to'] >= $from || abs(self::length($u['from'], $u['to']) - $length) > $this->tolerance()) {
                continue;
            }

            if ($best === null || [$u['to'], $u['order']] > [$best['to'], $best['order']]) {
                $best = $u;
            }
        }

        return $best;
    }

    /**
     * The upload covering the same calendar dates a year earlier (same tolerance on the start and on the length): the nearest start, then the newest.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return array{id: int|string, from: string, to: string, order: int}|null
     */
    public function lastYear(array $units, string $from, string $to): ?array
    {
        $length = self::length($from, $to);
        $target = Carbon::parse($from)->startOfDay()->subYear();
        $best = null;
        $bestGap = null;

        foreach ($this->effective($units) as $u) {
            $gap = (int) abs($target->diffInDays(Carbon::parse($u['from'])->startOfDay(), false));

            if ($gap > $this->tolerance() || abs(self::length($u['from'], $u['to']) - $length) > $this->tolerance()) {
                continue;
            }

            if ($best === null || [$gap, -$u['order']] < [$bestGap, -$best['order']]) {
                $best = $u;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /**
     * The current period, its previous comparable one and the same period last year, with the sentences a page prints when one is missing.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return array{current: array<string, mixed>|null, previous: array<string, mixed>|null, last_year: array<string, mixed>|null, previous_note: string|null, last_year_note: string|null}
     */
    public function resolve(array $units, ?string $from = null, ?string $to = null): array
    {
        $current = $this->current($units, $from, $to);

        if ($current === null) {
            return ['current' => null, 'previous' => null, 'last_year' => null, 'previous_note' => 'No upload lies inside the range.', 'last_year_note' => 'No upload lies inside the range.'];
        }

        $previous = $this->previous($units, $current['from'], $current['to']);
        $lastYear = $this->lastYear($units, $current['from'], $current['to']);

        return [
            'current' => $current, 'previous' => $previous, 'last_year' => $lastYear,
            'previous_note' => $previous ? null : 'No comparable previous period: no upload of about '.$current['length'].' days (within '.$this->tolerance().') ends before '.Carbon::parse($current['from'])->format('j M Y').'.',
            'last_year_note' => $lastYear ? null : 'No upload covers '.CommercialPeriods::describe(Carbon::parse($current['from'])->subYear(), Carbon::parse($current['to'])->subYear()).' (the same dates a year earlier).',
        ];
    }
}
