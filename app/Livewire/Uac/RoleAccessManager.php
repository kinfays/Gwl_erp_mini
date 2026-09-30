<?php

namespace App\Livewire\Uac;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Uac\RoleGrantPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The roles and permissions screen. Global Admin and super_admin only; every action is re-checked against
 * RoleGrantPolicy on the server (the screen's buttons are only a convenience).
 */
class RoleAccessManager extends Component
{
    use EnforcesModuleAccess;

    public array $roles = [];

    public ?int $selectedRoleId = null;

    // UI state
    public array $permissionsByModule = []; // ['leave' => [..Permission..], ...]

    public array $selectedPermissionIds = []; // [1,2,3]

    public array $moduleAccess = []; // ['leave' => true, 'uac' => false...]

    public bool $ictAssignable = false;

    public string $message = '';

    public array $modules = Permission::MODULES;

    public bool $showCreateRole = false;

    public string $newRoleSlug = '';

    public string $newRoleDisplayName = '';

    public string $newRoleDescription = '';

    public bool $showEditRole = false;

    public bool $showDeleteRole = false;

    public ?int $editRoleId = null;

    public string $editRoleDisplayName = '';

    public string $editRoleDescription = '';

    public ?int $deleteRoleId = null;

    public string $deleteConfirmText = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('uac');
        abort_unless($this->policy()->canAdministerRoles($this->actor()), 403, 'Only a Global Admin or Super Admin can manage roles.');

        $this->loadRoles();

        $this->permissionsByModule = Permission::query()
            ->orderBy('module')
            ->orderBy('display_name')
            ->get()
            ->groupBy('module')
            ->map(fn ($items) => $items->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name,
            ])->toArray())
            ->toArray();

        // default select first role
        if (! empty($this->roles)) {
            $this->selectRole($this->roles[0]['id']);
        }
    }

    public function createRole(): void
    {
        $actor = $this->actor();

        abort_unless($this->policy()->canCreateRole($actor), 403, 'Only a Global Admin or Super Admin can create roles.');

        $this->validate([
            'newRoleSlug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:roles,name'],
            'newRoleDisplayName' => ['required', 'string', 'max:100'],
            'newRoleDescription' => ['nullable', 'string', 'max:255'],
        ], [
            'newRoleSlug.regex' => 'Slug must be lowercase letters, numbers, or underscores only (e.g. finance_manager).',
        ]);

        $role = DB::transaction(function () use ($actor) {
            $role = Role::create([
                'name' => $this->newRoleSlug,
                'display_name' => $this->newRoleDisplayName,
                'description' => $this->newRoleDescription,
                'is_system' => false,
                'ict_assignable' => false,
                'is_protected' => false,
            ]);

            // Create default module_access rows (all false by default)
            foreach ($this->modules as $module) {
                ModuleAccess::updateOrCreate(
                    ['role_id' => $role->id, 'module' => $module],
                    ['can_access' => false]
                );
            }

            AuditLog::record(
                'create_role',
                'uac.roles',
                'roles',
                $role->id,
                null,
                $this->roleSnapshot($role),
                ['role' => $role->name, 'actor_id' => $actor->id]
            );

            return $role;
        });

        $this->loadRoles();

        // Select newly created role
        $this->selectRole($role->id);

        // Close modal + reset inputs
        $this->showCreateRole = false;
        $this->newRoleSlug = '';
        $this->newRoleDisplayName = '';
        $this->newRoleDescription = '';

        $this->message = 'Role created successfully.';
    }

    public function selectRole(int $roleId): void
    {
        $this->selectedRoleId = $roleId;
        $role = Role::with(['permissions', 'moduleAccesses'])
            ->visibleTo($this->actor())
            ->findOrFail($roleId);

        $this->selectedPermissionIds = $role->permissions->pluck('id')->toArray();
        $this->ictAssignable = (bool) $role->ict_assignable;

        // build module access map
        $this->moduleAccess = [];
        foreach ($this->modules as $module) {
            $record = $role->moduleAccesses->firstWhere('module', $module);
            $this->moduleAccess[$module] = $record ? (bool) $record->can_access : false;
        }

        $this->message = '';
    }

    public function toggleModuleAccess(string $module): void
    {
        if (! $this->getCanEditProperty()) {
            return;
        }

        $this->moduleAccess[$module] = ! ($this->moduleAccess[$module] ?? false);
    }

    public function togglePermission(int $permissionId): void
    {
        if (! $this->getCanEditProperty()) {
            return;
        }

        $key = array_search($permissionId, $this->selectedPermissionIds);
        if ($key !== false) {
            unset($this->selectedPermissionIds[$key]);
            $this->selectedPermissionIds = array_values($this->selectedPermissionIds); // re-index
        } else {
            $this->selectedPermissionIds[] = $permissionId;
        }
    }

    public function toggleIctAssignable(): void
    {
        $role = $this->selectedRole();

        if (! $role || ! $this->policy()->canSetIctAssignable($this->actor(), $role)) {
            return;
        }

        $this->ictAssignable = ! $this->ictAssignable;
    }

    public function getCanEditProperty(): bool
    {
        $role = $this->selectedRole();

        return $role !== null && $this->policy()->canEditRolePermissions($this->actor(), $role);
    }

    public function save(): void
    {
        $actor = $this->actor();

        if (! $this->selectedRoleId) {
            return;
        }

        $role = Role::with(['permissions', 'moduleAccesses'])
            ->visibleTo($actor)
            ->findOrFail($this->selectedRoleId);

        abort_unless($this->policy()->canEditRolePermissions($actor, $role), 403, 'You cannot modify this role.');

        $newPermissionIds = collect($this->selectedPermissionIds)->map(fn ($id) => (int) $id)->unique()->values();
        $added = $newPermissionIds->diff($role->permissions->pluck('id'));

        // Anti-escalation: you can't put governance permissions into a role that you don't hold yourself.
        if ($denial = $this->policy()->permissionGrantDenial($actor, $added)) {
            throw ValidationException::withMessages(['selectedPermissionIds' => $denial]);
        }

        $ictAssignable = $this->policy()->canSetIctAssignable($actor, $role)
            ? $this->ictAssignable
            : (bool) $role->ict_assignable;

        $oldPermissions = $role->permissions->pluck('name')->sort()->values()->toArray();
        $oldModules = $role->moduleAccesses->pluck('can_access', 'module')->toArray();
        $oldIctAssignable = (bool) $role->ict_assignable;

        DB::transaction(function () use ($role, $newPermissionIds, $ictAssignable, $actor, $oldPermissions, $oldModules, $oldIctAssignable) {
            $role->permissions()->sync($newPermissionIds->all());

            foreach ($this->modules as $module) {
                ModuleAccess::updateOrCreate(
                    ['role_id' => $role->id, 'module' => $module],
                    ['can_access' => (bool) ($this->moduleAccess[$module] ?? false)]
                );
            }

            if ($ictAssignable !== $oldIctAssignable) {
                $role->update(['ict_assignable' => $ictAssignable]);
            }

            $role->refresh()->load(['permissions', 'moduleAccesses']);

            AuditLog::record(
                'update_role_access',
                'uac.roles',
                'roles',
                $role->id,
                [
                    'permissions' => $oldPermissions,
                    'module_access' => $oldModules,
                    'ict_assignable' => $oldIctAssignable,
                ],
                [
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->toArray(),
                    'module_access' => $role->moduleAccesses->pluck('can_access', 'module')->toArray(),
                    'ict_assignable' => (bool) $role->ict_assignable,
                ],
                [
                    'role' => $role->name,
                    'actor_id' => $actor->id,
                    // Kept under the older keys too: the audit log viewer and earlier rows use them.
                    'old_permissions' => $oldPermissions,
                    'new_permissions' => $role->permissions->pluck('name')->sort()->values()->toArray(),
                    'old_module_access' => $oldModules,
                    'new_module_access' => $role->moduleAccesses->pluck('can_access', 'module')->toArray(),
                ]
            );
        });

        $this->message = 'Changes saved successfully.';
    }

    public function openEditRole(): void
    {
        $role = $this->selectedRole();

        abort_unless($role && $this->policy()->canEditRolePermissions($this->actor(), $role), 403);

        if ($role->is_system) {
            $this->message = 'System roles cannot be edited.';

            return;
        }

        $this->editRoleId = $role->id;
        $this->editRoleDisplayName = $role->display_name;
        $this->editRoleDescription = $role->description ?? '';

        $this->showEditRole = true;
    }

    public function updateRole(): void
    {
        $actor = $this->actor();
        $role = Role::query()->visibleTo($actor)->findOrFail($this->editRoleId);

        abort_unless($this->policy()->canEditRoleDetails($actor, $role), 403);

        $this->validate([
            'editRoleDisplayName' => 'required|string|max:100',
            'editRoleDescription' => 'nullable|string|max:255',
        ]);

        $old = ['display_name' => $role->display_name, 'description' => $role->description];

        $role->update([
            'display_name' => $this->editRoleDisplayName,
            'description' => $this->editRoleDescription,
        ]);

        AuditLog::record(
            'update_role',
            'uac.roles',
            'roles',
            $role->id,
            $old,
            ['display_name' => $role->display_name, 'description' => $role->description],
            ['role' => $role->name, 'actor_id' => $actor->id]
        );

        $this->loadRoles();
        $this->showEditRole = false;
        $this->message = 'Role updated successfully.';
    }

    public function openDeleteRole(): void
    {
        $actor = $this->actor();

        abort_unless($actor->isSuperAdmin(), 403);

        $role = Role::query()->visibleTo($actor)->withCount('users')->findOrFail($this->selectedRoleId);

        if ($role->is_system || $role->is_protected) {
            $this->message = 'System roles cannot be deleted.';

            return;
        }

        if ($role->users_count > 0) {
            $this->message = 'Remove this role from users before deleting.';

            return;
        }

        $this->deleteRoleId = $role->id;
        $this->deleteConfirmText = '';
        $this->showDeleteRole = true;
    }

    public function deleteRole(): void
    {
        $actor = $this->actor();

        if (trim($this->deleteConfirmText) !== 'DELETE') {
            $this->addError('deleteConfirmText', 'Type DELETE to confirm.');

            return;
        }

        $role = Role::query()->visibleTo($actor)->with(['permissions', 'moduleAccesses'])->findOrFail($this->deleteRoleId);

        abort_unless($this->policy()->canDeleteRole($actor, $role), 403);

        $old = $this->roleSnapshot($role);

        DB::transaction(function () use ($role, $old, $actor) {
            $role->permissions()->detach();
            $role->users()->detach();
            ModuleAccess::where('role_id', $role->id)->delete();
            $role->delete();

            AuditLog::record(
                'delete_role',
                'uac.roles',
                'roles',
                $role->id,
                $old,
                null,
                ['role' => $role->name, 'actor_id' => $actor->id]
            );
        });

        $this->loadRoles();
        $this->showDeleteRole = false;
        $this->selectedRoleId = null;
        $this->message = 'Role deleted successfully.';
    }

    public function render()
    {
        $selectedRole = $this->selectedRole();
        $actor = $this->actor();

        $canEdit = $selectedRole !== null && $this->policy()->canEditRolePermissions($actor, $selectedRole);

        return view('components.uac.role-access-manager', [
            'locked' => $selectedRole !== null && ! $canEdit,
            'lockReason' => $selectedRole ? $this->lockReason($selectedRole) : null,
            'canEdit' => $canEdit,
            'canCreate' => $this->policy()->canCreateRole($actor),
            'canEditDetails' => $selectedRole !== null && $this->policy()->canEditRoleDetails($actor, $selectedRole),
            'canDelete' => $selectedRole !== null && $this->policy()->canDeleteRole($actor, $selectedRole),
            'canSetIctAssignable' => $selectedRole !== null && $this->policy()->canSetIctAssignable($actor, $selectedRole),
            'selectedRole' => $selectedRole,
        ]);
    }

    protected function policy(): RoleGrantPolicy
    {
        return app(RoleGrantPolicy::class);
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user, 403, 'Unauthorized.');

        return $user;
    }

    protected function selectedRole(): ?Role
    {
        return $this->selectedRoleId
            ? Role::query()->visibleTo($this->actor())->find($this->selectedRoleId)
            : null;
    }

    protected function loadRoles(): void
    {
        $this->roles = Role::query()
            ->visibleTo($this->actor())
            ->orderBy('display_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'is_system' => (bool) $r->is_system,
            ])->toArray();
    }

    /** @return array<string, mixed> */
    protected function roleSnapshot(Role $role): array
    {
        $role->loadMissing(['permissions', 'moduleAccesses']);

        return [
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'ict_assignable' => (bool) $role->ict_assignable,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'module_access' => $role->moduleAccesses->pluck('can_access', 'module')->all(),
        ];
    }

    protected function lockReason(Role $role): ?string
    {
        $actor = $this->actor();

        if ($this->policy()->canEditRolePermissions($actor, $role)) {
            return null;
        }

        return match (true) {
            in_array($role->name, [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE], true) => 'This role is locked and cannot be modified.',
            (bool) $role->is_protected => 'This is a protected role: only a Super Admin can change its permissions.',
            default => 'You cannot modify this role.',
        };
    }
}
