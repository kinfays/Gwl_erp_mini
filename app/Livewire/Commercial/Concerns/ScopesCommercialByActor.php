<?php

namespace App\Livewire\Commercial\Concerns;

use App\Models\CommercialImportBatch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Who sees which Commercial batches. A separate trait from Assets\Concerns\ScopesAssetsByActor on purpose: the rules
 * differ (Assets scopes ICT staff; this scopes everyone) and neither should change when the other does.
 *
 * super_admin, Global Admin and Head Office staff see every region. Everyone else sees their own region only, and a
 * user with no employee record or no region sees nothing. Head Office is a district whose staff share the Head Office
 * district's region_id, so "Head Office" is recognised by location_type, never by region alone.
 */
trait ScopesCommercialByActor
{
    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function actorEmployee(): ?Employee
    {
        $user = $this->actor();

        return $user->employee ?? $user->employeeByStaffId;
    }

    protected function actorRegionId(): ?int
    {
        return $this->actorEmployee()?->region_id;
    }

    protected function actorSeesAllRegions(): bool
    {
        $user = $this->actor();

        return $user->hasRoles('super_admin', 'admin') || $this->actorEmployee()?->location_type === 'HeadOffice';
    }

    protected function scopeBatchesForActor(Builder $query): Builder
    {
        if ($this->actorSeesAllRegions()) {
            return $query;
        }

        $regionId = $this->actorRegionId();

        return $regionId
            ? $query->where($query->qualifyColumn('region_id'), $regionId)
            : $query->whereRaw('1 = 0');
    }

    protected function actorCanAccessBatch(CommercialImportBatch $batch): bool
    {
        if ($this->actorSeesAllRegions()) {
            return true;
        }

        $regionId = $this->actorRegionId();

        return $regionId !== null && $batch->region_id !== null && (int) $batch->region_id === (int) $regionId;
    }

    protected function abortUnlessBatchAccessible(CommercialImportBatch $batch): void
    {
        abort_unless($this->actorCanAccessBatch($batch), 403, 'This batch belongs to another region.');
    }

    /** Regions the actor may file or resolve reports for: null means every region. */
    protected function uploadRegionRestriction(): ?int
    {
        return $this->actorSeesAllRegions() ? null : ($this->actorRegionId() ?? 0);
    }

    /** Passes for super_admin or a user holding ANY of the permissions. */
    protected function guardCommercialPermission(string ...$slugs): void
    {
        $user = Auth::user();

        if (! $user) {
            abort(403);
        }

        if ($user->hasRoles('super_admin')) {
            return;
        }

        foreach ($slugs as $slug) {
            if ($user->hasPermission($slug)) {
                return;
            }
        }

        abort(403, 'You do not have permission to do that.');
    }

    /** Anyone who works with uploaded batches: uploads, resolves matches or voids. */
    protected function guardBatchWork(): void
    {
        $this->guardCommercialPermission('commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches');
    }

    protected function actorCan(string $slug): bool
    {
        $user = Auth::user();

        return (bool) $user && ($user->hasRoles('super_admin') || $user->hasPermission($slug));
    }
}
