<?php

namespace App\Services\Uac;

use App\Models\AuditLog;
use App\Models\District;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies role changes to a user, as far as the actor may make them (RoleGrantPolicy decides), and audits each one.
 *
 * Editing a user never rewrites their whole role set: the requested roles are compared with what they hold, and
 * only roles the actor is allowed to manage are added or removed. Everything else — a Global Admin role an ICT user
 * can't touch, the implicit Employee role — stays exactly as it was.
 */
class RoleAssignmentService
{
    public function __construct(protected RoleGrantPolicy $policy) {}

    /**
     * Make $target hold $requestedRoleIds among the roles $actor manages.
     *
     * @param  array<int, int|string>  $requestedRoleIds  the roles the actor wants the user to have
     * @return array{added: Collection<int, Role>, removed: Collection<int, Role>}
     *
     * @throws AuthorizationException when the actor may not manage this user at all
     * @throws ValidationException when a requested role may not be assigned (errors keyed roles.<index>)
     */
    public function sync(User $actor, User $target, array $requestedRoleIds): array
    {
        return DB::transaction(function () use ($actor, $target, $requestedRoleIds) {
            $target = User::query()->lockForUpdate()->with('roles')->findOrFail($target->getKey());

            if ($denial = $this->policy->targetDenial($actor, $target)) {
                throw new AuthorizationException($denial);
            }

            $requested = collect($requestedRoleIds)->map(fn ($id) => (int) $id);
            $roles = Role::query()->with('permissions')->whereIn('id', $requested->all())->get()->keyBy('id');
            $held = $target->roles->keyBy('id');

            $errors = [];
            $added = collect();

            foreach (array_values($requestedRoleIds) as $index => $id) {
                $id = (int) $id;

                if ($held->has($id) || $added->has($id)) {
                    continue;
                }

                $role = $roles->get($id);

                if (! $role) {
                    $errors["roles.$index"] = 'That role does not exist.';
                } elseif ($denial = $this->policy->roleDenial($actor, $role, $target)) {
                    $errors["roles.$index"] = $denial;
                } else {
                    $added->put($id, $role);
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $removed = $held
                ->reject(fn (Role $role) => $requested->contains($role->id))
                ->filter(fn (Role $role) => $this->policy->removalDenial($actor, $role, $target, true) === null);

            if ($added->isEmpty() && $removed->isEmpty()) {
                return ['added' => collect(), 'removed' => collect()];
            }

            $before = $held->pluck('name')->sort()->values()->all();
            $after = $held->except($removed->keys()->all())->union($added)->pluck('name')->sort()->values()->all();

            $target->roles()->attach($added->keys()->all());
            $target->roles()->detach($removed->keys()->all());

            foreach ($added as $role) {
                $this->audit('role_assigned', $actor, $target, $role, $before, $after);
            }

            foreach ($removed as $role) {
                $this->audit('role_removed', $actor, $target, $role, $before, $after);
            }

            return ['added' => $added->values(), 'removed' => $removed->values()];
        });
    }

    /**
     * An employee has just been moved out of Head Office (Employee::saving derives location_type from the district, so
     * this fires for every path that saves the employee: the staff form, an import, renaming a district, a script).
     * Every Head Office-only role they hold — Global Admin, Head Office HR, Chief Manager
     * (RoleGrantPolicy::HEAD_OFFICE_ROLES, the same list the assignment rule uses) — is removed, audited and announced.
     * Runs in the caller's transaction when there is one, and in its own otherwise, so the role removal and its audit
     * row are never half done.
     */
    public function stripHeadOfficeRolesOnTransfer(Employee $employee): void
    {
        $oldType = $employee->getOriginal('location_type');

        if ($oldType !== 'HeadOffice' || $employee->location_type === 'HeadOffice') {
            return;
        }

        $headOfficeRoles = Role::query()->whereIn('name', RoleGrantPolicy::HEAD_OFFICE_ROLES)->get()->keyBy('id');

        if ($headOfficeRoles->isEmpty()) {
            return;
        }

        $actor = Auth::user();

        $holders = User::query()
            ->where(fn ($users) => $users->where('employee_id', $employee->id)->orWhere('staff_id', $employee->staff_id))
            ->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $headOfficeRoles->keys()->all()))
            ->with('roles')
            ->get();

        foreach ($holders as $holder) {
            $removed = $holder->roles->whereIn('id', $headOfficeRoles->keys()->all())->values();

            DB::transaction(function () use ($holder, $removed, $employee, $actor, $oldType) {
                $before = $holder->roles->pluck('name')->sort()->values()->all();
                $holder->roles()->detach($removed->pluck('id')->all());
                $after = array_values(array_diff($before, $removed->pluck('name')->all()));

                AuditLog::record(
                    'head_office_roles_removed_on_transfer',
                    'uac.users',
                    'users',
                    $holder->id,
                    ['roles' => $before, 'location_type' => $oldType, 'district' => $this->districtName($employee->getOriginal('district_id'))],
                    ['roles' => $after, 'location_type' => $employee->location_type, 'district' => $this->districtName($employee->district_id)],
                    [
                        'roles' => $removed->pluck('name')->all(),
                        'reason' => 'These roles are for Head Office staff only.',
                        'target_staff_id' => $employee->staff_id,
                        'actor_id' => $actor?->id,
                        'actor_name' => $actor?->full_name,
                        'target_scope' => $this->targetScope($employee),
                    ]
                );
            });

            $this->announceTransfer($holder, $employee, $actor, $removed->pluck('display_name')->all());
        }
    }
    protected function audit(string $action, User $actor, User $target, Role $role, array $before, array $after): void
    {
        // Assigning or removing super_admin is logged apart from the rest so it never appears in the role/access
        // changes shown to non-super viewers (AuditLog::ROLE_ACCESS_ACTIONS).
        if ($role->name === User::ROLE_SUPER_ADMIN) {
            $action = 'super_admin_'.$action;
        }

        AuditLog::record(
            $action,
            'uac.users',
            'users',
            $target->id,
            ['roles' => $before],
            ['roles' => $after],
            [
                'role' => $role->name,
                'role_display_name' => $role->display_name,
                'target_staff_id' => $target->staff_id,
                'target_scope' => $this->targetScope($target->employee ?? $target->employeeByStaffId),
                'actor_id' => $actor->id,
            ]
        );
    }

    /** @return array{location_type: ?string, region_id: ?int, region: ?string, district: ?string} */
    protected function targetScope(?Employee $employee): array
    {
        $employee?->loadMissing(['region', 'district']);

        return [
            'location_type' => $employee?->location_type,
            'region_id' => $employee?->region_id,
            'region' => $employee?->region?->region_name,
            'district' => $employee?->district?->district_name,
        ];
    }

    protected function districtName(mixed $districtId): ?string
    {
        return $districtId ? District::query()->whereKey($districtId)->value('district_name') : null;
    }

    /**
     * Tell the person, whoever moved them and the Global Admins, so the removal is never silent.
     *
     * @param  list<string>  $roleNames  display names of the roles that were removed
     */
    protected function announceTransfer(User $holder, Employee $employee, ?User $actor, array $roleNames): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $district = $this->districtName($employee->district_id) ?? 'another location';
        $roles = implode(', ', $roleNames);
        $message = "{$employee->full_name} was moved out of Head Office (to {$district}), so these Head Office roles were removed: {$roles}.";

        $recipients = User::query()
            ->active()
            ->whereHas('roles', fn ($roles) => $roles->where('name', User::ROLE_ADMIN))
            ->whereKeyNot($holder->getKey())
            ->get()
            ->when($actor && ! $actor->is($holder), fn (Collection $users) => $users->push($actor))
            ->unique('id');

        foreach ($recipients as $recipient) {
            $this->notify($recipient, new GeneralDatabaseNotification(
                'Head Office roles removed',
                $message,
                route('uac.users'),
                'uac',
                ['type' => 'head_office_roles_removed', 'user_id' => $holder->id]
            ));
        }

        $this->notify($holder, new GeneralDatabaseNotification(
            'Head Office roles removed',
            "You were moved out of Head Office, so these roles were removed: {$roles}. They can be given back once you are at Head Office again.",
            null,
            'uac',
            ['type' => 'head_office_roles_removed', 'user_id' => $holder->id]
        ));
    }

    protected function notify(User $recipient, GeneralDatabaseNotification $notification): void
    {
        try {
            $recipient->notify($notification);
        } catch (Throwable $e) {
            Log::error('UAC notification failed: '.$e->getMessage(), ['user_id' => $recipient->id, 'exception' => $e]);
        }
    }
}
