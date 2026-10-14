<?php

namespace App\Services\Commercial;

use App\Models\CommercialBillingRoute;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialDistrictTarget;
use App\Models\District;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The district KPI table the Commercial team reports against a target: SALES (volume billed, m3), BILLING and COLLECTION (GH¢) for
 * each month, the EFFICIENCY of each (actual / target x 100), and the ARREARS table (closing balance, its age in months).
 *
 * Everything comes from single-month billing snapshots (the newest non-voided batch of a region, segment and month), never from a
 * multi-month period and never summed across snapshots. A district's figure is the sum of its routes' figures.
 *   sales       = volume_total (thousand litres, which is 1 m3 per thousand)
 *   billing     = billing_for_period
 *   collection  = total_payments (this month's payments, earlier months' payments and offsets together)
 *   arrears     = what customers OWE (balances above zero). A negative balance is a CUSTOMER CREDIT (confirmed by the Commercial team,
 *                 2026-10-12), not arrears, so credits are never netted off: they are shown beside it. The figure is exact when a
 *                 Customer List upload exists for the district in that month (its debit and credit totals, per customer); otherwise it
 *                 is worked out from the billing report's routes, where a route's closing balance has already netted its customers'
 *                 credits, so it can be lower than the true arrears (the table says which source each month used).
 *   age         = arrears / billing of the same month, in months (a working definition: see AGE_NOTE).
 * Efficiency is null when a district has no target (or a zero one) for that month: it is never guessed.
 */
class CommercialKpiService
{
    public const METRICS = [
        'sales' => ['label' => 'Sales', 'unit' => 'm³', 'field' => 'volume_total', 'money' => false],
        'billing' => ['label' => 'Billing', 'unit' => 'GH¢', 'field' => 'billing_for_period', 'money' => true],
        'collection' => ['label' => 'Collection', 'unit' => 'GH¢', 'field' => 'total_payments', 'money' => true],
    ];

    public const AGE_NOTE = 'Age of arrears = arrears ÷ that month\'s billing, in months (a working definition: the Commercial team has not yet confirmed the formula).';

    public const ARREARS_NOTE = 'Arrears are what customers owe. A negative balance is a customer credit, not arrears, so credits are shown beside it and never netted off. "Customer list" means the exact customer-level figure from that month\'s customer-list upload; "Billing routes" means it was worked out from the billing report\'s routes, where a route\'s closing balance already nets its customers\' credits (so it can be lower than the true arrears).';

    // ---------------------------------------------------------------- the months

    /**
     * The single-month snapshots of one region and segment, oldest first, the last $limit of them.
     *
     * @param  list<array<string, mixed>>  $snapshots  BillingAnalyticsService::snapshots() output (already scoped to the viewer)
     * @return list<array<string, mixed>>
     */
    public function months(array $snapshots, ?int $regionId, ?string $segment, int $limit): array
    {
        $months = array_values(array_filter($snapshots, fn (array $s) => $s['is_single_month'] && $s['region_id'] === $regionId && $s['segment'] === $segment));
        usort($months, fn (array $a, array $b) => $a['month'] <=> $b['month']);

        return array_slice($months, -max(1, $limit));
    }

    // ---------------------------------------------------------------- the scorecard

    /**
     * @param  list<array<string, mixed>>  $months  from months()
     * @return array<string, mixed> ['months', 'districts' => [...], 'total' => ...]
     */
    public function scorecard(array $months): array
    {
        $labels = array_map(fn (array $m) => [
            'key' => substr($m['month'], 0, 7), 'month' => $m['month'], 'label' => Carbon::parse($m['month'])->format('F'), 'short' => strtoupper(Carbon::parse($m['month'])->format('M')),
            'year' => Carbon::parse($m['month'])->format('Y'), 'snapshot_id' => $m['id'],
        ], $months);

        $perDistrict = [];   // key => [name, id, month => sums]

        foreach ($months as $m) {
            $routes = CommercialBillingRoute::query()->where('batch_id', $m['id'])->with('district:id,district_name')->get();

            foreach ($routes->groupBy(fn ($route) => $route->district_id ?: 'raw:'.$route->district_label_raw) as $key => $group) {
                $first = $group->first();
                $perDistrict[$key]['id'] ??= $first->district_id ? (int) $first->district_id : null;
                $perDistrict[$key]['name'] ??= (string) ($first->district?->district_name ?: $first->district_label_raw ?: 'Unknown district');
                $perDistrict[$key]['months'][substr($m['month'], 0, 7)] = $this->sums($group);
            }
        }

        $ids = array_values(array_filter(array_column($perDistrict, 'id')));
        $perDistrict = $this->withCustomerBalances($perDistrict, $ids, $labels);
        $targets = $this->targetIndex($ids);
        $districts = [];

        foreach ($perDistrict as $key => $d) {
            $districts[] = $this->block((string) $d['name'], $d['id'], $d['months'], $labels, fn (string $metric, string $month) => $d['id'] ? $this->targetAt($targets, $d['id'], $metric, $month) : null);
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return ['months' => $labels, 'districts' => $districts, 'total' => $this->total($districts, $labels, $perDistrict, $targets)];
    }

    /** @param  Collection<int, CommercialBillingRoute>  $routes @return array<string, float> */
    protected function sums(Collection $routes): array
    {
        $out = ['sales' => 0.0, 'billing' => 0.0, 'collection' => 0.0, 'arrears' => 0.0, 'owed' => 0.0, 'credit' => 0.0, 'credit_routes' => 0, 'routes' => $routes->count(), 'source' => 'routes'];

        foreach ($routes as $route) {
            $out['sales'] += (float) $route->volume_total;
            $out['billing'] += (float) $route->billing_for_period;
            $out['collection'] += (float) $route->total_payments;
            $closing = (float) $route->closing_balance;

            if ($closing >= 0) {
                $out['owed'] += $closing;
            } else {
                $out['credit'] += -$closing;
                $out['credit_routes']++;
            }
        }

        $out['arrears'] = $out['owed'];   // what customers owe; credits are not arrears and are not netted off

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $out);
    }

    /**
     * One district's (or the total's) table: a row per KPI with the target, the actual of each month and the efficiency, and the
     * arrears table.
     *
     * @param  array<string, array<string, float|int>>  $monthly  month key => sums
     * @param  list<array<string, mixed>>  $labels
     * @param  \Closure(string, string): (float|null)  $targetOf  (metric, month Y-m-01) => target
     * @return array<string, mixed>
     */
    protected function block(string $name, ?int $id, array $monthly, array $labels, \Closure $targetOf): array
    {
        $rows = [];

        foreach (self::METRICS as $metric => $meta) {
            $actuals = [];
            $targets = [];
            $efficiency = [];

            foreach ($labels as $label) {
                $actual = isset($monthly[$label['key']]) ? (float) $monthly[$label['key']][$metric] : null;
                $target = $targetOf($metric, $label['month']);
                $actuals[$label['key']] = $actual;
                $targets[$label['key']] = $target;
                $efficiency[$label['key']] = $actual !== null && $target !== null && $target > 0 ? round($actual / $target * 100, 1) : null;
            }

            $latest = null;

            foreach (array_reverse($labels) as $label) {
                if ($targets[$label['key']] !== null) {
                    $latest = $targets[$label['key']];

                    break;
                }
            }

            $rows[] = [
                'metric' => $metric, 'label' => $meta['label'], 'unit' => $meta['unit'], 'money' => $meta['money'],
                'target' => $latest, 'targets' => $targets, 'target_changes' => count(array_unique(array_filter($targets, fn ($t) => $t !== null))) > 1,
                'actuals' => $actuals, 'efficiency' => $efficiency,
            ];
        }

        $arrears = [];
        $previous = null;

        foreach ($labels as $label) {
            $m = $monthly[$label['key']] ?? null;

            if (! $m) {
                continue;
            }

            $billing = (float) $m['billing'];
            $arrears[] = [
                'key' => $label['key'], 'label' => $label['label'], 'arrears' => (float) $m['arrears'], 'billing' => $billing,
                'age' => $billing > 0 && $m['arrears'] > 0 ? round($m['arrears'] / $billing, 1) : null,
                'owed' => (float) $m['owed'], 'credit' => (float) $m['credit'], 'credit_routes' => (int) $m['credit_routes'], 'net' => round((float) $m['owed'] - (float) $m['credit'], 2),
                'source' => (string) ($m['source'] ?? 'routes'),
                'change' => $previous === null ? null : round($m['arrears'] - $previous, 2),
                'change_pct' => $previous === null || $previous == 0 ? null : round(($m['arrears'] - $previous) / abs($previous) * 100, 1),
            ];
            $previous = (float) $m['arrears'];
        }

        // The arrears row of the KPI table (actuals only: arrears have no target).
        $rows[] = [
            'metric' => 'arrears', 'label' => 'Arrears', 'unit' => 'GH¢', 'money' => true, 'target' => null, 'targets' => [], 'target_changes' => false,
            'actuals' => array_combine(array_column($labels, 'key'), array_map(fn ($l) => isset($monthly[$l['key']]) ? (float) $monthly[$l['key']]['arrears'] : null, $labels)), 'efficiency' => [],
        ];

        return ['id' => $id, 'name' => $name, 'rows' => $rows, 'arrears' => $arrears];
    }

    /**
     * The whole region: actuals are the sum of every district (including routes with no district record); a target is the sum of the
     * districts' targets, and efficiency is shown only when EVERY district with figures has a target (a partial sum would flatter or
     * punish the region).
     *
     * @param  list<array<string, mixed>>  $districts
     */
    protected function total(array $districts, array $labels, array $perDistrict, array $targets): array
    {
        $monthly = [];

        foreach ($perDistrict as $d) {
            foreach ($d['months'] as $key => $sums) {
                foreach ($sums as $field => $value) {
                    if ($field === 'source') {
                        $monthly[$key]['source'] = ! isset($monthly[$key]['source']) || $monthly[$key]['source'] === $value ? $value : 'mixed';

                        continue;
                    }

                    $monthly[$key][$field] = ($monthly[$key][$field] ?? 0) + $value;
                }
            }
        }

        foreach ($monthly as $key => $sums) {
            foreach ($sums as $field => $value) {
                if (is_float($value)) {
                    $monthly[$key][$field] = round($value, 2);
                }
            }
        }

        $targetOf = function (string $metric, string $month) use ($perDistrict, $targets): ?float {
            $sum = 0.0;

            foreach ($perDistrict as $d) {
                if (! isset($d['months'][substr($month, 0, 7)])) {
                    continue;   // no figures this month: it needs no target
                }

                $target = $d['id'] ? $this->targetAt($targets, $d['id'], $metric, $month) : null;

                if ($target === null) {
                    return null;
                }

                $sum += $target;
            }

            return $sum > 0 ? round($sum, 2) : null;
        };

        $block = $this->block('All districts', null, $monthly, $labels, $targetOf);
        $block['complete'] = collect($block['rows'])->where('metric', '!=', 'arrears')->every(fn (array $row) => ! in_array(null, $row['targets'], true) || $row['targets'] === []);

        return $block;
    }

    /**
     * Where a Customer List upload exists for a district in a month, its exact customer-level owed (debit) and credit totals replace
     * the routes' figures for that month. The upload used is the district's LAST imported one inside the month (a month-end figure).
     *
     * @param  array<string|int, array<string, mixed>>  $perDistrict
     * @param  list<int>  $districtIds
     * @param  list<array<string, mixed>>  $labels
     * @return array<string|int, array<string, mixed>>
     */
    protected function withCustomerBalances(array $perDistrict, array $districtIds, array $labels): array
    {
        if ($districtIds === [] || $labels === [] || ! config('gwl.commercial_customer_list_enabled')) {
            return $perDistrict;
        }

        $from = Carbon::parse($labels[0]['month'])->startOfMonth()->toDateString();
        $to = Carbon::parse($labels[count($labels) - 1]['month'])->endOfMonth()->toDateString();

        $batches = CommercialCustomerBatch::query()->where('status', CommercialCustomerBatch::STATUS_IMPORTED)->whereIn('district_id', $districtIds)
            ->whereDate('as_of_date', '>=', $from)->whereDate('as_of_date', '<=', $to)->orderBy('as_of_date')->orderBy('id')->get(['id', 'district_id', 'as_of_date']);

        $chosen = [];   // district => month key => batch id (the last one wins)

        foreach ($batches as $batch) {
            $chosen[(int) $batch->district_id][$batch->as_of_date->format('Y-m')] = (int) $batch->id;
        }

        if ($chosen === []) {
            return $perDistrict;
        }

        $totals = DB::table('commercial_customer_rollups_district')->whereIn('batch_id', collect($chosen)->flatten()->all())
            ->selectRaw('batch_id, COALESCE(SUM(debit_sum), 0) AS owed, COALESCE(SUM(credit_sum), 0) AS credit')->groupBy('batch_id')->get()->keyBy('batch_id');

        foreach ($perDistrict as $key => $d) {
            foreach ($d['months'] as $month => $sums) {
                $batchId = $d['id'] ? ($chosen[$d['id']][$month] ?? null) : null;

                if ($batchId === null || ! isset($totals[$batchId])) {
                    continue;
                }

                $perDistrict[$key]['months'][$month]['owed'] = round(((int) $totals[$batchId]->owed) / 100, 2);
                $perDistrict[$key]['months'][$month]['credit'] = round(abs((int) $totals[$batchId]->credit) / 100, 2);
                $perDistrict[$key]['months'][$month]['arrears'] = $perDistrict[$key]['months'][$month]['owed'];
                $perDistrict[$key]['months'][$month]['source'] = 'customers';
            }
        }

        return $perDistrict;
    }

    // ---------------------------------------------------------------- targets

    /** @param  list<int>  $districtIds @return array<int, array<string, list<array{0: string, 1: float}>>> district => metric => [[from, value], ...] oldest first */
    protected function targetIndex(array $districtIds): array
    {
        $index = [];

        foreach (CommercialDistrictTarget::query()->whereIn('district_id', $districtIds ?: [0])->orderBy('effective_from')->get() as $target) {
            $index[(int) $target->district_id][$target->metric][] = [$target->effective_from->toDateString(), (float) $target->value];
        }

        return $index;
    }

    /** The target in force in a month: the latest one that starts on or before it. */
    protected function targetAt(array $index, int $districtId, string $metric, string $month): ?float
    {
        $found = null;

        foreach ($index[$districtId][$metric] ?? [] as [$from, $value]) {
            if ($from <= $month) {
                $found = $value;
            }
        }

        return $found;
    }

    /**
     * The targets in force on a date, for the editor: district id => metric => [value, effective_from].
     *
     * @param  list<int>  $districtIds
     * @return array<int, array<string, array{0: float, 1: string}>>
     */
    public function current(array $districtIds, ?string $asOf = null): array
    {
        $month = Carbon::parse($asOf ?? now())->startOfMonth()->toDateString();
        $out = [];

        foreach ($this->targetIndex($districtIds) as $districtId => $byMetric) {
            foreach ($byMetric as $metric => $rows) {
                foreach ($rows as [$from, $value]) {
                    if ($from <= $month) {
                        $out[$districtId][$metric] = [$value, $from];
                    }
                }
            }
        }

        return $out;
    }

    /** Every target row of one district, newest first. @return Collection<int, CommercialDistrictTarget> */
    public function history(int $districtId): Collection
    {
        return CommercialDistrictTarget::query()->where('district_id', $districtId)->orderByDesc('effective_from')->orderBy('metric')->get();
    }

    /**
     * Sets a district's target for a KPI from a month on (replacing the row of that month if there is one). Audited with the old and
     * new value. The caller has already checked the person may manage settings and may touch this district.
     *
     * @throws ValidationException
     */
    public function save(User $actor, int $districtId, string $metric, string $from, mixed $value): CommercialDistrictTarget
    {
        if (! isset(self::METRICS[$metric]) || ! District::query()->whereKey($districtId)->exists()) {
            throw ValidationException::withMessages(['metric' => 'Choose a district and one of the three KPIs.']);
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])/', $from)) {
            throw ValidationException::withMessages(['from' => 'Choose the month the target starts.']);
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1000000000000) {
            throw ValidationException::withMessages(['value' => 'The target must be a number of zero or more.']);
        }

        $start = Carbon::parse(substr($from, 0, 7).'-01')->toDateString();
        $existing = CommercialDistrictTarget::query()->where(['district_id' => $districtId, 'metric' => $metric, 'effective_from' => $start])->first();
        $target = CommercialDistrictTarget::query()->updateOrCreate(['district_id' => $districtId, 'metric' => $metric, 'effective_from' => $start], ['value' => round((float) $value, 2), 'updated_by' => $actor->id]);

        Audit::log('commercial.kpi_target_saved', Permission::MODULE_COMMERCIAL, CommercialDistrictTarget::class, $target->id, [
            'district_id' => $districtId, 'metric' => $metric, 'from' => $start, 'old' => $existing?->value, 'new' => (float) $target->value,
        ]);

        return $target;
    }

    public function delete(User $actor, int $targetId): void
    {
        $target = CommercialDistrictTarget::query()->findOrFail($targetId);
        $meta = ['district_id' => $target->district_id, 'metric' => $target->metric, 'from' => $target->effective_from->toDateString(), 'old' => (float) $target->value];
        $target->delete();

        Audit::log('commercial.kpi_target_deleted', Permission::MODULE_COMMERCIAL, CommercialDistrictTarget::class, $targetId, $meta);
    }
}
