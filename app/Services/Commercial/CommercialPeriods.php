<?php

namespace App\Services\Commercial;

use App\Models\CommercialBillingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE period model of the reading and billing reports (design 8.30). A file carries a REPORTING PERIOD (reading) or a BILL PERIOD
 * (billing) and the uploader may pull any single day, any range or a whole month, so the module no longer assumes weekly or
 * monthly. Batches keep period_from / period_to exactly as the file prints them; "day", "range" and "month" are only labels
 * worked out from their length (label()). This one class decides, for the same report type, region (and billing segment):
 *
 *   1. IDENTICAL period: the newest non-voided batch wins.
 *   2. A newer batch whose period CONTAINS an older one's shadows it.
 *   3. PARTIAL overlap: both are kept; the upload preview names the other batch and warns; for a VIEW the non-overlapping set is
 *      chosen newest first, and the screen says which days of the chosen range are covered and which are not.
 *   4. Distinct, non-overlapping periods ADD UP for flows (read, skipped, visited, billed amounts, payments, consumption), with every
 *      ratio recomputed from the sums by the analytics; STOCKS (verified strength, balances, customer counts) take the latest period.
 *   5. Every view takes a date range (default: the latest uploaded period) and counts only batches whose period lies INSIDE it; a
 *      batch partly outside is excluded and listed.
 *
 * It works on "units": [id, from, to, order] where order says which is newer (a batch id). It never reads the database, so the
 * effective scopes, the screens, the exports and the upload preview all ask the same pure code. A whole-month batch is just a unit
 * of one month: on existing monthly data it reproduces what the screens always showed.
 *
 * @phpstan-type Unit array{id: int|string, from: string, to: string, order: int}
 */
final class CommercialPeriods
{
    public const DAY = 'day';

    public const MONTH = 'month';

    public const RANGE = 'range';

    // ---------------------------------------------------------------- labels

    /** "day" for one date, "month" for exactly one calendar month, "range" for anything else. */
    public static function label(Carbon|string $from, Carbon|string $to): string
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->equalTo($to)) {
            return self::DAY;
        }

        return $from->day === 1 && $to->equalTo($from->copy()->endOfMonth()->startOfDay()) ? self::MONTH : self::RANGE;
    }

    /** "5 Oct 2026", "1 Jul to 31 Aug 2026" or "Sep 2026" for a whole month. */
    public static function describe(Carbon|string $from, Carbon|string $to): string
    {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        return match (self::label($from, $to)) {
            self::DAY => $from->format('j M Y'),
            self::MONTH => $from->format('M Y'),
            default => $from->format('Y') === $to->format('Y') ? $from->format('j M').' to '.$to->format('j M Y') : $from->format('j M Y').' to '.$to->format('j M Y'),
        };
    }

    // ---------------------------------------------------------------- Phase 8h: the source of a billing summary

    /** The source ('upload' or 'derived') the setting commercial_billing_summary_preference prefers when both cover a period. */
    public static function preferredSource(): string
    {
        return config('gwl.commercial_billing_summary_preference') === 'details' ? 'derived' : 'upload';
    }

    /**
     * A billing batch as a period unit that knows its source (so select() applies the preference between an uploaded and a derived summary).
     *
     * @return array{id: int, from: string, to: string, order: int, source: string}
     */
    public static function unitOf(\App\Models\CommercialImportBatch $batch): array
    {
        return ['id' => (int) $batch->id, 'from' => $batch->period_from->toDateString(), 'to' => $batch->period_to->toDateString(), 'order' => (int) $batch->id, 'source' => $batch->isDerived() ? 'derived' : 'upload'];
    }

    // ---------------------------------------------------------------- range from what a person typed

    /**
     * A typed date or month as the first / last day it stands for: "2026-07" is the first (or last) day of July, "2026-07-15" that day.
     * Null for anything else.
     */
    public static function bound(?string $value, bool $end): ?Carbon
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $month = Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();

            return $end ? $month->copy()->endOfMonth()->startOfDay() : $month;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        }

        return null;
    }

    // ---------------------------------------------------------------- rules 1, 2, 3, 5

    /**
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units  every non-voided batch of ONE report type,
     *                                                                                       region (and segment)
     * @param  string|null  $from  first day of the range (null = the default: the latest uploaded period)
     * @param  string|null  $to  last day of the range
     * @return array{
     *     from: string|null, to: string|null, chosen: list<int|string>, shadowed: list<array{id: int|string, by: int|string, reason: string}>,
     *     overlaps: list<array{kept: int|string, dropped: int|string}>, partly_outside: list<int|string>, covered: list<array{0: string, 1: string}>,
     *     gaps: list<array{0: string, 1: string}>, covered_days: int, range_days: int, units: array<int|string, array<string, mixed>>
     * }
     */
    public function select(array $units, ?string $from = null, ?string $to = null): array
    {
        $units = array_values(array_filter($units, fn (array $u) => ! empty($u['from']) && ! empty($u['to'])));
        $units = array_map(fn (array $u) => ['id' => $u['id'], 'from' => Carbon::parse($u['from'])->toDateString(), 'to' => Carbon::parse($u['to'])->toDateString(), 'order' => (int) $u['order']] + (isset($u['source']) ? ['source' => (string) $u['source']] : []), $units);
        $byId = [];

        foreach ($units as $unit) {
            $byId[$unit['id']] = $unit;
        }

        // rules 1 and 2: a newer unit that contains (or is identical to) an older one shadows it, whatever the range. Phase 8h: a billing unit
        // carries a `source` ('upload' or 'derived'); against a unit of the OTHER source the newest does not win, the preference does: the
        // preferred source's unit shadows the other's whenever it covers its period (the same or a containing one). Two units of one source keep
        // the newest-wins rule, and a unit without a source (every other report) behaves exactly as before.
        $shadowed = [];
        $preferred = self::preferredSource();

        foreach ($units as $old) {
            $winner = null;

            foreach ($units as $new) {
                if ($new['from'] > $old['from'] || $new['to'] < $old['to'] || $new['id'] === $old['id']) {
                    continue;
                }

                $crossSource = isset($new['source'], $old['source']) && $new['source'] !== $old['source'];
                $beats = $crossSource ? $new['source'] === $preferred : $new['order'] > $old['order'];

                if ($beats && ($winner === null || ($crossSource && ! $winner['cross']) || ($crossSource === $winner['cross'] && $new['order'] > $winner['unit']['order']))) {
                    $winner = ['unit' => $new, 'cross' => $crossSource];
                }
            }

            if ($winner) {
                $shadowed[] = ['id' => $old['id'], 'by' => $winner['unit']['id'], 'reason' => $winner['cross'] ? 'source' : ($winner['unit']['from'] === $old['from'] && $winner['unit']['to'] === $old['to'] ? 'identical' : 'contained')];
            }
        }

        $shadowedIds = array_column($shadowed, 'id');
        $live = array_values(array_filter($units, fn (array $u) => ! in_array($u['id'], $shadowedIds, true)));

        // the range: asked for, or the latest uploaded period
        $latest = null;

        foreach ($live as $unit) {
            if ($latest === null || [$unit['to'], $unit['order']] > [$latest['to'], $latest['order']]) {
                $latest = $unit;
            }
        }

        $rangeFrom = $from !== null && $from !== '' ? Carbon::parse($from)->toDateString() : $latest['from'] ?? null;
        $rangeTo = $to !== null && $to !== '' ? Carbon::parse($to)->toDateString() : $latest['to'] ?? null;

        if ($rangeFrom !== null && $rangeTo !== null && $rangeFrom > $rangeTo) {
            [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
        }

        // rule 5: only units that lie INSIDE the range count; one that overlaps the range's edge is excluded and listed
        $inside = [];
        $partly = [];

        foreach ($live as $unit) {
            if ($rangeFrom === null || $rangeTo === null) {
                continue;
            }

            if ($unit['from'] >= $rangeFrom && $unit['to'] <= $rangeTo) {
                $inside[] = $unit;
            } elseif ($unit['to'] >= $rangeFrom && $unit['from'] <= $rangeTo) {
                $partly[] = $unit['id'];
            }
        }

        // rule 3: newest first, a unit that overlaps one already chosen is left out of THIS view (both stay stored)
        usort($inside, fn (array $a, array $b) => $b['order'] <=> $a['order']);
        $chosen = [];
        $overlaps = [];

        foreach ($inside as $unit) {
            $clash = null;

            foreach ($chosen as $kept) {
                if ($unit['from'] <= $kept['to'] && $unit['to'] >= $kept['from']) {
                    $clash = $kept;

                    break;
                }
            }

            if ($clash) {
                $overlaps[] = ['kept' => $clash['id'], 'dropped' => $unit['id']];

                continue;
            }

            $chosen[] = $unit;
        }

        usort($chosen, fn (array $a, array $b) => [$a['from'], $a['order']] <=> [$b['from'], $b['order']]);

        [$covered, $gaps, $coveredDays, $rangeDays] = $this->coverage($chosen, $rangeFrom, $rangeTo);

        return [
            'from' => $rangeFrom, 'to' => $rangeTo,
            'chosen' => array_column($chosen, 'id'),
            'shadowed' => $shadowed,
            'overlaps' => $overlaps,
            'partly_outside' => $partly,
            'covered' => $covered, 'gaps' => $gaps, 'covered_days' => $coveredDays, 'range_days' => $rangeDays,
            'units' => $byId,
        ];
    }

    /**
     * What an upload would do to the batches already stored (the preview's sentence).
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $existing
     * @return array{replaces: list<int|string>, shadows: list<int|string>, shadowed_by: int|string|null, overlaps: list<int|string>}
     */
    public function effectOfUpload(string $from, string $to, array $existing): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $out = ['replaces' => [], 'shadows' => [], 'shadowed_by' => null, 'overlaps' => []];

        foreach ($existing as $other) {
            $oFrom = Carbon::parse($other['from'])->toDateString();
            $oTo = Carbon::parse($other['to'])->toDateString();

            if ($oFrom === $from && $oTo === $to) {
                $out['replaces'][] = $other['id'];
            } elseif ($from <= $oFrom && $to >= $oTo) {
                $out['shadows'][] = $other['id'];
            } elseif ($oFrom <= $from && $oTo >= $to) {
                $out['shadowed_by'] = $other['id'];   // an existing batch already contains this period (and is newer than nothing: it stays the newer one only if it is)
            } elseif ($from <= $oTo && $to >= $oFrom) {
                $out['overlaps'][] = $other['id'];
            }
        }

        return $out;
    }

    /**
     * Days of the range the chosen units cover, as intervals, and the days they do not.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $chosen  ascending by from
     * @return array{0: list<array{0: string, 1: string}>, 1: list<array{0: string, 1: string}>, 2: int, 3: int}
     */
    protected function coverage(array $chosen, ?string $rangeFrom, ?string $rangeTo): array
    {
        if ($rangeFrom === null || $rangeTo === null) {
            return [[], [], 0, 0];
        }

        $rangeDays = (int) Carbon::parse($rangeFrom)->diffInDays(Carbon::parse($rangeTo)) + 1;
        $covered = [];

        foreach ($chosen as $unit) {
            $last = $covered === [] ? null : $covered[count($covered) - 1];

            if ($last && Carbon::parse($last[1])->addDay()->toDateString() >= $unit['from']) {
                $covered[count($covered) - 1][1] = max($last[1], $unit['to']);
            } else {
                $covered[] = [$unit['from'], $unit['to']];
            }
        }

        $gaps = [];
        $cursor = $rangeFrom;

        foreach ($covered as [$a, $b]) {
            if ($a > $cursor) {
                $gaps[] = [$cursor, Carbon::parse($a)->subDay()->toDateString()];
            }

            $cursor = Carbon::parse($b)->addDay()->toDateString();
        }

        if ($cursor <= $rangeTo) {
            $gaps[] = [$cursor, $rangeTo];
        }

        $coveredDays = array_sum(array_map(fn (array $i) => (int) Carbon::parse($i[0])->diffInDays(Carbon::parse($i[1])) + 1, $covered));

        return [$covered, $gaps, $coveredDays, $rangeDays];
    }

    /** "1 Jul to 31 Aug 2026 and 20 Sep 2026" for a list of day intervals. @param  list<array{0: string, 1: string}>  $intervals */
    public static function describeIntervals(array $intervals): string
    {
        return implode(' and ', array_map(fn (array $i) => self::describe($i[0], $i[1]), $intervals)) ?: 'none';
    }

    // ---------------------------------------------------------------- rule 4: flows add up, stocks take the latest

    /** Billing route columns that are FLOWS: they add up over distinct periods. */
    public const BILLING_FLOW_FIELDS = [
        'volume_actual', 'volume_average', 'volume_total', 'billing_for_period', 'revenue_adjustment',
        'payment_for_month', 'prev_month_payment', 'offset_payments', 'total_payments',
    ];

    /**
     * The routes of the chosen billing batches as ONE set of rows (unsaved models), one per (district, route). Flows are summed;
     * stocks (the closing balance and the customer counts: customers billed, unbilled and "number of customers") take the LATEST period
     * the route appears in; the opening balance is the EARLIEST one's (the balance the range started from); total receivable is then
     * recomputed as opening + billing + adjustments, never added up. With one batch the rows are that batch's, unchanged.
     *
     * @param  array<int|string, Collection<int, CommercialBillingRoute>>  $routesByBatch  chosen batch id => its routes
     * @param  list<int|string>  $chosenOrder  the chosen batch ids, earliest period first
     * @return Collection<int, CommercialBillingRoute>
     */
    public function combineBillingRoutes(array $routesByBatch, array $chosenOrder): Collection
    {
        if (count($chosenOrder) === 1) {
            return $routesByBatch[$chosenOrder[0]]->values();
        }

        /** @var array<string, CommercialBillingRoute> $combined */
        $combined = [];
        $first = [];

        foreach ($chosenOrder as $batchId) {
            foreach ($routesByBatch[$batchId] ?? [] as $route) {
                $key = ($route->district_id ?: 'raw:'.$route->district_label_raw).'|'.$route->route_code;

                if (! isset($combined[$key])) {
                    $combined[$key] = $route->replicate();
                    $combined[$key]->setRelation('district', $route->district);
                    $first[$key] = $route;

                    continue;
                }

                // a stock is the LATEST period's: replace everything, then re-add the flows
                $flows = [];

                foreach (self::BILLING_FLOW_FIELDS as $field) {
                    $flows[$field] = (float) $combined[$key]->{$field} + (float) $route->{$field};
                }

                $latest = $route->replicate();
                $latest->setRelation('district', $route->district);

                foreach ($flows as $field => $value) {
                    $latest->{$field} = round($value, 2);
                }

                $latest->opening_balance = $first[$key]->opening_balance;
                $combined[$key] = $latest;
            }
        }

        foreach ($combined as $route) {
            $route->total_receivable = round((float) $route->opening_balance + (float) $route->billing_for_period + (float) $route->revenue_adjustment, 2);
        }

        return collect(array_values($combined));
    }
}
