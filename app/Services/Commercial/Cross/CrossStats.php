<?php

namespace App\Services\Commercial\Cross;

/**
 * The small statistics the cross-file analyses use, written once. Everything is a plain function of numbers: no rows, no database.
 * A ratio is null on a zero denominator, a correlation is null when it cannot be worked out (fewer than 3 pairs, or no spread in one
 * of the two series): never a made-up zero.
 */
final class CrossStats
{
    /** A percentage (part of whole), null on a zero denominator. */
    public static function pct(int|float $part, int|float $whole, int $digits = 2): ?float
    {
        return $whole == 0 ? null : round($part / $whole * 100, $digits);
    }

    /** @param  list<int|float>  $values */
    public static function median(array $values): ?float
    {
        $values = array_values($values);
        $n = count($values);

        if ($n === 0) {
            return null;
        }

        sort($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * The p-th percentile (0 to 1) by linear interpolation between the closest ranks (the "inclusive" definition: position p x (n - 1)).
     *
     * @param  list<int|float>  $values
     */
    public static function percentile(array $values, float $p): ?float
    {
        $values = array_values($values);
        $n = count($values);

        if ($n === 0) {
            return null;
        }

        sort($values);
        $position = $p * ($n - 1);
        $low = (int) floor($position);
        $high = (int) ceil($position);

        return $values[$low] + ($values[$high] - $values[$low]) * ($position - $low);
    }

    /**
     * Pearson's correlation coefficient of two series of the same length: the sum of the products of the deviations from the means, over
     * the square root of the product of the two sums of squared deviations. Between -1 and 1; null with fewer than 3 pairs or when either
     * series does not vary. It measures how two figures move together; it says nothing about which one causes the other.
     *
     * @param  list<int|float>  $xs
     * @param  list<int|float>  $ys
     */
    public static function pearson(array $xs, array $ys, int $digits = 3): ?float
    {
        $n = min(count($xs), count($ys));

        if ($n < 3) {
            return null;
        }

        $xs = array_slice(array_values($xs), 0, $n);
        $ys = array_slice(array_values($ys), 0, $n);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        if ($sxx <= 0.0 || $syy <= 0.0) {
            return null;
        }

        return round($sxy / sqrt($sxx * $syy), $digits);
    }
}
