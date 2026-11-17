<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
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
        // Thin wrapper: the definition lives in User::isScopedIct().
        return $this->actor()->isScopedIct();
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

    /**
     * Who may READ every region's assets: super_admin, Global Admin, and the Head Office ICT team. A regional ICT
     * user sees only their own region. Reading is wider than writing on purpose: scopeAssetsForActor() (and the
     * form's own-region stamp) still confine creating, editing and MDM actions to the actor's own region.
     */
    protected function actorSeesAllRegions(): bool
    {
        $user = $this->actor();

        return ! $user->isScopedIct() || ($user->ictScope()['type'] ?? null) === 'head_office';
    }

    /** True when the actor may change records that belong to $regionId (their own region, unless unscoped). */
    protected function actorCanModifyInRegion(?int $regionId): bool
    {
        return ! $this->actorIsRegionScopedIct() || ($regionId !== null && $regionId === $this->actorRegionId());
    }

    /** Asset rows the actor may LIST or count (all regions for Head Office ICT). Use scopeAssetsForActor() for writes. */
    protected function scopeAssetsForViewing(Builder $query): Builder
    {
        return $this->actorSeesAllRegions() ? $query : $this->scopeAssetsForActor($query);
    }

    /**
     * Districts the list filters may offer: those the actor can SEE assets in (every region for Head Office ICT and
     * unscoped roles, the own region for a regional ICT user). Head Office is a district of its own region, so this
     * never scopes "Head Office" by region alone beyond what scopeAssetsForViewing() already does for the rows.
     */
    protected function viewableDistricts(): Collection
    {
        return District::query()
            ->with('region')
            ->when(! $this->actorSeesAllRegions(), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
            ->orderBy('district_name')
            ->get();
    }

    /**
     * What the browser sent as a district filter, cut down to the districts the actor may see. Junk and ids from
     * another region are dropped (not obeyed); an empty result means "no district filter".
     *
     * @param  array<int|string, mixed>  $requested
     * @return array<int, int>
     */
    protected function permittedDistrictIds(array $requested): array
    {
        $ids = $this->wholeNumbers($requested);

        if ($ids === []) {
            return [];
        }

        $allowed = District::query()
            ->whereIn('id', $ids)
            ->when(! $this->actorSeesAllRegions(), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect($ids, $allowed));
    }

    /**
     * Models the Model filter may offer on a list: only those that occur on assets of this category that the actor
     * can see (so the phones list never offers a laptop model, and a regional user never sees another region's).
     */
    protected function viewableModels(string $category): Collection
    {
        return IctAssetModel::query()
            ->with('manufacturer')
            ->whereIn('id', $this->scopeAssetsForViewing(IctAsset::query())
                ->where('device_category', $category)
                ->whereNotNull('ict_asset_model_id')
                ->select('ict_asset_model_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int|string, mixed>  $requested
     * @return array<int, int>
     */
    protected function permittedModelIds(array $requested, string $category): array
    {
        $ids = $this->wholeNumbers($requested);

        return $ids === [] ? [] : array_values(array_intersect($ids, $this->viewableModels($category)->pluck('id')->map(fn ($id) => (int) $id)->all()));
    }

    /** @return array<int, int> the whole, positive, distinct numbers in a client-sent list; anything else is dropped */
    private function wholeNumbers(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => is_int($value) || (is_string($value) && ctype_digit($value)))
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** Report rows the actor may LIST (all regions for Head Office ICT). Use scopeReportsForActor() for writes. */
    protected function scopeReportsForViewing(Builder $query, string $regionColumn = 'reporting_region_id'): Builder
    {
        return $this->actorSeesAllRegions() ? $query : $this->scopeReportsForActor($query, $regionColumn);
    }

    /** Row-level data the list views need to hide actions on records the actor can see but not change. @return array<string, mixed> */
    protected function regionViewData(): array
    {
        return [
            'ownRegionId' => $this->actorRegionId(),
            'regionLimited' => $this->actorIsRegionScopedIct(),
            'seesAllRegions' => $this->actorSeesAllRegions(),
        ];
    }

    protected function scopeAssetsForActor(Builder $query): Builder
    {
        if (! $this->actorIsRegionScopedIct()) {
            return $query;
        }

        $regionId = $this->actorRegionId();

        // Qualified: the dashboard joins districts, which has a region_id of its own.
        return $regionId
            ? $query->where($query->getModel()->qualifyColumn('region_id'), $regionId)
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
