<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsPpeIssue;
use App\Models\HsSite;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and work on which equipment. The region / district / Head Office rules are ActorScope's, shared with the
 * incident register, applied here to hs_fire_extinguishers and hs_first_aid_kits through their copied region_id and
 * district_id. Nothing in this class re-states those rules.
 *
 * On top of scope: viewing needs view_equipment; managing (create, edit, service, status, decommission, import) needs
 * manage_equipment; recording a check needs record_checks, OR the user is the item's named responsible person, who may
 * record a check on THAT item and nothing else.
 */
class EquipmentScope
{
    public function __construct(protected ActorScope $scope = new ActorScope) {}

    public function can(User $user, string $slug): bool
    {
        return $this->scope->can($user, $slug);
    }

    public function employeeOf(User $user): ?Employee
    {
        return $this->scope->employeeOf($user);
    }

    /** @return array{level: string, id: int|null} */
    public function scopeOf(User $user): array
    {
        return $this->scope->resolve($user, 'health_safety.view_equipment');
    }

    /** Whether the user's reach spans every region (for pickers). */
    public function seesAllRegions(User $user): bool
    {
        return $this->scopeOf($user)['level'] === ActorScope::ALL
            || $user->hasRoles('super_admin')
            || $this->employeeOf($user)?->location_type === 'HeadOffice';
    }

    /** The region used for pickers and defaults: the actor's own. */
    public function regionIdOf(User $user): ?int
    {
        return $this->employeeOf($user)?->region_id;
    }

    /** Restrict an equipment query to the user's part of the register. */
    public function applyTo(Builder $query, User $user): Builder
    {
        $scope = $this->scopeOf($user);

        return match ($scope['level']) {
            ActorScope::ALL => $query,
            ActorScope::REGION => $query->where($query->qualifyColumn('region_id'), $scope['id']),
            ActorScope::DISTRICT => $query->where($query->qualifyColumn('district_id'), $scope['id']),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** The item lies in the part of the register the user's role entitles them to. */
    public function contains(User $user, Model $item): bool
    {
        $scope = $this->scopeOf($user);

        return match ($scope['level']) {
            ActorScope::ALL => true,
            ActorScope::REGION => (int) $item->region_id === $scope['id'],
            ActorScope::DISTRICT => $item->district_id !== null && (int) $item->district_id === $scope['id'],
            default => false,
        };
    }

    /** Equipment (of either kind) the user is named as responsible for. */
    public function isResponsible(User $user, Model $item): bool
    {
        $employeeId = $this->employeeOf($user)?->id;

        return $employeeId !== null
            && $item->responsible_employee_id !== null
            && (int) $item->responsible_employee_id === (int) $employeeId;
    }

    public function canView(User $user, Model $item): bool
    {
        return ($this->can($user, 'health_safety.view_equipment') && $this->contains($user, $item))
            || $this->isResponsible($user, $item);
    }

    public function canManage(User $user, Model $item): bool
    {
        return $this->can($user, 'health_safety.manage_equipment') && $this->contains($user, $item);
    }

    public function canCheck(User $user, Model $item): bool
    {
        return ($this->can($user, 'health_safety.record_checks') && $this->contains($user, $item))
            || $this->isResponsible($user, $item);
    }

    /**
     * Whether a new item (or one being moved) may be placed in this region / district by the user: managing equipment,
     * and the place inside their part of the register.
     */
    public function canPlaceIn(User $user, int $regionId, ?int $districtId): bool
    {
        if (! $this->can($user, 'health_safety.manage_equipment')) {
            return false;
        }

        $scope = $this->scopeOf($user);

        return match ($scope['level']) {
            ActorScope::ALL => true,
            ActorScope::REGION => $regionId === $scope['id'],
            ActorScope::DISTRICT => $districtId !== null && $districtId === $scope['id'],
            default => false,
        };
    }

    /** Whether the user may follow the link to a vehicle: only if Transport's own policy lets them see it. */
    public function canSeeVehicle(User $user, ?Vehicle $vehicle): bool
    {
        return $vehicle !== null && ! $vehicle->trashed() && Gate::forUser($user)->allows('view', $vehicle)
            && in_array('transport', $user->getAccessibleModules(), true);
    }

    // ------------------------------------------------------------------ PPE (Phase 3)

    /** Active PPE stores in the user's part of the register. */
    public function ppeStores(User $user): Builder
    {
        return $this->applyTo(HsSite::query()->ppeStores(), $user);
    }

    /** Active employees in the user's part of the register (their own region / district), never super_admin accounts. */
    public function employees(User $user): Builder
    {
        return $this->applyTo(Employee::query()->active()->visibleTo($user), $user);
    }

    /** PPE issues of staff in the user's part of the register (closed issues of people who have since left included). */
    public function ppeIssues(User $user): Builder
    {
        return HsPpeIssue::query()->whereIn(
            'employee_id',
            $this->applyTo(Employee::query()->visibleTo($user), $user)->select('employees.id')
        );
    }

    public function employeeInScope(User $user, Employee $employee): bool
    {
        return $employee->is_active && $this->contains($user, $employee);
    }

    /** Post stock to, or take stock from, this site: manage_ppe, and the store inside the user's part of the register. */
    public function canManagePpeAt(User $user, HsSite $site): bool
    {
        return $this->can($user, 'health_safety.manage_ppe') && $this->contains($user, $site);
    }

    /** Both registers, scoped, for "my equipment" and the overview. */
    public function extinguishers(User $user): Builder
    {
        return $this->applyTo(HsFireExtinguisher::query(), $user);
    }

    public function kits(User $user): Builder
    {
        return $this->applyTo(HsFirstAidKit::query(), $user);
    }
}
