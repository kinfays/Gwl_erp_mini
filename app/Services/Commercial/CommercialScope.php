<?php

namespace App\Services\Commercial;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * WHO may see WHICH regions and districts of the Commercial module: the one rule (design 8.30), asked by the Livewire scoping trait,
 * the customer services, the exports, the SMS lock and the queued jobs. It has no Auth::user() of its own, so a job can ask it too;
 * a test pins the Livewire trait to it.
 *
 *   super_admin, Global Admin (admin) and Head Office staff     every region, every district      regions null, districts null
 *   holders of commercial.view_all_districts                    every district of THEIR region      regions [R], districts null
 *   anyone else with commercial access                          ONLY the district on their employee record   regions [R], districts [D]
 *   no employee region (or no employee district, without the permission)    nothing               regions [], districts []
 *
 * Head Office is a district whose staff share the Head Office district's region id, so "Head Office" is recognised by
 * `location_type`, never by region alone.
 */
final class CommercialScope
{
    /**
     * @param  list<int>|null  $regions  null = every region
     * @param  list<int>|null  $districts  null = every district (of those regions)
     */
    private function __construct(public readonly ?array $regions, public readonly ?array $districts)
    {
    }

    public static function for(?User $user): self
    {
        if (! $user) {
            return new self([], []);
        }

        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($user->hasRoles('super_admin', 'admin') || $employee?->location_type === 'HeadOffice') {
            return new self(null, null);
        }

        $regionId = $employee?->region_id !== null ? (int) $employee->region_id : null;

        if ($regionId === null) {
            return new self([], []);
        }

        if ($user->hasPermission('commercial.view_all_districts')) {
            return new self([$regionId], null);
        }

        $districtId = $employee?->district_id !== null ? (int) $employee->district_id : null;

        return $districtId === null ? new self([], []) : new self([$regionId], [$districtId]);
    }

    /** Whether the person may see anything of the module's data at all. */
    public function hasAnyAccess(): bool
    {
        return $this->regions !== [] && $this->districts !== [];
    }

    public function seesAllRegions(): bool
    {
        return $this->regions === null;
    }

    /** True for a district user: no region-wide row, no other district's figure. */
    public function isDistrictLimited(): bool
    {
        return $this->districts !== null;
    }

    public function seesRegion(?int $regionId): bool
    {
        if ($this->regions === null) {
            return true;
        }

        return $regionId !== null && in_array($regionId, $this->regions, true);
    }

    /** A district id; null never matches a limited scope (a row with no district is a region-wide row). */
    public function seesDistrict(?int $districtId, ?int $regionId = null): bool
    {
        if ($this->districts === null) {
            return $regionId === null ? $this->regions !== [] : $this->seesRegion($regionId);
        }

        return $districtId !== null && in_array($districtId, $this->districts, true);
    }

    /** The one region the person is limited to: null = every region, 0 = none, otherwise the id (the form CustomerScope has always returned). */
    public function regionRestriction(): ?int
    {
        if ($this->regions === null) {
            return null;
        }

        return $this->regions === [] ? 0 : $this->regions[0];
    }

    /** The district ids to restrict to, or null for no district limit. An empty list is "no district at all". @return list<int>|null */
    public function districtIds(): ?array
    {
        return $this->districts;
    }

    /**
     * Narrows a query on a table with a district column to the districts of the scope (nothing when the scope has none). A scope
     * with no district limit leaves the query alone: the region rule is applied where the query reaches its batches.
     */
    public function limitDistricts(EloquentBuilder|QueryBuilder $query, string $column): EloquentBuilder|QueryBuilder
    {
        if ($this->districts === null) {
            return $query;
        }

        return $this->districts === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $this->districts);
    }

    /** A short string that tells two scopes apart (for a cache key). */
    public function key(): string
    {
        $part = fn (?array $ids): string => $ids === null ? 'all' : ($ids === [] ? 'none' : implode('.', $ids));

        return $part($this->regions).'|'.$part($this->districts);
    }
}
