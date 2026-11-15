<?php

namespace App\Services\Commercial;

/**
 * THE owner rule of the reading report by route (design 8.36). A route can appear under TWO CCAs in one period: one row carries the
 * route's verified strength with ZERO visits (a CCA the route was taken from but who is still listed), the other the visits. Summing
 * strength over rows would count such a route twice, so for each route and period ONE row is the owner and the route's strength
 * counts once, in that row.
 *
 *   1. the row with the most visits;
 *   2. on a tie, the larger verified strength;
 *   3. on a tie, the lower staff id (numerically when both are numbers, else as text).
 *
 * The other rows are kept and marked "assigned, no visits" (is_route_owner = false). Pure: no database, no clock.
 */
final class ReadingRouteOwner
{
    /**
     * @param  list<array{cca: string, strength: int|float, visited: int|float}>  $rows  every row of ONE route in ONE period
     * @return int|null the index (in $rows) of the owner row, null for no rows
     */
    public static function ownerIndex(array $rows): ?int
    {
        $best = null;

        foreach ($rows as $index => $row) {
            if ($best === null || self::beats($row, $rows[$best])) {
                $best = $index;
            }
        }

        return $best;
    }

    /** @param  array{cca: string, strength: int|float, visited: int|float}  $a @param  array{cca: string, strength: int|float, visited: int|float}  $b */
    protected static function beats(array $a, array $b): bool
    {
        if ($a['visited'] !== $b['visited']) {
            return $a['visited'] > $b['visited'];
        }

        if ($a['strength'] !== $b['strength']) {
            return $a['strength'] > $b['strength'];
        }

        return self::staffOrder($a['cca'], $b['cca']) < 0;
    }

    /** Numeric staff ids compare as numbers ("9" before "10"), anything else as text. */
    public static function staffOrder(string $a, string $b): int
    {
        return ctype_digit($a) && ctype_digit($b) ? ((int) $a <=> (int) $b) : strcmp($a, $b);
    }
}
