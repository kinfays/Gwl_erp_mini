<?php

namespace App\Livewire\HealthSafety\Concerns;

use App\Models\Employee;
use App\Models\HsIncident;
use App\Models\User;
use App\Services\HealthSafety\EquipmentScope;
use App\Services\HealthSafety\IncidentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who sees which Health & Safety records. A separate trait from the Assets and Commercial ones on purpose: the rules
 * differ (here everyone sees their own reports, officers their region, district managers their district) and none of
 * them should change when another does. The rules themselves live in IncidentVisibility, which the controllers,
 * notifications and print copy call too, so this trait is only the Livewire-side wrapper.
 */
trait ScopesHealthSafetyByActor
{
    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function visibility(): IncidentVisibility
    {
        return app(IncidentVisibility::class);
    }

    protected function equipmentScope(): EquipmentScope
    {
        return app(EquipmentScope::class);
    }

    protected function actorEmployee(): ?Employee
    {
        return $this->visibility()->employeeOf($this->actor());
    }

    protected function actorRegionId(): ?int
    {
        return $this->actorEmployee()?->region_id;
    }

    protected function actorSeesAllRegions(): bool
    {
        return $this->visibility()->seesAllRegions($this->actor());
    }

    protected function actorCan(string $slug): bool
    {
        $user = Auth::user();

        return (bool) $user && $this->visibility()->can($user, $slug);
    }

    /** Passes for super_admin or a user holding ANY of the permissions. */
    protected function guardHealthSafetyPermission(string ...$slugs): void
    {
        if (! Auth::user()) {
            abort(403);
        }

        foreach ($slugs as $slug) {
            if ($this->actorCan($slug)) {
                return;
            }
        }

        abort(403, 'You do not have permission to do that.');
    }

    /** The incidents the actor may see: their own reports plus their role's part of the register. */
    protected function incidentsForActor(): Builder
    {
        return $this->visibility()->scopeFor(HsIncident::query(), $this->actor());
    }

    protected function abortUnlessIncidentVisible(HsIncident $incident): void
    {
        abort_unless($this->visibility()->canView($this->actor(), $incident), 403, 'This incident is not available to you.');
    }

    /**
     * The PPE employee picker: ACTIVE employees in the actor's part of the register only (their own region or district),
     * matched on staff ID or name, at most eight. Unlike employeeMatches() it follows the equipment scope exactly.
     *
     * @return Collection<int, Employee>
     */
    protected function ppeEmployeeMatches(string $term, int $limit = 8): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return $this->equipmentScope()->employees($this->actor())
            ->with('jobTitle:id,job_title_name')
            ->where(fn (Builder $query) => $query
                ->where('full_name', 'like', '%'.$term.'%')
                ->orWhere('staff_id', 'like', $term.'%'))
            ->orderBy('full_name')
            ->limit($limit)
            ->get(['id', 'staff_id', 'full_name', 'job_title_id']);
    }

    /**
     * Employees to pick from (assignees, people affected, someone reported for): active staff in the actor's region, or
     * anywhere for someone who sees every region. Matched on name or staff ID; at most eight.
     *
     * @return Collection<int, Employee>
     */
    protected function employeeMatches(string $term, int $limit = 8): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Employee::query()
            ->active()
            ->visibleTo($this->actor())
            ->when(! $this->actorSeesAllRegions(), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
            ->where(fn (Builder $query) => $query
                ->where('full_name', 'like', '%'.$term.'%')
                ->orWhere('staff_id', 'like', $term.'%'))
            ->orderBy('full_name')
            ->limit($limit)
            ->get(['id', 'staff_id', 'full_name', 'district_id']);
    }
}
