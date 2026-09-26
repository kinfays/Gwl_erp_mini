<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\District;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

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

    protected function actorRegion(): ?Region
    {
        $regionId = $this->actorRegionId();

        return $regionId ? Region::query()->find($regionId) : null;
    }

    /**
     * Region on the Assets/Phones/Network forms is never user-selected: every
     * actor (super_admin included) records devices against their own region,
     * so a save without one is blocked rather than stored as NULL.
     */
    protected function requireActorRegionId(): int
    {
        $regionId = $this->actorRegionId();

        if (! $regionId) {
            throw ValidationException::withMessages([
                'form.region_id' => 'Your account has no region assigned — contact an administrator before adding assets.',
            ]);
        }

        return $regionId;
    }

    /**
     * Location (district) options for those forms: the actor's region only.
     */
    protected function actorRegionDistricts(): Collection
    {
        return District::query()
            ->where('region_id', $this->actorRegionId() ?? 0)
            ->orderBy('district_name')
            ->get();
    }

    /**
     * Server-side twin of actorRegionDistricts(), so a district from another
     * region can't be submitted by tampering with the request.
     */
    protected function actorRegionDistrictRule(): Exists
    {
        return Rule::exists('districts', 'id')->where('region_id', $this->actorRegionId() ?? 0);
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
