<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait ScopesAssetsByActor
{
    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function actorIsRegionScopedIct(): bool
    {
        $user = $this->actor();

        return $user->hasRoles(User::ROLE_ICT_TEAM)
            && ! $user->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }

    protected function actorRegionId(): ?int
    {
        $user = $this->actor();
        $employee = $user->employee ?? $user->employeeByStaffId;

        return $employee?->region_id;
    }

    protected function scopeAssetsForActor(Builder $query): Builder
    {
        if (! $this->actorIsRegionScopedIct()) {
            return $query;
        }

        $regionId = $this->actorRegionId();

        return $regionId
            ? $query->where('region_id', $regionId)
            : $query->whereRaw('1 = 0');
    }

    protected function scopeReportsForActor(Builder $query, string $regionColumn = 'reporting_region_id'): Builder
    {
        if (! $this->actorIsRegionScopedIct()) {
            return $query;
        }

        $regionId = $this->actorRegionId();

        return $regionId
            ? $query->where($regionColumn, $regionId)
            : $query->whereRaw('1 = 0');
    }
}

