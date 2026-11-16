<?php

namespace App\Services\Commercial\Trends;

/**
 * The arithmetic of the trends (Phase 8f, design 8.45), written once and pure (no database, no user), so every trend page and file works a change, a
 * rank and a projection the same way and each can be checked by hand.
 *
 *   MEASURES   the headline measures of each kind: its label, its unit (pct = a ratio in percent, n = a count, m = money in GH¢) and which direction is
 *              GOOD ('up', 'down', or null when the existing targets and thresholds say nothing): skip rate and unvisited are good when they fall,
 *              coverage and the collection ratio when they rise (the existing targets), the estimated and unbilled shares when they fall (the "high"
 *              thresholds of the billing exceptions), the owing balance when it falls, the contactable share when it rises; the month total and the
 *              number of billing accounts have no direction, so a change in them is only shown.
 *   change     a ratio changes in PERCENTAGE POINTS (now - then); a count or an amount in absolute terms AND as a percentage of the earlier value.
 *   tone       'good' or 'bad' by the direction above; 'neutral' for no change or no direction; null when either value is missing.
 *   rank       1 = the best: ascending for a rate that is good when low (skip rate), descending for one good when high (collection ratio); a tie is
 *              broken by the label, so a rank never depends on the order the rows came in.
 */
final class TrendFigures
{
    public const MEASURES = [
        'reading' => [
            'coverage' => ['label' => 'Coverage', 'unit' => 'pct', 'good' => 'up'],
            'skip_rate' => ['label' => 'Skip rate', 'unit' => 'pct', 'good' => 'down'],
            'unvisited' => ['label' => 'Unvisited', 'unit' => 'n', 'good' => 'down'],
        ],
        'billing' => [
            'estimated_share' => ['label' => 'Estimated bills', 'unit' => 'pct', 'good' => 'down'],
            'unbilled_share' => ['label' => 'Unbilled', 'unit' => 'pct', 'good' => 'down'],
            'collection_ratio' => ['label' => 'Collection ratio', 'unit' => 'pct', 'good' => 'up'],
            'owing_amount' => ['label' => 'Owing balance (GH¢)', 'unit' => 'm', 'good' => 'down'],
            'month_total' => ['label' => 'Month total (GH¢)', 'unit' => 'm', 'good' => null],
        ],
        'customers' => [
            'billing_accounts' => ['label' => 'Billing accounts', 'unit' => 'n', 'good' => null],
            'contactable_share' => ['label' => 'Contactable', 'unit' => 'pct', 'good' => 'up'],
        ],
    ];

    /**
     * A measure's value now and then, the change and its tone.
     *
     * @param  array{label: string, unit: string, good: string|null}  $def
     * @return array{key: string, label: string, unit: string, good: string|null, now: float|int|null, then: float|int|null, change: float|int|null, change_pct: float|null, tone: string|null}
     */
    public static function measure(string $key, array $def, float|int|null $now, float|int|null $then): array
    {
        $change = $now !== null && $then !== null ? round($now - $then, 2) : null;
        $pct = $change !== null && $def['unit'] !== 'pct' && $then != 0 ? round($change / abs($then) * 100, 2) : null;

        return ['key' => $key, 'label' => $def['label'], 'unit' => $def['unit'], 'good' => $def['good'], 'now' => $now, 'then' => $then, 'change' => $change, 'change_pct' => $pct, 'tone' => self::tone($def['good'], $change)];
    }

    public static function tone(?string $good, float|int|null $change): ?string
    {
        if ($change === null) {
            return null;
        }

        if ($good === null || $change == 0) {
            return 'neutral';
        }

        return ($change > 0) === ($good === 'up') ? 'good' : 'bad';
    }

    /**
     * Every measure of a kind from two sets of values (key => value), in the order of MEASURES.
     *
     * @param  array<string, float|int|null>  $now
     * @param  array<string, float|int|null>|null  $then  null when there is no comparable period
     * @return list<array<string, mixed>>
     */
    public static function measures(string $kind, array $now, ?array $then): array
    {
        $out = [];

        foreach (self::MEASURES[$kind] as $key => $def) {
            $out[] = self::measure($key, $def, $now[$key] ?? null, $then === null ? null : ($then[$key] ?? null));
        }

        return $out;
    }

    /**
     * Ranks (1 = best) of items keyed by id: only items with a value are ranked; ascending puts the lowest value first. A tie goes by the label.
     *
     * @param  array<int|string, array{value: float|int|null, label: string}>  $items
     * @return array<int|string, int>
     */
    public static function ranks(array $items, bool $ascending): array
    {
        $ranked = array_filter($items, fn (array $i) => $i['value'] !== null);
        uasort($ranked, fn (array $a, array $b) => [$ascending ? $a['value'] : -$a['value'], $a['label']] <=> [$ascending ? $b['value'] : -$b['value'], $b['label']]);
        $out = [];
        $n = 0;

        foreach (array_keys($ranked) as $id) {
            $out[$id] = ++$n;
        }

        return $out;
    }

    /**
     * How an item's rank moved: up (a lower number), down, the same, new (ranked now, not before) or gone (ranked before, not now).
     *
     * @return array{move: int|null, direction: string}
     */
    public static function move(?int $now, ?int $then): array
    {
        return match (true) {
            $now !== null && $then === null => ['move' => null, 'direction' => 'new'],
            $now === null && $then !== null => ['move' => null, 'direction' => 'gone'],
            $now === null => ['move' => null, 'direction' => 'none'],
            $then === $now => ['move' => 0, 'direction' => 'same'],
            default => ['move' => $then - $now, 'direction' => $now < $then ? 'up' : 'down'],
        };
    }

    /**
     * The projection of a FLOW measure to the end of a month that is only part covered: what the covered days hold, per covered day, times the days in
     * the month. Null when the month is complete (nothing to project) or fewer days than the minimum are covered (too little to say anything). A stock
     * (a balance, a strength) is never projected: callers only offer flows.
     *
     * @return array{state: string, projection: float|null, per_day: float|null}
     */
    public static function projection(float|int $actual, int $coveredDays, int $daysInMonth, int $minDays): array
    {
        if ($coveredDays <= 0) {
            return ['state' => 'none', 'projection' => null, 'per_day' => null];
        }

        if ($coveredDays >= $daysInMonth) {
            return ['state' => 'complete', 'projection' => null, 'per_day' => round($actual / $coveredDays, 4)];
        }

        if ($coveredDays < $minDays) {
            return ['state' => 'withheld', 'projection' => null, 'per_day' => null];
        }

        $perDay = $actual / $coveredDays;

        return ['state' => 'projected', 'projection' => round($perDay * $daysInMonth, 2), 'per_day' => round($perDay, 4)];
    }
}
