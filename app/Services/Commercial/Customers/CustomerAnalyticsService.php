<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialCustomerCategory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer-list analytics A to I. Every method reads the PRE-AGGREGATED tables (rollups, consumption, quality, connections,
 * debt stats) of the batches in a CustomerFilters, never commercial_customers, so a dashboard costs the same with five
 * thousand customers or five million: it reads a few thousand small rows.
 *
 * Money in the rollups is integer pesewas; it leaves this class as cedis (floats). Ratios are recomputed from sums and are
 * null on a zero denominator. The sign of a balance is NOT confirmed: positive is treated as owed (debit) and negative as a
 * credit, and every screen says "to be confirmed".
 */
class CustomerAnalyticsService
{
    public const BUCKET_LABELS = [
        0 => 'Credit balance', 1 => 'Nil balance', 2 => 'Up to 1 bill owed', 3 => '1 to 3 bills', 4 => '3 to 6 bills', 5 => '6 to 12 bills',
        6 => 'More than 12 bills', 7 => 'Owes, no usable last bill',
    ];

    public const QUALITY_LABELS = [
        'missing_mobile' => 'No mobile number', 'missing_email' => 'No e-mail', 'missing_address' => 'No address', 'missing_name' => 'No account name',
        'invalid_phone' => 'Mobile that is not a valid number', 'multiple_phones' => 'Two or more mobile numbers (not a fault)', 'reachable' => 'Reachable: at least one valid mobile number', 'future_date' => 'Date in the future', 'implausible_date' => 'Implausible or unreadable date',
        'unknown_category' => 'Category UNKNOWN', 'pending_category' => 'Category code not reviewed yet', 'unconfirmed_status' => 'Status code with an unconfirmed meaning',
        'shared_meter' => 'Meter number shared by several accounts', 'shared_mobile' => 'Mobile shared by several accounts', 'shared_email' => 'E-mail shared by several accounts',
        'not_in_file' => 'Not in the latest file',
    ];

    // ---------------------------------------------------------------- plumbing

    /** Only a route filter (or a per-route breakdown) needs the route-level rollups; everything else reads the district-level ones. */
    protected function fine(CustomerFilters $f): bool
    {
        return $f->routeId !== null;
    }

    /**
     * The rollups of the batches in view, with the three small lookups joined and every filter applied. The district-level
     * table by default (a few hundred rows per district); the route-level one only when a route is involved.
     */
    protected function base(CustomerFilters $f, bool $fine = false): Builder
    {
        $table = $fine ? 'commercial_customer_rollups' : 'commercial_customer_rollups_district';
        $query = DB::table($table.' AS r')->whereIn('r.batch_id', $f->batchIds === [] ? [0] : $f->batchIds);

        if ($f->regionRestriction !== null) {
            $f->regionRestriction === 0 ? $query->whereRaw('1 = 0') : $query->where('r.region_id', $f->regionRestriction);
        }

        $query->when($f->districtId, fn (Builder $q, int $id) => $q->where('r.district_id', $id));

        return $query->join('commercial_customer_categories AS k', 'k.id', '=', 'r.category_id')
            ->join('commercial_customer_statuses AS s', 's.id', '=', 'r.status_id')
            ->join('commercial_meter_statuses AS m', 'm.id', '=', 'r.meter_status_id')
            ->when($f->regionId, fn (Builder $q, int $id) => $q->where('r.region_id', $id))
            ->when($f->routeId, fn (Builder $q, int $id) => $q->where('r.route_id', $id))
            ->when($f->categoryId, fn (Builder $q, int $id) => $q->where('r.category_id', $id))
            ->when($f->group, fn (Builder $q, string $group) => $q->where('k.category_group', $group))
            ->when($f->statusId, fn (Builder $q, int $id) => $q->where('r.status_id', $id))
            ->when($f->meterStatusId, fn (Builder $q, int $id) => $q->where('r.meter_status_id', $id))
            ->when($f->billingOnly, fn (Builder $q) => $q->where('s.is_billing', true));
    }

    /** "SUM(r.x) AS x" for each column. @param  list<string>  $columns */
    protected function sums(array $columns): string
    {
        return implode(', ', array_map(fn (string $column) => "COALESCE(SUM(r.{$column}), 0) AS {$column}", $columns));
    }

    /** Percent of $part in $whole to one decimal, null when $whole is 0. */
    protected function pct(int|float $part, int|float $whole, int $digits = 1): ?float
    {
        return $whole == 0 ? null : round($part / $whole * 100, $digits);
    }

    protected function cedis(int|float|string|null $pesewas): float
    {
        return round(((float) $pesewas) / 100, 2);
    }

    /**
     * Sums of rollup columns grouped by a dimension.
     *
     * @param  list<string>  $columns
     * @return Collection<int, object>
     */
    protected function grouped(CustomerFilters $f, string $dimension, array $columns, string $extraSelect = ''): Collection
    {
        $query = $this->base($f, $dimension === 'route' || $this->fine($f));
        $extra = $extraSelect === '' ? '' : ', '.$extraSelect;

        $select = match ($dimension) {
            'group' => ['k.category_group AS dim_key, k.category_group AS dim_label', ['k.category_group']],
            'category' => ['k.id AS dim_key, k.code AS dim_code, k.name AS dim_name, k.is_unknown AS dim_unknown, k.category_group AS dim_group', ['k.id', 'k.code', 'k.name', 'k.is_unknown', 'k.category_group']],
            'status' => ['s.id AS dim_key, s.code AS dim_code, s.label AS dim_name, s.meaning_confirmed AS dim_confirmed, s.is_billing AS dim_billing, s.is_active AS dim_active', ['s.id', 's.code', 's.label', 's.meaning_confirmed', 's.is_billing', 's.is_active']],
            'meter' => ['m.id AS dim_key, m.code AS dim_code, m.label AS dim_name', ['m.id', 'm.code', 'm.label']],
            'district' => ['r.district_id AS dim_key, d.district_name AS dim_name, r.region_id AS dim_region', ['r.district_id', 'd.district_name', 'r.region_id']],
            'route' => ['r.route_id AS dim_key, rt.name AS dim_name, r.district_id AS dim_district', ['r.route_id', 'rt.name', 'r.district_id']],
            'region' => ['r.region_id AS dim_key, g.region_name AS dim_name', ['r.region_id', 'g.region_name']],
            default => ['1 AS dim_key', []],
        };

        if ($dimension === 'district') {
            $query->join('districts AS d', 'd.id', '=', 'r.district_id');
        } elseif ($dimension === 'route') {
            $query->join('commercial_routes AS rt', 'rt.id', '=', 'r.route_id');
        } elseif ($dimension === 'region') {
            $query->join('regions AS g', 'g.id', '=', 'r.region_id');
        }

        $query->selectRaw($select[0].', '.$this->sums($columns).$extra);

        foreach ($select[1] as $group) {
            $query->groupBy($group);
        }

        return $query->get();
    }

    protected function one(CustomerFilters $f, array $columns, string $extraSelect = ''): object
    {
        $query = $this->base($f, $this->fine($f))->selectRaw($this->sums($columns).($extraSelect === '' ? '' : ', '.$extraSelect));

        return $query->first() ?? (object) array_fill_keys($columns, 0);
    }

    // ---------------------------------------------------------------- A. customer base

    /** @return array<string, mixed> */
    public function overview(CustomerFilters $f): array
    {
        $total = (int) $this->one($f, ['customer_count'])->customer_count;
        $statuses = $this->grouped($f, 'status', ['customer_count']);

        $billing = (int) $statuses->where('dim_billing', 1)->sum('customer_count');
        $activeNonBilling = (int) $statuses->filter(fn ($s) => $s->dim_active && ! $s->dim_billing)->sum('customer_count');
        $disconnected = (int) $statuses->where('dim_code', 'DISC')->sum('customer_count');
        $suspended = (int) $statuses->where('dim_code', 'SUSP')->sum('customer_count');
        $other = $total - $billing - $activeNonBilling - $disconnected - $suspended;

        $share = fn (Collection $rows, callable $label) => $rows->map(fn ($row) => ['key' => $row->dim_key, 'label' => $label($row), 'customers' => (int) $row->customer_count, 'share' => $this->pct((int) $row->customer_count, $total)])->sortByDesc('customers')->values()->all();

        $districts = $this->grouped($f, 'district', ['customer_count']);
        // Route statistics come from the route-level rollups, which are read only for one district (or a route filter): a
        // company-wide screen takes the route counts the batches already carry instead of scanning every route.
        $routes = $f->districtId || $f->routeId ? $this->grouped($f, 'route', ['customer_count']) : collect();

        $batches = CommercialCustomerBatch::query()->whereIn('id', $f->batchIds ?: [0])->get(['id', 'district_id', 'rows_missing', 'rows_moved', 'rows_new', 'as_of_date', 'period_key', 'routes_count']);
        $routeCount = $routes->isNotEmpty() ? $routes->count() : (int) $batches->sum('routes_count');

        return [
            'total' => $total,
            'billing' => $billing,
            'active_non_billing' => $activeNonBilling,
            'disconnected' => $disconnected,
            'suspended' => $suspended,
            'other_status' => max(0, $other),
            'billing_share' => $this->pct($billing, $total),
            'active_share' => $this->pct($billing + $activeNonBilling, $total),
            'by_group' => $share($this->grouped($f, 'group', ['customer_count']), fn ($r) => CommercialCustomerCategory::groupLabel($r->dim_label)),
            'by_category' => $share($this->grouped($f, 'category', ['customer_count']), fn ($r) => $r->dim_unknown ? 'UNKNOWN' : trim($r->dim_code.' '.$r->dim_name)),
            'by_status' => $share($statuses, fn ($r) => $r->dim_confirmed ? $r->dim_name.' ('.$r->dim_code.')' : $r->dim_code),
            'by_meter' => $share($this->grouped($f, 'meter', ['customer_count']), fn ($r) => $r->dim_name),
            'by_district' => $districts->map(fn ($r) => ['key' => (int) $r->dim_key, 'label' => $r->dim_name, 'customers' => (int) $r->customer_count, 'routes' => (int) $batches->firstWhere('district_id', (int) $r->dim_key)?->routes_count, 'share' => $this->pct((int) $r->customer_count, $total), 'per_route' => ($n = (int) $batches->firstWhere('district_id', (int) $r->dim_key)?->routes_count) > 0 ? (int) round($r->customer_count / $n) : null])->sortByDesc('customers')->values()->all(),
            'top_routes' => $routes->map(fn ($r) => ['key' => (int) $r->dim_key, 'label' => $r->dim_name, 'district_id' => (int) $r->dim_district, 'customers' => (int) $r->customer_count])->sortByDesc('customers')->take(25)->values()->all(),
            'route_count' => $routeCount,
            'per_route_average' => $routeCount > 0 ? (int) round($total / $routeCount) : null,
            'not_in_file' => (int) $batches->sum('rows_missing'),
            'moved' => (int) $batches->sum('rows_moved'),
            'new' => (int) $batches->sum('rows_new'),
            'batches' => $batches->count(),
        ];
    }

    // ---------------------------------------------------------------- B. meter health

    /**
     * Meter status by a dimension ('district', 'route' or 'category'), faulty and no-meter customers still billing, how much
     * of the billing base is on estimates and how long faulty-meter customers have gone without a read.
     *
     * @return array<string, mixed>
     */
    public function meters(CustomerFilters $f, string $by = 'district'): array
    {
        $by = in_array($by, ['district', 'route', 'category'], true) ? $by : 'district';
        $cases = "COALESCE(SUM(CASE WHEN m.code = 'W' THEN r.customer_count ELSE 0 END), 0) AS working, "
            ."COALESCE(SUM(CASE WHEN m.code = 'F' THEN r.customer_count ELSE 0 END), 0) AS faulty, "
            ."COALESCE(SUM(CASE WHEN m.code = 'N' THEN r.customer_count ELSE 0 END), 0) AS no_meter, "
            ."COALESCE(SUM(CASE WHEN m.code NOT IN ('W','F','N') THEN r.customer_count ELSE 0 END), 0) AS other_meter, "
            ."COALESCE(SUM(CASE WHEN m.code = 'F' AND s.is_billing = 1 THEN r.customer_count ELSE 0 END), 0) AS faulty_billing, "
            ."COALESCE(SUM(CASE WHEN m.code = 'N' AND s.is_billing = 1 THEN r.customer_count ELSE 0 END), 0) AS no_meter_billing, "
            ."COALESCE(SUM(CASE WHEN s.is_billing = 1 THEN r.customer_count ELSE 0 END), 0) AS billing_customers, "
            ."COALESCE(SUM(CASE WHEN s.is_billing = 1 THEN r.est_count ELSE 0 END), 0) AS billing_on_estimate";

        $rows = $this->grouped($f, $by, ['customer_count'], $cases);
        $label = fn ($r) => $by === 'category' ? ($r->dim_unknown ? 'UNKNOWN' : trim($r->dim_code.' '.$r->dim_name)) : $r->dim_name;

        $table = $rows->map(fn ($r) => [
            'key' => $r->dim_key, 'label' => $label($r), 'customers' => (int) $r->customer_count,
            'working' => (int) $r->working, 'faulty' => (int) $r->faulty, 'no_meter' => (int) $r->no_meter, 'other' => (int) $r->other_meter,
            'working_pct' => $this->pct((int) $r->working, (int) $r->customer_count), 'faulty_pct' => $this->pct((int) $r->faulty, (int) $r->customer_count), 'no_meter_pct' => $this->pct((int) $r->no_meter, (int) $r->customer_count),
            'faulty_billing' => (int) $r->faulty_billing, 'no_meter_billing' => (int) $r->no_meter_billing,
            'on_estimate_pct' => $this->pct((int) $r->billing_on_estimate, (int) $r->billing_customers),
        ])->sortByDesc('customers')->values()->all();

        $sum = fn (string $key) => array_sum(array_column($table, $key));
        $customers = (int) $sum('customers');

        $ageing = $this->base($f, $this->fine($f))->where('m.code', 'F')
            ->selectRaw($this->sums(['customer_count', 'no_read_30', 'no_read_60', 'no_read_90', 'no_read_180', 'never_read']))->first();

        return [
            'by' => $by,
            'rows' => $table,
            'totals' => [
                'customers' => $customers, 'working' => $sum('working'), 'faulty' => $sum('faulty'), 'no_meter' => $sum('no_meter'),
                'working_pct' => $this->pct($sum('working'), $customers), 'faulty_pct' => $this->pct($sum('faulty'), $customers), 'no_meter_pct' => $this->pct($sum('no_meter'), $customers),
                'faulty_billing' => $sum('faulty_billing'), 'no_meter_billing' => $sum('no_meter_billing'),
            ],
            'on_estimate_pct' => $this->pct((int) $rows->sum('billing_on_estimate'), (int) $rows->sum('billing_customers')),
            'faulty_ageing' => [
                'faulty' => (int) ($ageing->customer_count ?? 0),
                'bands' => [
                    ['label' => 'No read in 30+ days', 'customers' => (int) ($ageing->no_read_30 ?? 0)],
                    ['label' => 'No read in 60+ days', 'customers' => (int) ($ageing->no_read_60 ?? 0)],
                    ['label' => 'No read in 90+ days', 'customers' => (int) ($ageing->no_read_90 ?? 0)],
                    ['label' => 'No read in 180+ days', 'customers' => (int) ($ageing->no_read_180 ?? 0)],
                    ['label' => 'Never read', 'customers' => (int) ($ageing->never_read ?? 0)],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------- C. receivables

    /** @return array<string, mixed> */
    public function receivables(CustomerFilters $f): array
    {
        $t = $this->one($f, ['customer_count', 'balance_sum', 'debit_sum', 'debit_count', 'credit_sum', 'credit_count', 'bucket_0', 'bucket_1', 'bucket_2', 'bucket_3', 'bucket_4', 'bucket_5', 'bucket_6', 'bucket_7']);
        $customers = (int) $t->customer_count;

        $buckets = [];

        foreach (self::BUCKET_LABELS as $bucket => $label) {
            $buckets[] = ['bucket' => $bucket, 'label' => $label, 'customers' => (int) $t->{'bucket_'.$bucket}, 'share' => $this->pct((int) $t->{'bucket_'.$bucket}, $customers)];
        }

        $byStatus = $this->grouped($f, 'status', ['customer_count', 'debit_sum', 'debit_count']);
        $byCategory = $this->grouped($f, 'category', ['customer_count', 'debit_sum', 'debit_count']);
        $debtDistricts = $this->grouped($f, 'district', ['customer_count', 'debit_sum', 'debit_count', 'credit_sum', 'credit_count']);

        $stats = DB::table('commercial_customer_debt_stats')->whereIn('batch_id', $f->batchIds ?: [0])
            ->when($f->districtId, fn ($q, $id) => $q->where('district_id', $id))
            ->get()->keyBy('district_id');

        $totalDebit = (int) $stats->sum('total_debit');
        $debtors = (int) $stats->sum('debtors');

        return [
            'customers' => $customers,
            'debit' => $this->cedis($t->debit_sum), 'debit_count' => (int) $t->debit_count,
            'credit' => $this->cedis($t->credit_sum), 'credit_count' => (int) $t->credit_count,
            'net' => $this->cedis($t->balance_sum),
            'average_balance' => $customers > 0 ? round($this->cedis($t->balance_sum) / $customers, 2) : null,
            'average_debit' => (int) $t->debit_count > 0 ? round($this->cedis($t->debit_sum) / (int) $t->debit_count, 2) : null,
            'buckets' => $buckets,
            'by_status' => $byStatus->map(fn ($r) => ['label' => $r->dim_confirmed ? $r->dim_name.' ('.$r->dim_code.')' : $r->dim_code, 'code' => $r->dim_code, 'customers' => (int) $r->customer_count, 'debtors' => (int) $r->debit_count, 'debit' => $this->cedis($r->debit_sum), 'debit_share' => $this->pct((int) $r->debit_sum, (int) $t->debit_sum)])->sortByDesc('debit')->values()->all(),
            'owing_after_leaving' => (function () use ($byStatus): array {
                $left = $byStatus->filter(fn ($r) => in_array($r->dim_code, ['DISC', 'SUSP'], true));

                return ['debtors' => (int) $left->sum('debit_count'), 'debit' => $this->cedis($left->sum('debit_sum'))];
            })(),
            'by_category' => $byCategory->map(fn ($r) => ['label' => $r->dim_unknown ? 'UNKNOWN' : trim($r->dim_code.' '.$r->dim_name), 'customers' => (int) $r->customer_count, 'debtors' => (int) $r->debit_count, 'debit' => $this->cedis($r->debit_sum), 'average_debit' => (int) $r->debit_count > 0 ? round($this->cedis($r->debit_sum) / (int) $r->debit_count, 2) : null])->sortByDesc('debit')->values()->all(),
            'by_district' => $debtDistricts->map(function ($r) use ($stats) {
                $s = $stats->get((int) $r->dim_key);

                return [
                    'key' => (int) $r->dim_key, 'label' => $r->dim_name, 'customers' => (int) $r->customer_count, 'debtors' => (int) $r->debit_count,
                    'debit' => $this->cedis($r->debit_sum), 'credit' => $this->cedis($r->credit_sum),
                    'median_balance' => $s && $s->median_balance !== null ? $this->cedis($s->median_balance) : null,
                    'median_debit' => $s && $s->median_debit !== null ? $this->cedis($s->median_debit) : null,
                ];
            })->sortByDesc('debit')->values()->all(),
            'concentration' => [
                'debtors' => $debtors,
                'top1_share' => $this->pct((int) $stats->sum('top1_sum'), $totalDebit),
                'top5_share' => $this->pct((int) $stats->sum('top5_sum'), $totalDebit),
                'top10_share' => $this->pct((int) $stats->sum('top10_sum'), $totalDebit),
            ],
            'median_balance' => $stats->count() === 1 && $stats->first()->median_balance !== null ? $this->cedis($stats->first()->median_balance) : null,
            'median_debit' => $stats->count() === 1 && $stats->first()->median_debit !== null ? $this->cedis($stats->first()->median_debit) : null,
        ];
    }

    // ---------------------------------------------------------------- D. billing and collection behaviour

    /** @return array<string, mixed> */
    public function collection(CustomerFilters $f, string $by = 'category'): array
    {
        $by = in_array($by, ['category', 'route', 'district'], true) ? $by : 'category';
        $columns = ['customer_count', 'billed_sum', 'billed_count', 'paid_sum', 'zero_bill_count', 'billed_unpaid_30', 'billed_unpaid_60', 'billed_unpaid_90', 'billed_unpaid_180'];
        $t = $this->one($f, $columns);
        $rows = $this->grouped($f, $by, $columns);
        $label = fn ($r) => $by === 'category' ? ($r->dim_unknown ? 'UNKNOWN' : trim($r->dim_code.' '.$r->dim_name)) : $r->dim_name;

        $table = $rows->map(fn ($r) => [
            'key' => $r->dim_key, 'label' => $label($r), 'customers' => (int) $r->customer_count,
            'billed' => $this->cedis($r->billed_sum), 'paid' => $this->cedis($r->paid_sum),
            'paid_to_billed' => $this->pct((int) $r->paid_sum, (int) $r->billed_sum),
            'average_bill' => (int) $r->billed_count > 0 ? round($this->cedis($r->billed_sum) / (int) $r->billed_count, 2) : null,
            'unpaid_90' => (int) $r->billed_unpaid_90, 'zero_bill' => (int) $r->zero_bill_count,
        ])->sortByDesc('billed')->values()->all();

        return [
            'by' => $by,
            'customers' => (int) $t->customer_count,
            'billed' => $this->cedis($t->billed_sum), 'paid' => $this->cedis($t->paid_sum),
            'paid_to_billed' => $this->pct((int) $t->paid_sum, (int) $t->billed_sum),
            'billed_customers' => (int) $t->billed_count,
            'average_bill' => (int) $t->billed_count > 0 ? round($this->cedis($t->billed_sum) / (int) $t->billed_count, 2) : null,
            'zero_bill_active' => (int) $t->zero_bill_count,
            'unpaid' => array_map(fn (int $days) => ['days' => $days, 'customers' => (int) $t->{'billed_unpaid_'.$days}, 'share' => $this->pct((int) $t->{'billed_unpaid_'.$days}, (int) $t->billed_count)], [30, 60, 90, 180]),
            'rows' => $table,
        ];
    }

    // ---------------------------------------------------------------- E. activity and dormancy

    /** @return array<string, mixed> */
    public function dormancy(CustomerFilters $f): array
    {
        $columns = ['customer_count', 'never_read', 'never_billed', 'never_paid', 'ghost_count'];

        foreach ([30, 60, 90, 180] as $days) {
            array_push($columns, "no_read_{$days}", "no_bill_{$days}", "no_pay_{$days}");
        }

        $t = $this->one($f, $columns);
        $customers = (int) $t->customer_count;
        $windows = [];

        foreach ([30, 60, 90, 180] as $days) {
            $windows[] = [
                'days' => $days,
                'no_read' => (int) $t->{"no_read_{$days}"}, 'no_read_pct' => $this->pct((int) $t->{"no_read_{$days}"}, $customers),
                'no_bill' => (int) $t->{"no_bill_{$days}"}, 'no_bill_pct' => $this->pct((int) $t->{"no_bill_{$days}"}, $customers),
                'no_pay' => (int) $t->{"no_pay_{$days}"}, 'no_pay_pct' => $this->pct((int) $t->{"no_pay_{$days}"}, $customers),
            ];
        }

        $byDistrict = $this->grouped($f, 'district', ['customer_count', 'ghost_count', 'never_read', 'no_read_90', 'no_pay_90']);

        return [
            'customers' => $customers,
            'windows' => $windows,
            'never_read' => (int) $t->never_read, 'never_billed' => (int) $t->never_billed, 'never_paid' => (int) $t->never_paid,
            'ghost' => (int) $t->ghost_count, 'ghost_pct' => $this->pct((int) $t->ghost_count, $customers),
            'by_district' => $byDistrict->map(fn ($r) => ['key' => (int) $r->dim_key, 'label' => $r->dim_name, 'customers' => (int) $r->customer_count, 'ghost' => (int) $r->ghost_count, 'never_read' => (int) $r->never_read, 'no_read_90' => (int) $r->no_read_90, 'no_pay_90' => (int) $r->no_pay_90])->sortByDesc('ghost')->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- F. growth and churn

    /** @return array<string, mixed> */
    public function growth(CustomerFilters $f): array
    {
        $months = DB::table('commercial_customer_connections AS c')->whereIn('c.batch_id', $f->batchIds ?: [0])
            ->when($f->districtId, fn ($q, $id) => $q->where('c.district_id', $id))
            ->when($f->categoryId, fn ($q, $id) => $q->where('c.category_id', $id))
            ->when($f->regionRestriction === 0, fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('c.connect_month, SUM(c.customers) AS customers, SUM(c.billing_customers) AS billing')
            ->groupBy('c.connect_month')->orderBy('c.connect_month')->get();

        $byCategory = DB::table('commercial_customer_connections AS c')->join('commercial_customer_categories AS k', 'k.id', '=', 'c.category_id')
            ->whereIn('c.batch_id', $f->batchIds ?: [0])->when($f->districtId, fn ($q, $id) => $q->where('c.district_id', $id))
            ->selectRaw('k.category_group, SUM(c.customers) AS customers')->groupBy('k.category_group')->orderByDesc('customers')->get();

        $matrix = DB::table('commercial_customer_changes AS ch')
            ->join('commercial_customer_batches AS b', 'b.id', '=', 'ch.batch_id')
            ->join('commercial_customer_statuses AS o', 'o.id', '=', 'ch.old_value')
            ->join('commercial_customer_statuses AS n', 'n.id', '=', 'ch.new_value')
            ->whereIn('ch.batch_id', $f->batchIds ?: [0])->where('ch.field', CustomerMergeService::FIELD_STATUS)
            ->when($f->regionRestriction !== null, fn ($q) => $f->regionRestriction === 0 ? $q->whereRaw('1 = 0') : $q->where('b.region_id', $f->regionRestriction))
            ->when($f->districtId, fn ($q, $id) => $q->where('b.district_id', $id))
            ->selectRaw('o.code AS from_code, o.label AS from_label, o.meaning_confirmed AS from_ok, n.code AS to_code, n.label AS to_label, n.meaning_confirmed AS to_ok, COUNT(*) AS customers')
            ->groupBy('o.code', 'o.label', 'o.meaning_confirmed', 'n.code', 'n.label', 'n.meaning_confirmed')->orderByDesc('customers')->get();

        $batches = CommercialCustomerBatch::query()->whereIn('id', $f->batchIds ?: [0])->get();
        $totals = $this->one($f, ['customer_count']);
        $name = fn (string $code, string $label, $ok) => $ok ? $label.' ('.$code.')' : $code;

        $reconnected = (int) $matrix->filter(fn ($r) => $r->from_code === 'DISC' && $r->to_code === 'ACTB')->sum('customers');

        return [
            'customers' => (int) $totals->customer_count,
            'new' => (int) $batches->sum('rows_new'),
            'missing' => (int) $batches->sum('rows_missing'),
            'moved' => (int) $batches->sum('rows_moved'),
            'net' => (int) $batches->sum('rows_new') - (int) $batches->sum('rows_missing'),
            'reconnections' => $reconnected,
            'connections_by_month' => $months->map(fn ($r) => ['month' => $r->connect_month, 'customers' => (int) $r->customers, 'billing' => (int) $r->billing])->all(),
            'connections_by_group' => $byCategory->map(fn ($r) => ['label' => CommercialCustomerCategory::groupLabel($r->category_group), 'customers' => (int) $r->customers])->all(),
            'migration' => $matrix->map(fn ($r) => ['from' => $name($r->from_code, $r->from_label, $r->from_ok), 'to' => $name($r->to_code, $r->to_label, $r->to_ok), 'from_code' => $r->from_code, 'to_code' => $r->to_code, 'customers' => (int) $r->customers])->all(),
        ];
    }

    // ---------------------------------------------------------------- G. consumption

    /** @return array<string, mixed> */
    public function consumption(CustomerFilters $f): array
    {
        $query = DB::table('commercial_customer_consumption AS c')->join('commercial_customer_categories AS k', 'k.id', '=', 'c.category_id')
            ->join('commercial_customer_batches AS b', 'b.id', '=', 'c.batch_id')
            ->whereIn('c.batch_id', $f->batchIds ?: [0])
            ->when($f->regionRestriction !== null, fn ($q) => $f->regionRestriction === 0 ? $q->whereRaw('1 = 0') : $q->where('b.region_id', $f->regionRestriction))
            ->when($f->districtId, fn ($q, $id) => $q->where('c.district_id', $id))
            ->when($f->categoryId, fn ($q, $id) => $q->where('c.category_id', $id));

        $rows = (clone $query)->selectRaw('k.id, k.code, k.name, k.is_unknown, SUM(c.customers) AS customers, SUM(c.outliers) AS outliers, SUM(c.zero_consume_billing) AS zero_consume, SUM(c.factor_not_one) AS factor_not_one, SUM(c.factor_odd) AS factor_odd, '
            .'SUM(c.median_average * c.customers) AS median_weighted, SUM(CASE WHEN c.median_average IS NOT NULL THEN c.customers ELSE 0 END) AS median_weight, '
            .'SUM(c.bin_0) AS bin_0, SUM(c.bin_1) AS bin_1, SUM(c.bin_2) AS bin_2, SUM(c.bin_3) AS bin_3, SUM(c.bin_4) AS bin_4, SUM(c.bin_5) AS bin_5')
            ->groupBy('k.id', 'k.code', 'k.name', 'k.is_unknown')->get();

        $edges = CustomerRollupService::BIN_EDGES;
        $binLabels = ['0', "0 to {$edges[0]}", "{$edges[0]} to {$edges[1]}", "{$edges[1]} to {$edges[2]}", "{$edges[2]} to {$edges[3]}", "over {$edges[3]}"];

        $distribution = [];

        foreach ($binLabels as $i => $label) {
            $distribution[] = ['label' => $label, 'customers' => (int) $rows->sum('bin_'.$i)];
        }

        return [
            'rows' => $rows->map(fn ($r) => [
                'label' => $r->is_unknown ? 'UNKNOWN' : trim($r->code.' '.$r->name), 'customers' => (int) $r->customers,
                'typical' => (int) $r->median_weight > 0 ? round((float) $r->median_weighted / (int) $r->median_weight, 2) : null,
                'outliers' => (int) $r->outliers, 'outlier_pct' => $this->pct((int) $r->outliers, (int) $r->customers),
                'zero_consume' => (int) $r->zero_consume, 'factor_not_one' => (int) $r->factor_not_one, 'factor_odd' => (int) $r->factor_odd,
            ])->sortByDesc('customers')->values()->all(),
            'distribution' => $distribution,
            'customers' => (int) $rows->sum('customers'),
            'outliers' => (int) $rows->sum('outliers'),
            'zero_consume' => (int) $rows->sum('zero_consume'),
            'factor_not_one' => (int) $rows->sum('factor_not_one'),
            'factor_odd' => (int) $rows->sum('factor_odd'),
            'multiple' => (float) config('gwl.commercial_customer_outlier_multiple', 5),
        ];
    }

    // ---------------------------------------------------------------- H. data quality

    /** @return array<string, mixed> */
    public function quality(CustomerFilters $f): array
    {
        $rows = DB::table('commercial_customer_quality AS q')->join('commercial_customer_batches AS b', 'b.id', '=', 'q.batch_id')
            ->whereIn('q.batch_id', $f->batchIds ?: [0])
            ->when($f->regionRestriction !== null, fn ($q) => $f->regionRestriction === 0 ? $q->whereRaw('1 = 0') : $q->where('b.region_id', $f->regionRestriction))
            ->when($f->districtId, fn ($q, $id) => $q->where('q.district_id', $id))
            ->selectRaw('q.district_id, q.issue, SUM(q.issue_count) AS issue_count')->groupBy('q.district_id', 'q.issue')->get();

        $customers = (int) $this->one($f, ['customer_count'])->customer_count;
        $byDistrictCustomers = $this->grouped($f, 'district', ['customer_count'])->keyBy('dim_key');
        $byIssue = $rows->groupBy('issue')->map(fn ($group) => (int) $group->sum('issue_count'));

        $issues = [];

        foreach (self::QUALITY_LABELS as $issue => $label) {
            $count = (int) ($byIssue[$issue] ?? 0);
            $issues[] = ['issue' => $issue, 'label' => $label, 'count' => $count, 'share' => $this->pct($count, $customers)];
        }

        $score = function (int $districtId) use ($rows, $byDistrictCustomers): ?float {
            $n = (int) ($byDistrictCustomers[$districtId]->customer_count ?? 0);

            if ($n === 0) {
                return null;
            }

            $get = fn (string $issue) => (int) $rows->where('district_id', $districtId)->where('issue', $issue)->sum('issue_count');

            return round(max(0, 100 - ($get('missing_mobile') + $get('missing_address') + $get('missing_name') + $get('invalid_phone')) / (4 * $n) * 100), 1);
        };

        $districts = $byDistrictCustomers->map(fn ($r) => [
            'key' => (int) $r->dim_key, 'label' => $r->dim_name, 'customers' => (int) $r->customer_count, 'completeness' => $score((int) $r->dim_key),
            'issues' => $rows->where('district_id', (int) $r->dim_key)->filter(fn ($i) => in_array($i->issue, ['missing_mobile', 'missing_email', 'missing_address', 'invalid_phone', 'multiple_phones', 'reachable', 'shared_meter', 'shared_mobile', 'unknown_category'], true))->pluck('issue_count', 'issue')->map(fn ($c) => (int) $c)->all(),
        ])->sortBy('completeness')->values()->all();

        return ['customers' => $customers, 'issues' => $issues, 'districts' => $districts];
    }

    // ---------------------------------------------------------------- I. trends and comparison

    /**
     * KPIs per period for the effective batches of the viewer's scope (a district's batches are summed with the other districts'
     * batches of the same period), newest last, plus the change against the period before.
     *
     * @return array<string, mixed>
     */
    public function trend(?int $restriction, ?int $regionId, ?int $districtId, int $periods = 12, bool $monthEnd = false): array
    {
        if ($monthEnd) {
            return $this->trendByMonthEnd($restriction, $regionId, $districtId, $periods);
        }

        $rows = DB::table('commercial_customer_rollups_district AS r')
            ->join('commercial_customer_batches AS b', 'b.id', '=', 'r.batch_id')
            ->join('commercial_customer_statuses AS s', 's.id', '=', 'r.status_id')
            ->join('commercial_meter_statuses AS m', 'm.id', '=', 'r.meter_status_id')
            ->where('b.status', CommercialCustomerBatch::STATUS_IMPORTED)
            ->when($restriction !== null, fn ($q) => $restriction === 0 ? $q->whereRaw('1 = 0') : $q->where('b.region_id', $restriction))
            ->when($regionId, fn ($q, $id) => $q->where('b.region_id', $id))
            ->when($districtId, fn ($q, $id) => $q->where('b.district_id', $id))
            ->selectRaw('b.period_key, b.period_type, MAX(b.as_of_date) AS as_of, COUNT(DISTINCT b.id) AS batches, SUM(r.customer_count) AS customers, '
                .'SUM(CASE WHEN s.is_billing = 1 THEN r.customer_count ELSE 0 END) AS billing, SUM(r.debit_sum) AS debit_sum, SUM(r.credit_sum) AS credit_sum, '
                ."SUM(CASE WHEN m.code = 'F' THEN r.customer_count ELSE 0 END) AS faulty, SUM(r.no_pay_90) AS no_pay_90, SUM(r.ghost_count) AS ghost, SUM(r.billed_sum) AS billed_sum, SUM(r.paid_sum) AS paid_sum")
            ->groupBy('b.period_key', 'b.period_type')->orderByDesc('as_of')->limit($periods)->get()->reverse()->values();

        $series = [];
        $previous = null;

        foreach ($rows as $row) {
            $point = [
                'period' => $row->period_key, 'type' => $row->period_type, 'as_of' => $row->as_of, 'districts' => (int) $row->batches,
                'customers' => (int) $row->customers, 'billing' => (int) $row->billing,
                'debit' => $this->cedis($row->debit_sum), 'credit' => $this->cedis($row->credit_sum),
                'faulty_pct' => $this->pct((int) $row->faulty, (int) $row->customers),
                'no_pay_90_pct' => $this->pct((int) $row->no_pay_90, (int) $row->customers),
                'ghost' => (int) $row->ghost,
                'paid_to_billed' => $this->pct((int) $row->paid_sum, (int) $row->billed_sum),
            ];

            $point['change'] = $previous && $previous['districts'] === $point['districts'] ? [
                'customers' => $point['customers'] - $previous['customers'],
                'debit' => round($point['debit'] - $previous['debit'], 2),
                'faulty_pct' => $point['faulty_pct'] !== null && $previous['faulty_pct'] !== null ? round($point['faulty_pct'] - $previous['faulty_pct'], 1) : null,
            ] : null;

            $series[] = $point;
            $previous = $point;
        }

        return ['series' => $series];
    }

    /**
     * The same series with one point per MONTH: each district contributes the last upload it made in that month (so a weekly
     * district's month-end is its last week), and the points are summed across districts.
     *
     * @return array<string, mixed>
     */
    protected function trendByMonthEnd(?int $restriction, ?int $regionId, ?int $districtId, int $months): array
    {
        $batches = CommercialCustomerBatch::query()->where('status', CommercialCustomerBatch::STATUS_IMPORTED)
            ->when($restriction !== null, fn ($q) => $restriction === 0 ? $q->whereRaw('1 = 0') : $q->where('region_id', $restriction))
            ->when($regionId, fn ($q, $id) => $q->where('region_id', $id))
            ->when($districtId, fn ($q, $id) => $q->where('district_id', $id))
            ->orderBy('as_of_date')->orderBy('id')->get(['id', 'district_id', 'as_of_date']);

        // the last batch of each district in each month
        $last = [];

        foreach ($batches as $batch) {
            $last[$batch->district_id.'|'.\Illuminate\Support\Carbon::parse($batch->as_of_date)->format('Y-m')] = $batch;
        }

        $byMonth = collect($last)->groupBy(fn ($batch) => \Illuminate\Support\Carbon::parse($batch->as_of_date)->format('Y-m'))->sortKeys()->take(-$months);
        $ids = $byMonth->flatten()->pluck('id')->all();

        $sums = DB::table('commercial_customer_rollups_district AS r')
            ->join('commercial_customer_statuses AS s', 's.id', '=', 'r.status_id')
            ->join('commercial_meter_statuses AS m', 'm.id', '=', 'r.meter_status_id')
            ->whereIn('r.batch_id', $ids ?: [0])
            ->selectRaw('r.batch_id, SUM(r.customer_count) AS customers, SUM(CASE WHEN s.is_billing = 1 THEN r.customer_count ELSE 0 END) AS billing, SUM(r.debit_sum) AS debit_sum, SUM(r.credit_sum) AS credit_sum, '
                ."SUM(CASE WHEN m.code = 'F' THEN r.customer_count ELSE 0 END) AS faulty, SUM(r.no_pay_90) AS no_pay_90, SUM(r.ghost_count) AS ghost, SUM(r.billed_sum) AS billed_sum, SUM(r.paid_sum) AS paid_sum")
            ->groupBy('r.batch_id')->get()->keyBy('batch_id');

        $series = [];
        $previous = null;

        foreach ($byMonth as $month => $group) {
            $row = (object) ['customers' => 0, 'billing' => 0, 'debit_sum' => 0, 'credit_sum' => 0, 'faulty' => 0, 'no_pay_90' => 0, 'ghost' => 0, 'billed_sum' => 0, 'paid_sum' => 0];

            foreach ($group as $batch) {
                foreach (array_keys((array) $row) as $key) {
                    $row->$key += (float) ($sums[$batch->id]->$key ?? 0);
                }
            }

            $point = [
                'period' => $month, 'type' => 'month-end', 'as_of' => $group->max('as_of_date'), 'districts' => $group->count(),
                'customers' => (int) $row->customers, 'billing' => (int) $row->billing, 'debit' => $this->cedis($row->debit_sum), 'credit' => $this->cedis($row->credit_sum),
                'faulty_pct' => $this->pct((int) $row->faulty, (int) $row->customers), 'no_pay_90_pct' => $this->pct((int) $row->no_pay_90, (int) $row->customers),
                'ghost' => (int) $row->ghost, 'paid_to_billed' => $this->pct((int) $row->paid_sum, (int) $row->billed_sum),
            ];
            $point['change'] = $previous && $previous['districts'] === $point['districts'] ? [
                'customers' => $point['customers'] - $previous['customers'], 'debit' => round($point['debit'] - $previous['debit'], 2),
                'faulty_pct' => $point['faulty_pct'] !== null && $previous['faulty_pct'] !== null ? round($point['faulty_pct'] - $previous['faulty_pct'], 1) : null,
            ] : null;

            $series[] = $point;
            $previous = $point;
        }

        return ['series' => $series];
    }

    /**
     * District league table for the batches in view, and the same figures for the region and for the whole company (only when
     * the viewer may see every region), so a district can be read against its surroundings.
     *
     * @return array<string, mixed>
     */
    public function league(CustomerFilters $f, ?CustomerFilters $company = null): array
    {
        $columns = ['customer_count', 'debit_sum', 'billed_sum', 'paid_sum', 'no_pay_90', 'ghost_count'];
        $cases = "COALESCE(SUM(CASE WHEN m.code = 'F' THEN r.customer_count ELSE 0 END), 0) AS faulty, COALESCE(SUM(CASE WHEN s.is_billing = 1 THEN r.customer_count ELSE 0 END), 0) AS billing";

        $shape = fn ($r) => [
            'customers' => (int) $r->customer_count, 'billing' => (int) $r->billing,
            'debit' => $this->cedis($r->debit_sum), 'debit_per_customer' => (int) $r->customer_count > 0 ? round($this->cedis($r->debit_sum) / (int) $r->customer_count, 2) : null,
            'faulty_pct' => $this->pct((int) $r->faulty, (int) $r->customer_count),
            'no_pay_90_pct' => $this->pct((int) $r->no_pay_90, (int) $r->customer_count),
            'paid_to_billed' => $this->pct((int) $r->paid_sum, (int) $r->billed_sum),
            'ghost' => (int) $r->ghost_count,
        ];

        $districts = $this->grouped($f, 'district', $columns, $cases)->map(fn ($r) => ['key' => (int) $r->dim_key, 'label' => $r->dim_name, 'region_id' => (int) $r->dim_region] + $shape($r))->values();

        // Rank each measure (1 = best): fewer debts, faults and non-payers are better, a higher collection is better.
        $rank = function (string $key, bool $ascending) use ($districts): array {
            $sorted = $districts->filter(fn ($d) => $d[$key] !== null)->sortBy($key, SORT_REGULAR, ! $ascending)->pluck('key')->values();

            return $sorted->flip()->map(fn ($i) => $i + 1)->all();
        };

        $ranks = ['debit_per_customer' => $rank('debit_per_customer', true), 'faulty_pct' => $rank('faulty_pct', true), 'no_pay_90_pct' => $rank('no_pay_90_pct', true), 'paid_to_billed' => $rank('paid_to_billed', false)];

        $region = $this->one($f, $columns, $cases);
        $out = [
            'districts' => $districts->map(fn ($d) => $d + ['ranks' => array_map(fn ($r) => $r[$d['key']] ?? null, $ranks)])->all(),
            'selection' => $shape($region),
            'company' => null,
        ];

        if ($company) {
            $out['company'] = $shape($this->one($company, $columns, $cases));
        }

        return $out;
    }
}
