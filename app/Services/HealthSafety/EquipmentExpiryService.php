<?php

namespace App\Services\HealthSafety;

use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filtered equipment queries, built in ONE place for the register screens and the overview, so a number on the
 * overview and the list it links to cannot disagree. (Phase 4's expiry register, alerts and exports are meant to reuse
 * this class, not re-derive states.)
 *
 * Filters, all optional: state (a computed state, see the models), expiring (days: items whose expiry date falls between
 * today and that many days ahead), status, district_id, site_id, in_vehicles (true: only vehicle-based items), type,
 * search, label ('none': no label printed yet), sort. Decommissioned items are left out unless status asks for them.
 */
class EquipmentExpiryService
{
    public function __construct(protected EquipmentScope $scope) {}

    /** @param  array<string, mixed>  $filters */
    public function extinguishers(User $actor, array $filters = []): Builder
    {
        $query = $this->scope->extinguishers($actor);

        $this->common($query, $filters, HsFireExtinguisher::STATUS_DECOMMISSIONED);

        $query
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where($q->qualifyColumn('extinguisher_type'), $filters['type']))
            ->when(filled($filters['state'] ?? null), fn (Builder $q) => $q->withState((string) $filters['state']))
            ->when((int) ($filters['expiring'] ?? 0) > 0, fn (Builder $q) => $q->expiringWithin((int) $filters['expiring']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.trim((string) $filters['search']).'%';

                $q->where(fn (Builder $match) => $match
                    ->where('asset_code', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                    ->orWhere('location_detail', 'like', $term)
                    ->orWhereHas('site', fn (Builder $site) => $site->where('name', 'like', $term))
                    ->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('number_plate', 'like', $term)));
            });

        return match ($filters['sort'] ?? 'expiry') {
            '-expiry' => $query->orderByRaw('expiry_date is null')->orderByDesc('expiry_date')->orderBy('asset_code'),
            'code' => $query->orderBy('asset_code'),
            default => $query->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('asset_code'),
        };
    }

    /** @param  array<string, mixed>  $filters */
    public function kits(User $actor, array $filters = []): Builder
    {
        $query = $this->scope->kits($actor);

        $this->common($query, $filters, HsFirstAidKit::STATUS_DECOMMISSIONED);

        $query
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where($q->qualifyColumn('kit_type'), $filters['type']))
            ->when(filled($filters['state'] ?? null), fn (Builder $q) => $q->withState((string) $filters['state']))
            ->when((int) ($filters['expiring'] ?? 0) > 0, fn (Builder $q) => $q->expiringWithin((int) $filters['expiring']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.trim((string) $filters['search']).'%';

                $q->where(fn (Builder $match) => $match
                    ->where('asset_code', 'like', $term)
                    ->orWhere('location_detail', 'like', $term)
                    ->orWhereHas('site', fn (Builder $site) => $site->where('name', 'like', $term))
                    ->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('number_plate', 'like', $term)));
            });

        return $query->orderBy('asset_code');
    }

    /**
     * The equipment row of the overview. Every figure is the count of the very query the list it links to runs.
     *
     * @return array<string, array{count: int, params: array<string, mixed>}> keyed extinguishers_expired, extinguishers_expiring, extinguishers_check_overdue, kits_expired, kits_expiring, kits_check_overdue
     */
    public function overview(User $actor): array
    {
        $critical = (int) HealthSafetySettings::value('hs_expiry_critical_days');

        $figures = [
            'extinguishers_expired' => ['kind' => 'extinguishers', 'params' => ['state' => HsFireExtinguisher::STATE_EXPIRED]],
            'extinguishers_expiring' => ['kind' => 'extinguishers', 'params' => ['expiring' => $critical]],
            'extinguishers_check_overdue' => ['kind' => 'extinguishers', 'params' => ['state' => HsFireExtinguisher::STATE_CHECK_OVERDUE]],
            'kits_expired' => ['kind' => 'kits', 'params' => ['state' => HsFirstAidKit::STATE_ITEM_EXPIRED]],
            'kits_expiring' => ['kind' => 'kits', 'params' => ['expiring' => $critical]],
            'kits_check_overdue' => ['kind' => 'kits', 'params' => ['state' => HsFirstAidKit::STATE_CHECK_OVERDUE]],
        ];

        return collect($figures)->map(fn (array $figure) => [
            'count' => $figure['kind'] === 'extinguishers'
                ? $this->extinguishers($actor, $figure['params'])->count()
                : $this->kits($actor, $figure['params'])->count(),
            'params' => $figure['params'],
        ])->all();
    }

    /** Per-state counts of a scoped register, for the filter chips on the list screens. @return array<string, int> */
    public function stateCounts(User $actor, string $kind): array
    {
        $counts = [];

        foreach ($kind === 'kits' ? HsFirstAidKit::stateNames() : HsFireExtinguisher::stateNames() as $state) {
            $counts[$state] = $kind === 'kits'
                ? $this->kits($actor, ['state' => $state])->count()
                : $this->extinguishers($actor, ['state' => $state])->count();
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $filters */
    protected function common(Builder $query, array $filters, string $decommissioned): void
    {
        $query
            ->when(filled($filters['status'] ?? null),
                fn (Builder $q) => $q->where($q->qualifyColumn('status'), $filters['status']),
                fn (Builder $q) => $q->where($q->qualifyColumn('status'), '!=', $decommissioned))
            ->when(filled($filters['district_id'] ?? null), fn (Builder $q) => $q->where($q->qualifyColumn('district_id'), (int) $filters['district_id']))
            ->when(filled($filters['site_id'] ?? null), fn (Builder $q) => $q->where($q->qualifyColumn('site_id'), (int) $filters['site_id']))
            ->when(! empty($filters['in_vehicles']), fn (Builder $q) => $q->whereNotNull($q->qualifyColumn('vehicle_id')))
            // "No label yet": nothing has been printed for the item (Phase 3b).
            ->when(($filters['label'] ?? null) === 'none', fn (Builder $q) => $q->whereNull($q->qualifyColumn('label_printed_at')));
    }
}
