<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvEvent;
use App\Models\FinSupplier;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The ONLY place that works out payment-voucher figures: the Reports screens, their charts and the Excel / PDF exports all show
 * what report() returns and never add a second implementation (in SQL or a screen).
 *
 * One LOCATION at a time (the user's own; super_admin picks one), read through FinanceScope::pvs() as Finance (drafts counted
 * separately) or as Audit (never a draft), so it can only ever see what the viewer may. Two kinds of figure:
 *  - the PERIOD (vouchers dated from..to): counts, amounts, spend by category / type / payee kind / month / supplier, how often
 *    Audit queried and why, and the turnaround times (how long Audit took to respond, how long Finance took to answer a query,
 *    first sending to Finance's acknowledgement);
 *  - RIGHT NOW (whatever the period): what is waiting, for how long, and how much of it is overdue (PvBacklog + FinanceSettings).
 * Amounts are integer pesewas (the charts get a cedis copy); a voided voucher and a draft are never counted as spend; every ratio is
 * null on a zero denominator. The payload is arrays and scalars only (it is cached), keyed by what the viewer reads, the location,
 * the period and FinanceBooksVersion (bumped after every write), so a figure is never shown after a change.
 */
class PvAnalyticsService
{
    /** Months of the trend charts: the 36 ending with the period's last month. */
    public const MAX_MONTHS = 36;

    public const AGING = ['0-2 days' => [0, 2], '3-6 days' => [3, 6], '7-13 days' => [7, 13], '14+ days' => [14, null]];

    public function __construct(protected FinanceScope $scope, protected PvBacklog $backlog, protected PvCatalogService $catalogue) {}

    /**
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return array<string, mixed>
     */
    public function report(User $actor, string $as, array $location, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $key = 'fin_analytics:v1:'.md5(json_encode([
            $this->scope->keyFor($actor), $as, $as === FinanceScope::AS_AUDIT ? $this->scope->auditReach($actor) : '', FinanceBooksVersion::unitKey($location), FinanceBooksVersion::current($location), $from->toDateString(), $to->toDateString(), $location['district_id'] ?? null,
        ]));

        return Cache::remember($key, max(1, (int) config('gwl.fin_analytics_cache_seconds', 300)), fn () => $this->build($actor, $as, $location, $from, $to));
    }

    // ------------------------------------------------------------------ the evaluation

    /** @return array<string, mixed> */
    protected function build(User $actor, string $as, array $location, Carbon $from, Carbon $to): array
    {
        // Figures are location-wide totals (also for the Audit manager, who is not shown every voucher): pvsForTotals(). Only a
        // LIST of vouchers (the waiting items below) goes back through pvs().
        $reach = fn () => $this->scope->atLocation($this->scope->pvsForTotals($actor, $as), $location);
        $listable = fn (array $ids) => $this->scope->atLocation($this->scope->pvs($actor, $as), $location)->whereIn('fin_pvs.id', $ids)->pluck('fin_pvs.id')->all();
        $inPeriod = fn () => $reach()->whereDate('fin_pvs.pv_date', '>=', $from->toDateString())->whereDate('fin_pvs.pv_date', '<=', $to->toDateString());

        $rows = $inPeriod()->get(['fin_pvs.id', 'fin_pvs.pv_date', 'fin_pvs.amount', 'fin_pvs.status', 'fin_pvs.round', 'fin_pvs.payee_kind', 'fin_pvs.supplier_id', 'fin_pvs.payee_name', 'fin_pvs.category_id', 'fin_pvs.type_id']);
        $live = $rows->reject(fn (Pv $pv) => in_array($pv->status, [Pv::DRAFT, Pv::VOIDED], true));

        $events = FinPvEvent::query()
            ->whereIn('pv_id', $inPeriod()->select('fin_pvs.id'))
            ->orderBy('id')
            ->get(['pv_id', 'round', 'action', 'query_reason_code', 'created_at'])
            ->groupBy('pv_id');

        $queriedIds = $events->filter(fn ($list) => $list->contains('action', FinPvEvent::QUERIED))->keys()->all();
        $liveIds = $live->pluck('id')->all();
        $queriedLive = count(array_intersect($queriedIds, $liveIds));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $from->format('d M Y').' to '.$to->format('d M Y')],
            'as' => $as,
            'tiles' => [
                'vouchers' => $live->count(),
                'amount' => (int) $live->sum('amount'),
                'average_amount' => $live->isEmpty() ? null : (int) round($live->sum('amount') / $live->count()),
                'completed_vouchers' => $live->where('status', Pv::COMPLETED)->count(),
                'completed_amount' => (int) $live->where('status', Pv::COMPLETED)->sum('amount'),
                'open_vouchers' => $live->where('status', '!=', Pv::COMPLETED)->count(),
                'open_amount' => (int) $live->where('status', '!=', Pv::COMPLETED)->sum('amount'),
                'drafts' => $rows->where('status', Pv::DRAFT)->count(),
                'voided' => $rows->where('status', Pv::VOIDED)->count(),
            ],
            'audit' => [
                'queried_vouchers' => $queriedLive,
                'queried_rate' => $this->pct($queriedLive, $live->count()),
                'average_rounds' => $live->isEmpty() ? null : round($live->avg('round'), 2),
                'multi_round_vouchers' => $live->where('round', '>', 1)->count(),
                'turnaround' => $this->turnaround($events),
            ],
            'statuses' => $this->statuses($rows, $as),
            'reasons' => $this->reasons($events, $liveIds),
            'categories' => $this->byCategory($live, $location),
            'types' => $this->byType($live, $location),
            'payee_kinds' => $this->byPayeeKind($live),
            'months' => $this->byMonth($live, $from, $to),
            'suppliers' => $this->topSuppliers($live),
            'waiting' => $this->waiting($reach(), $listable),
        ];
    }

    /** @return list<array{status: string, label: string, count: int}> */
    protected function statuses($rows, string $as): array
    {
        return collect(Pv::STATUSES)
            ->reject(fn (string $label, string $status) => $as === FinanceScope::AS_AUDIT && $status === Pv::DRAFT)
            ->map(fn (string $label, string $status) => ['status' => $status, 'label' => $label, 'count' => $rows->where('status', $status)->count()])
            ->values()->all();
    }

    /**
     * Average days (one decimal) from the event that starts a wait to the event that ends it, over every such pair in the period.
     *
     * @return array{audit_response: array{days: float|null, count: int}, finance_response: array{days: float|null, count: int}, acknowledge: array{days: float|null, count: int}, end_to_end: array{days: float|null, count: int}}
     */
    protected function turnaround($events): array
    {
        $samples = ['audit_response' => [], 'finance_response' => [], 'acknowledge' => [], 'end_to_end' => []];
        $days = fn ($start, $end) => max(0, $end->getTimestamp() - $start->getTimestamp()) / 86400;

        foreach ($events as $list) {
            $waitingForAudit = null;
            $waitingForFinance = null;
            $ticked = null;
            $first = null;

            foreach ($list as $event) {
                switch ($event->action) {
                    case FinPvEvent::SUBMITTED:
                    case FinPvEvent::RESUBMITTED:
                        $first ??= $event->created_at;
                        $waitingForAudit = $event->created_at;

                        if ($event->action === FinPvEvent::RESUBMITTED && $waitingForFinance) {
                            $samples['finance_response'][] = $days($waitingForFinance, $event->created_at);
                            $waitingForFinance = null;
                        }
                        break;
                    case FinPvEvent::TICK_WITHDRAWN:
                        $waitingForAudit = $event->created_at;
                        $ticked = null;
                        break;
                    case FinPvEvent::QUERIED:
                    case FinPvEvent::AUDIT_ACKNOWLEDGED:
                        if ($waitingForAudit) {
                            $samples['audit_response'][] = $days($waitingForAudit, $event->created_at);
                            $waitingForAudit = null;
                        }

                        if ($event->action === FinPvEvent::QUERIED) {
                            $waitingForFinance = $event->created_at;
                        } else {
                            $ticked = $event->created_at;
                        }
                        break;
                    case FinPvEvent::FINANCE_ACKNOWLEDGED:
                        if ($ticked) {
                            $samples['acknowledge'][] = $days($ticked, $event->created_at);
                        }

                        if ($first) {
                            $samples['end_to_end'][] = $days($first, $event->created_at);
                        }
                        break;
                }
            }
        }

        return collect($samples)->map(fn (array $values) => [
            'days' => $values === [] ? null : round(array_sum($values) / count($values), 1),
            'count' => count($values),
        ])->all();
    }

    /** Why Audit queried (every query of a voucher that went ahead), most common first. @return list<array{code: string, label: string, count: int}> */
    protected function reasons($events, array $liveIds): array
    {
        $counts = [];

        foreach ($events as $pvId => $list) {
            if (! in_array($pvId, $liveIds, true)) {
                continue;
            }

            foreach ($list->where('action', FinPvEvent::QUERIED) as $event) {
                $code = $event->query_reason_code ?: 'other';
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        arsort($counts);

        return collect($counts)->map(fn (int $count, string $code) => ['code' => $code, 'label' => Pv::QUERY_REASONS[$code] ?? ucfirst(str_replace('_', ' ', $code)), 'count' => $count])->values()->all();
    }

    /** @return list<array{name: string, count: int, amount: int}> */
    protected function byCategory($live, array $location): array
    {
        // The location's own effective names (its renames), from the one place that works them out.
        $names = $this->catalogue->effective($location)->pluck('effective_name', 'id');

        return $live->groupBy('category_id')
            ->map(fn ($group, $id) => ['name' => (string) ($names[$id] ?? 'Unknown'), 'count' => $group->count(), 'amount' => (int) $group->sum('amount')])
            ->sortByDesc('amount')->values()->all();
    }

    /** The ten types with the most spend, as "Category > Type". @return list<array{name: string, count: int, amount: int}> */
    protected function byType($live, array $location): array
    {
        $types = $this->catalogue->effective($location)->flatMap(fn ($category) => $category->types->map(fn ($type) => ['id' => $type->id, 'name' => $category->effective_name.' > '.$type->effective_name]))->pluck('name', 'id');

        return $live->groupBy('type_id')
            ->map(function ($group, $id) use ($types) {
                return ['name' => (string) ($types[$id] ?? 'Unknown'), 'count' => $group->count(), 'amount' => (int) $group->sum('amount')];
            })
            ->sortByDesc('amount')->take(10)->values()->all();
    }

    /** @return list<array{kind: string, label: string, count: int, amount: int}> */
    protected function byPayeeKind($live): array
    {
        return collect(Pv::PAYEE_KINDS)
            ->map(fn (string $label, string $kind) => ['kind' => $kind, 'label' => $label, 'count' => $live->where('payee_kind', $kind)->count(), 'amount' => (int) $live->where('payee_kind', $kind)->sum('amount')])
            ->filter(fn (array $row) => $row['count'] > 0)->values()->all();
    }

    /**
     * Month by month (the PV date's month), zero for a month with nothing. Chart-ready: parallel `labels`, `vouchers`, `amount`
     * (cedis) arrays, plus `rows` for the table.
     *
     * @return array{labels: list<string>, vouchers: list<int>, amount: list<float>, rows: list<array{month: string, label: string, count: int, amount: int}>}
     */
    protected function byMonth($live, Carbon $from, Carbon $to): array
    {
        $cursor = $to->copy()->startOfMonth();
        $first = $from->copy()->startOfMonth();
        $months = [];

        for ($i = 0; $i < self::MAX_MONTHS && $cursor->gte($first); $i++) {
            array_unshift($months, $cursor->copy());
            $cursor->subMonthNoOverflow();
        }

        $by = $live->groupBy(fn (Pv $pv) => $pv->pv_date->format('Y-m'));
        $rows = collect($months)->map(fn (Carbon $month) => [
            'month' => $month->format('Y-m'),
            'label' => $month->format('M Y'),
            'count' => $by->get($month->format('Y-m'), collect())->count(),
            'amount' => (int) $by->get($month->format('Y-m'), collect())->sum('amount'),
        ]);

        return [
            'labels' => $rows->pluck('label')->all(),
            'vouchers' => $rows->pluck('count')->all(),
            'amount' => $rows->map(fn (array $row) => round($row['amount'] / 100, 2))->all(),
            'rows' => $rows->all(),
        ];
    }

    /** @return list<array{supplier_id: int, name: string, count: int, amount: int}> the ten suppliers paid most */
    protected function topSuppliers($live): array
    {
        $paid = $live->where('payee_kind', Pv::PAYEE_SUPPLIER)->whereNotNull('supplier_id');
        $names = FinSupplier::query()->whereIn('id', $paid->pluck('supplier_id')->unique()->all())->pluck('name', 'id');

        return $paid->groupBy('supplier_id')
            ->map(fn ($group, $id) => ['supplier_id' => (int) $id, 'name' => (string) ($names[$id] ?? $group->first()->payee_name), 'count' => $group->count(), 'amount' => (int) $group->sum('amount')])
            ->sortByDesc('amount')->take(10)->values()->all();
    }

    /**
     * What is waiting right now at the location. `aging` buckets the vouchers With Audit by how long they have waited; `items` is
     * the longest-waiting 25 (the waiting export lists them all).
     *
     * @return array{kinds: array<string, array{label: string, count: int, overdue: int, oldest_days: int|null, threshold: int|null}>, aging: array{labels: list<string>, counts: list<int>}, items: list<array<string, mixed>>}
     */
    protected function waiting($pvs, callable $listable): array
    {
        $items = $this->backlog->items($pvs);
        $kinds = [];

        foreach ([PvBacklog::AUDIT, PvBacklog::QUERIED, PvBacklog::ACKNOWLEDGE] as $kind) {
            $of = $items->where('kind', $kind);
            $kinds[$kind] = [
                'label' => PvBacklog::kindLabel($kind),
                'count' => $of->count(),
                'overdue' => $of->where('overdue', true)->count(),
                'oldest_days' => $of->isEmpty() ? null : (int) $of->max('days'),
                'threshold' => $of->isEmpty() ? null : (int) $of->first()['threshold'],
            ];
        }

        $withAudit = $items->where('kind', PvBacklog::AUDIT);
        $aging = ['labels' => array_keys(self::AGING), 'counts' => []];

        foreach (self::AGING as [$low, $high]) {
            $aging['counts'][] = $withAudit->filter(fn (array $item) => $item['days'] >= $low && ($high === null || $item['days'] <= $high))->count();
        }

        return [
            'kinds' => $kinds,
            'aging' => $aging,
            // The counts above include every waiting voucher; the list names only the ones this viewer may open.
            'items' => $items->whereIn('pv.id', $listable($items->pluck('pv.id')->all()))->take(25)->map(fn (array $item) => [
                'pv_id' => $item['pv']->id, 'pv_no' => $item['pv']->pv_no, 'kind' => $item['kind'], 'label' => PvBacklog::kindLabel($item['kind']),
                'since' => $item['since']->toDateString(), 'days' => $item['days'], 'overdue' => $item['overdue'],
            ])->values()->all(),
        ];
    }

    protected function pct(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 1);
    }
}
