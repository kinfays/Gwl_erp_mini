<?php

namespace App\Services\Uac;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may do what to roles and to the users who hold them. Every UAC entry point (UacController, the roles
 * screen, the users import, the Head Office transfer rule) asks this class instead of testing role names itself.
 *
 * Tiers, highest first: super_admin > Global Admin (slug `admin`) > ICT team > everyone else. Nobody grants a role
 * above their own tier, and only a super_admin touches super_admin.
 *
 * Location. Head Office is a district, not a region; its staff have location_type = HeadOffice and still carry the
 * Head Office district's region_id. A scoped ICT user therefore works in exactly one scope: Head Office, or one
 * region (excluding its Head Office staff). See User::ictScope().
 *
 * Denials are returned as messages (null = allowed) so the caller can show the user why.
 */
class RoleGrantPolicy
{
    /** Roles that only make sense for Head Office staff. */
    public const HEAD_OFFICE_ROLES = ['admin', 'hr_headoffice', 'chief_manager'];

    /** Roles that only make sense for regional-office or district staff. */
    public const REGIONAL_ROLES = ['hr_region', 'regional_chief_manager', 'district_manager'];

    /**
     * Permission modules the anti-escalation rule compares: a role carrying any of these permissions can only be
     * granted (or given the permission) by someone who holds them all. The rule is limited to governance because the
     * ICT team is *meant* to hand out roles whose operational permissions (staff, leave, letters…) it doesn't hold.
     */
    public const GOVERNED_MODULES = [Permission::MODULE_UAC];

    // ------------------------------------------------------------------ Who, and where

    /** May this account work in the UAC user screens at all? */
    public function canManageAccounts(User $actor): bool
    {
        return $actor->tier() >= User::TIER_ICT;
    }

    /** Global Admin and super_admin: roles, permissions, bulk imports. */
    public function canAdministerRoles(User $actor): bool
    {
        return $actor->tier() >= User::TIER_GLOBAL_ADMIN;
    }

    public function canImportUsers(User $actor): bool
    {
        return $this->canAdministerRoles($actor);
    }

    public function roleTier(Role|string $role): int
    {
        return match ($role instanceof Role ? $role->name : $role) {
            User::ROLE_SUPER_ADMIN => User::TIER_SUPER_ADMIN,
            User::ROLE_ADMIN => User::TIER_GLOBAL_ADMIN,
            User::ROLE_ICT_TEAM => User::TIER_ICT,
            default => User::TIER_STAFF,
        };
    }

    /** Users $actor may see and manage: super_admin accounts only for a super_admin, ICT within its location scope. */
    public function usersQueryFor(User $actor): Builder
    {
        $query = User::query()->visibleTo($actor);

        if ($actor->tier() >= User::TIER_GLOBAL_ADMIN) {
            return $query;
        }

        $scope = $actor->ictScope();

        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        $inScope = fn (Builder $employee) => $this->applyScope($employee, $scope);

        return $query->where(fn (Builder $users) => $users
            ->whereHas('employee', $inScope)
            ->orWhereHas('employeeByStaffId', $inScope));
    }

    /** Employees $actor may pick when creating a user. */
    public function employeesQueryFor(User $actor): Builder
    {
        $query = Employee::query()->visibleTo($actor);

        if ($actor->tier() >= User::TIER_GLOBAL_ADMIN) {
            return $query;
        }

        $scope = $actor->ictScope();

        return $scope === null ? $query->whereRaw('1 = 0') : $this->applyScope($query, $scope);
    }

    /** @param  array{type: string, region_id: int|null}  $scope */
    protected function applyScope(Builder $employees, array $scope): Builder
    {
        return match ($scope['type']) {
            'head_office' => $employees->where('location_type', 'HeadOffice'),
            'region' => $employees->where('region_id', $scope['region_id'])->where('location_type', '!=', 'HeadOffice'),
            default => $employees->whereRaw('1 = 0'),
        };
    }

    /**
     * Why $actor may not manage $target's account or roles, or null. The target is a user, or an employee who has no
     * account yet (creating one). $trustScope skips the scope query when the caller already listed the target from
     * usersQueryFor().
     */
    public function targetDenial(User $actor, User|Employee|null $target, bool $trustScope = false): ?string
    {
        if ($target === null) {
            return null;
        }

        if (! $this->canManageAccounts($actor)) {
            return 'You are not allowed to manage users.';
        }

        [$user, $employee] = $this->resolveTarget($target);

        if (! $trustScope) {
            $inScope = $user
                ? $this->usersQueryFor($actor)->whereKey($user->getKey())->exists()
                : ($employee !== null && $this->employeesQueryFor($actor)->whereKey($employee->getKey())->exists());

            if (! $inScope) {
                return $this->outOfScopeMessage($actor);
            }
        }

        if ($user && $actor->is($user) && $actor->isScopedIct()) {
            return 'You cannot change your own roles.';
        }

        return null;
    }

    public function canManageUser(User $actor, User|Employee $target): bool
    {
        return $this->targetDenial($actor, $target) === null;
    }

    /** Activating/deactivating: in scope, and never an account above the actor's own tier. */
    public function statusDenial(User $actor, User $target): ?string
    {
        if (! $this->canManageAccounts($actor)) {
            return 'You are not allowed to manage users.';
        }

        if (! $this->usersQueryFor($actor)->whereKey($target->getKey())->exists()) {
            return $this->outOfScopeMessage($actor);
        }

        if ($target->tier() > $actor->tier()) {
            return 'You cannot change the status of an account above your own level.';
        }

        return null;
    }

    // ------------------------------------------------------------------ Assigning and removing roles

    /**
     * Roles $actor may assign to $target (a user, an employee without an account, or null for "in general").
     *
     * @return Collection<int, Role>
     */
    public function assignableRolesFor(
        User $actor,
        User|Employee|null $target = null,
        bool $trustScope = false,
        ?Collection $candidateRoles = null,
        ?array $governed = null,
    ): Collection {
        if ($this->targetDenial($actor, $target, $trustScope) !== null) {
            return collect();
        }

        // A caller listing many targets passes the roles (with permissions) and the actor's governance permissions
        // once, instead of having them queried for every row.
        $governed ??= $this->governedPermissionNames($actor);
        $candidateRoles ??= Role::query()->visibleTo($actor)->with('permissions')->orderBy('display_name')->get();

        return $candidateRoles
            ->filter(fn (Role $role) => $this->roleDenial($actor, $role, $target, $governed) === null)
            ->values();
    }

    public function assignmentDenial(User $actor, Role $role, User|Employee|null $target = null): ?string
    {
        return $this->targetDenial($actor, $target) ?? $this->roleDenial($actor, $role, $target);
    }

    public function canAssignRole(User $actor, Role $role, User|Employee|null $target = null): bool
    {
        return $this->assignmentDenial($actor, $role, $target) === null;
    }

    /**
     * The role-specific rules, once the target itself is known to be manageable. $target null skips the location rule
     * ("could this actor ever assign the role").
     *
     * @param  list<string>|null  $governed  the actor's governance permissions, when the caller already has them
     */
    public function roleDenial(User $actor, Role $role, User|Employee|null $target = null, ?array $governed = null): ?string
    {
        if ($role->name === User::ROLE_EMPLOYEE) {
            return 'The Employee role is implicit and is not assigned here.';
        }

        if ($role->name === User::ROLE_SUPER_ADMIN) {
            return $actor->isSuperAdmin() ? null : 'Only a super admin can assign the Super Admin role.';
        }

        if (! $this->canManageAccounts($actor)) {
            return 'You are not allowed to assign roles.';
        }

        if ($this->roleTier($role) > $actor->tier()) {
            return "You cannot grant {$role->display_name}: it is above your own level.";
        }

        if ($actor->isScopedIct() && ! $role->ict_assignable) {
            return "{$role->display_name} can only be assigned by a Global Admin.";
        }

        if ($target !== null && ($denial = $this->locationDenial($role, $this->resolveTarget($target)[1]))) {
            return $denial;
        }

        return $this->escalationDenial($actor, $role, $governed);
    }

    /** Why $actor may not remove $role from $target, or null. */
    public function removalDenial(User $actor, Role $role, User $target, bool $trustScope = false): ?string
    {
        if ($role->name === User::ROLE_EMPLOYEE) {
            return 'The Employee role is implicit and is not removed here.';
        }

        if ($role->name === User::ROLE_SUPER_ADMIN && ! $actor->isSuperAdmin()) {
            return 'Only a super admin can remove the Super Admin role.';
        }

        if ($denial = $this->targetDenial($actor, $target, $trustScope)) {
            return $denial;
        }

        if ($this->roleTier($role) > $actor->tier()) {
            return "You cannot remove {$role->display_name}: it is above your own level.";
        }

        if ($actor->isScopedIct() && ! $role->ict_assignable) {
            return "{$role->display_name} can only be removed by a Global Admin.";
        }

        return null;
    }

    public function canRemoveRole(User $actor, Role $role, User $target): bool
    {
        return $this->removalDenial($actor, $role, $target) === null;
    }

    /**
     * Role ids $actor may change on $target: the ones they could assign, plus the ones the user holds and the actor
     * may remove. Roles outside this set are left exactly as they are when the user is edited.
     *
     * @return list<int>
     */
    public function manageableRoleIdsFor(
        User $actor,
        User $target,
        bool $trustScope = false,
        ?Collection $candidateRoles = null,
        ?array $governed = null,
    ): array {
        if ($this->targetDenial($actor, $target, $trustScope) !== null) {
            return [];
        }

        $assignable = $this->assignableRolesFor($actor, $target, true, $candidateRoles, $governed)->pluck('id');

        $removable = $target->roles
            ->filter(fn (Role $role) => $this->removalDenial($actor, $role, $target, true) === null)
            ->pluck('id');

        return $assignable->merge($removable)->unique()->values()->all();
    }

    // ------------------------------------------------------------------ Role and location

    /** A role's location requirement, or a clear message when the target's location doesn't meet it. */
    public function locationDenial(Role $role, ?Employee $employee): ?string
    {
        $headOfficeOnly = in_array($role->name, self::HEAD_OFFICE_ROLES, true);
        $regionalOnly = in_array($role->name, self::REGIONAL_ROLES, true);

        if (! $headOfficeOnly && ! $regionalOnly) {
            return null;
        }

        if (! $employee) {
            return "{$role->display_name} can only be given to staff with an employee record ("
                .($headOfficeOnly ? 'Head Office' : 'regional or district').').';
        }

        if ($headOfficeOnly && $employee->location_type !== 'HeadOffice') {
            return "{$role->display_name} can only be given to Head Office staff.";
        }

        if ($regionalOnly && $employee->location_type === 'HeadOffice') {
            return "{$role->display_name} can only be given to regional or district staff, not Head Office staff.";
        }

        return null;
    }

    public function roleFitsLocation(Role $role, ?Employee $employee): bool
    {
        return $this->locationDenial($role, $employee) === null;
    }

    // ------------------------------------------------------------------ Role definitions

    public function canCreateRole(User $actor): bool
    {
        return $this->canAdministerRoles($actor);
    }

    /**
     * Change a role's permissions and module access. Global Admin and super_admin only, since roles are shared
     * definitions; a protected role (super_admin, admin) only by a super_admin, so nobody widens their own role;
     * the super_admin and implicit employee roles are locked for everyone.
     */
    public function canEditRolePermissions(User $actor, Role $role): bool
    {
        if (in_array($role->name, [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE], true)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return ! $role->is_protected && $actor->isGlobalAdmin();
    }

    /** Rename or re-describe a custom role (system roles keep their names). */
    public function canEditRoleDetails(User $actor, Role $role): bool
    {
        return ! $role->is_system && $this->canEditRolePermissions($actor, $role);
    }

    /** super_admin only, for a custom role nobody holds. */
    public function canDeleteRole(User $actor, Role $role, ?int $holders = null): bool
    {
        if (! $actor->isSuperAdmin() || $role->is_system || $role->is_protected) {
            return false;
        }

        return ($holders ?? $role->users()->count()) === 0;
    }

    /** Whether the ICT team may hand out the role. Never for the roles that carry a tier of their own. */
    public function canSetIctAssignable(User $actor, Role $role): bool
    {
        return $this->roleTier($role) === User::TIER_STAFF && $this->canEditRolePermissions($actor, $role);
    }

    /**
     * Why $actor may not add these permissions to a role, or null. Anti-escalation: governance permissions can only
     * be added by someone who holds them.
     *
     * @param  iterable<int>  $addedPermissionIds
     */
    public function permissionGrantDenial(User $actor, iterable $addedPermissionIds): ?string
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }

        $missing = Permission::query()
            ->whereIn('id', collect($addedPermissionIds)->all())
            ->whereIn('module', self::GOVERNED_MODULES)
            ->pluck('name')
            ->diff($this->governedPermissionNames($actor));

        return $missing->isEmpty()
            ? null
            : 'You cannot grant permissions you do not hold yourself: '.$missing->take(3)->implode(', ').'.';
    }

    // ------------------------------------------------------------------ Internals

    /** A role carrying governance permissions the actor lacks can't be granted by them. */
    protected function escalationDenial(User $actor, Role $role, ?array $governed = null): ?string
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }

        $governed ??= $this->governedPermissionNames($actor);

        $missing = $role->permissions
            ->whereIn('module', self::GOVERNED_MODULES)
            ->pluck('name')
            ->diff($governed);

        return $missing->isEmpty()
            ? null
            : "You cannot grant {$role->display_name}: it carries permissions you do not hold yourself (".$missing->take(3)->implode(', ').').';
    }

    /** @return list<string> */
    public function governedPermissionNames(User $actor): array
    {
        return Permission::query()
            ->whereIn('module', self::GOVERNED_MODULES)
            ->whereHas('roles.users', fn ($users) => $users->where('users.id', $actor->getKey()))
            ->pluck('name')
            ->all();
    }

    /** @return array{0: ?User, 1: ?Employee} */
    protected function resolveTarget(User|Employee $target): array
    {
        return $target instanceof Employee
            ? [$target->user ?? $target->userByStaffId, $target]
            : [$target, $target->employee ?? $target->employeeByStaffId];
    }

    public function outOfScopeMessage(User $actor): string
    {
        return match ($actor->ictScope()['type'] ?? null) {
            'head_office' => 'You can only manage Head Office users.',
            'region' => 'You can only manage users in your own region.',
            default => 'You cannot manage this account.',
        };
    }
}
