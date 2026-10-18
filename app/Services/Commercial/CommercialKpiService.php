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
 * TARGETS ARE YEARLY. A district's target for a KPI is ONE figure for a calendar year (stored on 1 January of that year), spread evenly
 * over the twelve months, so a month's target is annual / 12. A year with no target row has no target: nothing carries over from an
 * earlier year, and efficiency is null (it is never guessed). Beside each month, the YEAR TO DATE compares what the months uploaded so
 * far this year add up to with the part of the annual target those months stand for (annual x months uploaded / 12), so a month that
 * was never uploaded does not count against the district; "of the annual target" is the same actual over the whole year's figure.
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
            $districts[] = $this->block(
                (string) $d['name'], $d['id'], $d['months'], $labels,
                fn (string $metric, string $month) => $d['id'] ? $this->targetAt($targets, $d['id'], $metric, $month) : null,
                fn (string $metric, int $year) => $d['id'] ? $this->annualAt($targets, $d['id'], $metric, $year) : null,
            );
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
     * @param  \Closure(string, string): (float|null)  $targetOf  (metric, month Y-m-01) => that month's target (annual / 12)
     * @param  \Closure(string, int): (float|null)  $annualOf  (metric, year) => the annual target
     * @return array<string, mixed>
     */
    protected function block(string $name, ?int $id, array $monthly, array $labels, \Closure $targetOf, \Closure $annualOf): array
    {
        $rows = [];
        $year = $labels === [] ? null : (int) $labels[count($labels) - 1]['year'];
        $yearKeys = array_values(array_filter(array_column($labels, 'key'), fn (string $key) => (int) substr($key, 0, 4) === $year && isset($monthly[$key])));

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

            $annual = $year === null ? null : $annualOf($metric, $year);
            $ytdActual = round(array_sum(array_map(fn (string $key) => (float) $monthly[$key][$metric], $yearKeys)), 2);
            $ytdTarget = $annual !== null && $annual > 0 && $yearKeys !== [] ? round($annual * count($yearKeys) / 12, 2) : null;

            $rows[] = [
                'metric' => $metric, 'label' => $meta['label'], 'unit' => $meta['unit'], 'money' => $meta['money'],
                'target' => $latest, 'targets' => $targets, 'target_changes' => count(array_unique(array_filter($targets, fn ($t) => $t !== null))) > 1,
                'actuals' => $actuals, 'efficiency' => $efficiency,
                'annual' => $annual, 'annual_year' => $year,
                'ytd' => [
                    'months' => count($yearKeys), 'actual' => $yearKeys === [] ? null : $ytdActual, 'target' => $ytdTarget,
                    'efficiency' => $ytdTarget !== null ? round($ytdActual / $ytdTarget * 100, 1) : null,
                    'progress' => $annual !== null && $annual > 0 && $yearKeys !== [] ? round($ytdActual / $annual * 100, 1) : null,
                ],
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
            'annual' => null, 'annual_year' => $year, 'ytd' => ['months' => count($yearKeys), 'actual' => null, 'target' => null, 'efficiency' => null, 'progress' => null],
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

        $annualOf = function (string $metric, int $year) use ($perDistrict, $targets): ?float {
            $sum = 0.0;
            $any = false;

            foreach ($perDistrict as $d) {
                if (! array_filter(array_keys($d['months']), fn (string $key) => (int) substr($key, 0, 4) === $year)) {
                    continue;   // no figures this year: it needs no target
                }

                $any = true;
                $target = $d['id'] ? $this->annualAt($targets, $d['id'], $metric, $year) : null;

                if ($target === null) {
                    return null;
                }

                $sum += $target;
            }

            return $any && $sum > 0 ? round($sum, 2) : null;
        };

        $block = $this->block('All districts', null, $monthly, $labels, $targetOf, $annualOf);
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

    // ---------------------------------------------------------------- charts

    /**
     * The numbers behind the KPI charts, taken from a scorecard (so a chart can never disagree with the table). $districtId narrows
     * the trend and arrears charts to one district; the district comparisons always list every district of the scorecard.
     *
     * @param  array<string, mixed>  $card  scorecard() output (months, districts, total)
     * @return array<string, mixed>
     */
    public function charts(array $card, ?int $districtId = null): array
    {
        $months = $card['months'];
        $labels = array_map(fn (array $m) => $m['short'].' '.substr($m['year'], 2), $months);
        $keys = array_column($months, 'key');
        $block = $districtId !== null ? (collect($card['districts'])->firstWhere('id', $districtId) ?? $card['total']) : $card['total'];
        $rows = collect($block['rows'])->keyBy('metric');
        $districts = $card['districts'];

        $trend = [];

        foreach (self::METRICS as $metric => $meta) {
            $row = $rows[$metric];
            $actual = array_map(fn (string $key) => $row['actuals'][$key] ?? null, $keys);
            $target = array_map(fn (string $key) => $row['targets'][$key] ?? null, $keys);
            $series = [['label' => 'Actual', 'data' => array_map(fn ($v) => $v ?? 0, $actual), 'color' => 'series-1']];

            if (array_filter($target, fn ($t) => $t !== null) !== []) {
                $series[] = ['label' => 'Target (annual ÷ 12)', 'data' => array_map(fn ($v) => $v ?? 0, $target), 'color' => 'series-3'];
            }

            $trend[$metric] = ['label' => $meta['label'], 'unit' => $meta['unit'], 'series' => $series];
        }

        $efficiency = [];

        foreach (self::METRICS as $metric => $meta) {
            $efficiency[$metric] = array_map(fn (array $d) => collect($d['rows'])->firstWhere('metric', $metric)['ytd']['efficiency'] ?? null, $districts);
        }

        $arrears = $block['arrears'];

        return [
            'labels' => $labels,
            'trend' => $trend,
            'districts' => array_column($districts, 'name'),
            // year to date efficiency of each district, by KPI (null = no target set)
            'ytd_efficiency' => $efficiency,
            'ytd_months' => $rows['billing']['ytd']['months'] ?? 0,
            'ytd_year' => $rows['billing']['annual_year'] ?? null,
            // share of the annual target reached so far
            'ytd_progress' => array_map(fn (string $metric) => array_map(fn (array $d) => collect($d['rows'])->firstWhere('metric', $metric)['ytd']['progress'] ?? null, $districts), array_keys(self::METRICS)),
            'arrears_labels' => array_column($arrears, 'label'),
            'arrears_owed' => array_column($arrears, 'arrears'),
            'arrears_credit' => array_column($arrears, 'credit'),
            'arrears_age' => array_map(fn ($a) => $a ?? 0, array_column($arrears, 'age')),
            'latest_arrears' => array_map(fn (array $d) => (float) (collect($d['arrears'])->last()['arrears'] ?? 0), $districts),
            'latest_billing' => array_map(fn (array $d) => (float) (collect($d['rows'])->firstWhere('metric', 'billing')['actuals'][end($keys) ?: ''] ?? 0), $districts),
            'scope' => $block['name'],
        ];
    }

    // ---------------------------------------------------------------- targets

    /** @param  list<int>  $districtIds @return array<int, array<string, array<int, float>>> district => metric => year => the ANNUAL target */
    protected function targetIndex(array $districtIds): array
    {
        $index = [];

        foreach (CommercialDistrictTarget::query()->whereIn('district_id', $districtIds ?: [0])->orderBy('effective_from')->get() as $target) {
            $index[(int) $target->district_id][$target->metric][(int) $target->effective_from->format('Y')] = (float) $target->value;
        }

        return $index;
    }

    /** The annual target of a year; null when that year has none (nothing carries over from another year). */
    protected function annualAt(array $index, int $districtId, string $metric, int $year): ?float
    {
        $value = $index[$districtId][$metric][$year] ?? null;

        return $value !== null && $value > 0 ? $value : null;
    }

    /** The target of one month: the annual target of its year spread evenly over twelve months. */
    protected function targetAt(array $index, int $districtId, string $metric, string $month): ?float
    {
        $annual = $this->annualAt($index, $districtId, $metric, (int) substr($month, 0, 4));

        return $annual === null ? null : round($annual / 12, 2);
    }

    /**
     * The annual targets of a year, for the editor: district id => metric => [value, year].
     *
     * @param  list<int>  $districtIds
     * @return array<int, array<string, array{0: float, 1: int}>>
     */
    public function current(array $districtIds, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');
        $out = [];

        foreach ($this->targetIndex($districtIds) as $districtId => $byMetric) {
            foreach ($byMetric as $metric => $years) {
                if (isset($years[$year])) {
                    $out[$districtId][$metric] = [$years[$year], $year];
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
     * Sets a district's ANNUAL target of a KPI for a calendar year (replacing that year's row if there is one). Audited with the old and
     * new value. The caller has already checked the person may manage settings and may touch this district.
     *
     * @throws ValidationException
     */
    public function save(User $actor, int $districtId, string $metric, int|string $year, mixed $value): CommercialDistrictTarget
    {
        if (! isset(self::METRICS[$metric]) || ! District::query()->whereKey($districtId)->exists()) {
            throw ValidationException::withMessages(['metric' => 'Choose a district and one of the three KPIs.']);
        }

        if (! preg_match('/^\d{4}$/', (string) $year) || (int) $year < 2000 || (int) $year > 2100) {
            throw ValidationException::withMessages(['year' => 'Choose the year the target is for.']);
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1000000000000) {
            throw ValidationException::withMessages(['value' => 'The target must be a number of zero or more.']);
        }

        $start = Carbon::create((int) $year, 1, 1)->toDateString();   // the year's key: 1 January
        $existing = CommercialDistrictTarget::query()->where(['district_id' => $districtId, 'metric' => $metric, 'effective_from' => $start])->first();
        $target = CommercialDistrictTarget::query()->updateOrCreate(['district_id' => $districtId, 'metric' => $metric, 'effective_from' => $start], ['value' => round((float) $value, 2), 'updated_by' => $actor->id]);

        Audit::log('commercial.kpi_target_saved', Permission::MODULE_COMMERCIAL, CommercialDistrictTarget::class, $target->id, [
            'district_id' => $districtId, 'metric' => $metric, 'year' => (int) $year, 'old' => $existing?->value, 'new' => (float) $target->value,
        ]);

        return $target;
    }

    public function delete(User $actor, int $targetId): void
    {
        $target = CommercialDistrictTarget::query()->findOrFail($targetId);
        $meta = ['district_id' => $target->district_id, 'metric' => $target->metric, 'year' => (int) $target->effective_from->format('Y'), 'old' => (float) $target->value];
        $target->delete();

        Audit::log('commercial.kpi_target_deleted', Permission::MODULE_COMMERCIAL, CommercialDistrictTarget::class, $targetId, $meta);
    }
}
