<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which customer batches a viewer is looking at. Every figure on the customer screens is read from the rollups of the
 * *effective* batch of each district (the newest imported one, or the effective one of a chosen period), so this is the one
 * place that decides it. Scope is applied here AND again in the analytics queries.
 *
 * $restriction: null = every region, 0 = none, otherwise the one region the viewer may see (CustomerScope::regionRestriction).
 */
class CustomerSnapshots
{
    /** The viewer's batches that count as data: imported = the winner of its district and period. */
    public function scoped(?int $restriction): Builder
    {
        $query = CommercialCustomerBatch::query()->where('status', CommercialCustomerBatch::STATUS_IMPORTED);

        if ($restriction === null) {
            return $query;
        }

        return $restriction === 0 ? $query->whereRaw('1 = 0') : $query->where('region_id', $restriction);
    }

    /**
     * One batch per district: the newest, or the effective one of $periodKey.
     *
     * @return Collection<int, CommercialCustomerBatch> keyed by district id
     */
    public function current(?int $restriction, ?int $regionId = null, ?int $districtId = null, ?string $periodKey = null): Collection
    {
        // "2026-10" is a MONTH-END view: the last upload of each district inside that month, whatever its cadence (a district
        // that uploads weekly contributes its last week of the month). Any other key is one period exactly.
        $month = $periodKey !== null && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodKey) ? \Illuminate\Support\Carbon::create((int) substr($periodKey, 0, 4), (int) substr($periodKey, 5, 2), 1) : null;

        return $this->scoped($restriction)
            ->when($regionId, fn ($q, $id) => $q->where('region_id', $id))
            ->when($districtId, fn ($q, $id) => $q->where('district_id', $id))
            ->when($month, fn ($q, $first) => $q->whereDate('as_of_date', '>=', $first->toDateString())->whereDate('as_of_date', '<=', $first->copy()->endOfMonth()->toDateString()))
            ->when($periodKey !== null && $month === null, fn ($q) => $q->where('period_key', $periodKey))
            ->whereNotNull('district_id')
            ->orderBy('as_of_date')->orderBy('id')
            ->get()
            ->keyBy('district_id');   // the later of two for a district replaces the earlier
    }

    /**
     * The effective batch before each of the given ones (same district): the baseline for "change since last time".
     *
     * @param  Collection<int, CommercialCustomerBatch>  $current
     * @return Collection<int, CommercialCustomerBatch> keyed by district id
     */
    public function previous(?int $restriction, Collection $current): Collection
    {
        $previous = collect();

        foreach ($current as $districtId => $batch) {
            $before = $this->scoped($restriction)->where('district_id', $districtId)->where(fn ($q) => $q
                ->whereDate('as_of_date', '<', $batch->as_of_date)
                ->orWhere(fn ($q2) => $q2->whereDate('as_of_date', '=', $batch->as_of_date)->where('id', '<', $batch->id)))
                ->orderByDesc('as_of_date')->orderByDesc('id')->first();

            if ($before) {
                $previous->put($districtId, $before);
            }
        }

        return $previous;
    }

    /**
     * Period keys with data, newest first, for the period picker and the trend series.
     *
     * @return list<array{key: string, type: string, as_of: string}>
     */
    public function periods(?int $restriction, ?int $regionId = null, ?int $districtId = null, int $limit = 24): array
    {
        return $this->scoped($restriction)
            ->when($regionId, fn ($q, $id) => $q->where('region_id', $id))
            ->when($districtId, fn ($q, $id) => $q->where('district_id', $id))
            ->selectRaw('period_key, period_type, MAX(as_of_date) AS as_of')
            ->groupBy('period_key', 'period_type')
            ->orderByDesc('as_of')->limit($limit)->get()
            ->map(fn ($row) => ['key' => (string) $row->period_key, 'type' => (string) $row->period_type, 'as_of' => (string) $row->as_of])
            ->all();
    }

    /**
     * Months that have data (newest first) for the month-end view of the period picker.
     *
     * @return list<string> Y-m
     */
    public function monthEnds(?int $restriction, ?int $regionId = null, int $limit = 24): array
    {
        return $this->scoped($restriction)->when($regionId, fn ($q, $id) => $q->where('region_id', $id))
            ->orderByDesc('as_of_date')->limit(2000)->pluck('as_of_date')
            ->map(fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('Y-m'))->unique()->take($limit)->values()->all();
    }

    public function filters(Collection $batches, ?int $restriction, array $narrowing = []): CustomerFilters
    {
        return new CustomerFilters(
            batchIds: $batches->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            regionRestriction: $restriction,
            regionId: $narrowing['region_id'] ?? null,
            districtId: $narrowing['district_id'] ?? null,
            routeId: $narrowing['route_id'] ?? null,
            categoryId: $narrowing['category_id'] ?? null,
            group: $narrowing['group'] ?? null,
            statusId: $narrowing['status_id'] ?? null,
            meterStatusId: $narrowing['meter_status_id'] ?? null,
            billingOnly: (bool) ($narrowing['billing_only'] ?? false),
        );
    }
}
