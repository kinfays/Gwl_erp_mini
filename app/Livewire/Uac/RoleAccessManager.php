<?php

namespace App\Livewire\Uac;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Livewire\Component;

class RoleAccessManager extends Component
{
    public array $roles = [];

    public ?int $selectedRoleId = null;

    // UI state
    public array $permissionsByModule = []; // ['leave' => [..Permission..], ...]

    public array $selectedPermissionIds = []; // [1,2,3]

    public array $moduleAccess = []; // ['leave' => true, 'uac' => false...]

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

        $this->roles = Role::query()
                   ->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])
                   ->orderBy('display_name')
                   ->get()
                   ->map(fn ($r) => [
                       'id' => $r->id,
                       'name' => $r->name,
                       'display_name' => $r->display_name,
                       'is_system' => (bool) $r->is_system,
                   ])->toArray();

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
        $user = auth()->user();

        // ✅ admin and super_admin can create roles
        if (! $user || ! $user->hasRoles('admin', 'super_admin')) {
            abort(403, 'Only Admin or Super Admin can create roles.');
        }

        $this->validate([
            'newRoleSlug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:roles,name'],
            'newRoleDisplayName' => ['required', 'string', 'max:100'],
            'newRoleDescription' => ['nullable', 'string', 'max:255'],
        ], [
            'newRoleSlug.regex' => 'Slug must be lowercase letters, numbers, or underscores only (e.g. finance_manager).',
        ]);

        $role = Role::create([
            'name' => $this->newRoleSlug,
            'display_name' => $this->newRoleDisplayName,
            'description' => $this->newRoleDescription,
            'is_system' => false,
        ]);

        // Create default module_access rows (all false by default)
        foreach ($this->modules as $module) {
            ModuleAccess::updateOrCreate(
                ['role_id' => $role->id, 'module' => $module],
                ['can_access' => false]
            );
        }

        // Refresh roles list (still hiding super_admin)
        $this->roles = Role::query()
            ->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])
            ->orderBy('display_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'is_system' => (bool) $r->is_system,
            ])->toArray();

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
            ->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])
            ->findOrFail($roleId);

        $this->selectedPermissionIds = $role->permissions->pluck('id')->toArray();

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

    public function getCanEditProperty(): bool
    {
        $selectedRole = $this->selectedRoleId
            ? Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->find($this->selectedRoleId)
            : null;

        return auth()->check() && $this->canEditRole(auth()->user(), $selectedRole);
    }

    public function save(): void
    {
        $user = auth()->user();

        if (! $this->selectedRoleId) {
            return;
        }

        $role = Role::with(['permissions', 'moduleAccesses'])
            ->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])
            ->findOrFail($this->selectedRoleId);

        if (! $user || ! $this->canEditRole($user, $role)) {
            abort(403, 'You cannot modify this role.');
        }

        $oldPermissions = $role->permissions->pluck('name')->toArray();
        $oldModules = $role->moduleAccesses->pluck('can_access', 'module')->toArray();

        $role->permissions()->sync($this->selectedPermissionIds);

        foreach ($this->modules as $module) {
            ModuleAccess::updateOrCreate(
                ['role_id' => $role->id, 'module' => $module],
                ['can_access' => (bool) ($this->moduleAccess[$module] ?? false)]
            );
        }

        $role->refresh()->load(['permissions', 'moduleAccesses']);

        Audit::log(
            action: 'update_role_access',
            module: 'uac.roles',
            targetType: 'roles',
            targetId: $role->id,
            metadata: [
                'role' => $role->name,
                'old_permissions' => $oldPermissions,
                'new_permissions' => $role->permissions->pluck('name')->toArray(),
                'old_module_access' => $oldModules,
                'new_module_access' => $role->moduleAccesses->pluck('can_access', 'module')->toArray(),
            ]
        );

        $this->message = 'Changes saved successfully.';
    }

    public function openEditRole(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRoles('admin', 'super_admin')) {
            abort(403);
        }

        $role = Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->findOrFail($this->selectedRoleId);

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
        $user = auth()->user();
        if (! $user || ! $user->hasRoles('admin', 'super_admin')) {
            abort(403);
        }

        $role = Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->findOrFail($this->editRoleId);

        if ($role->is_system) {
            abort(403);
        }

        $this->validate([
            'editRoleDisplayName' => 'required|string|max:100',
            'editRoleDescription' => 'nullable|string|max:255',
        ]);

        $role->update([
            'display_name' => $this->editRoleDisplayName,
            'description' => $this->editRoleDescription,
        ]);

        Audit::log(
            action: 'update_role',
            module: 'uac.roles',
            targetType: 'roles',
            targetId: $role->id,
            metadata: ['role' => $role->name]
        );

        $this->showEditRole = false;
        $this->message = 'Role updated successfully.';
    }

    public function openDeleteRole(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRoles('super_admin')) {
            abort(403);
        }

        $role = Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->withCount('users')->findOrFail($this->selectedRoleId);

        if ($role->is_system) {
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
        $user = auth()->user();
        if (! $user || ! $user->hasRoles('super_admin')) {
            abort(403);
        }

        if (trim($this->deleteConfirmText) !== 'DELETE') {
            $this->addError('deleteConfirmText', 'Type DELETE to confirm.');

            return;
        }

        $role = Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->findOrFail($this->deleteRoleId);

        \DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->users()->detach();
            ModuleAccess::where('role_id', $role->id)->delete();
            $role->delete();
        });

        Audit::log(
            action: 'delete_role',
            module: 'uac.roles',
            targetType: 'roles',
            targetId: $this->deleteRoleId
        );

        $this->showDeleteRole = false;
        $this->selectedRoleId = null;
        $this->message = 'Role deleted successfully.';
    }

    public function render()
    {
        $selectedRole = $this->selectedRoleId
            ? Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->find($this->selectedRoleId)
            : null;

        $locked = $selectedRole ? $this->isLockedRole($selectedRole) : false;
        $canEdit = auth()->check() && $this->canEditRole(auth()->user(), $selectedRole);

        return view('components.uac.role-access-manager', [
            'locked' => $locked,
            'canEdit' => $canEdit,
            'selectedRole' => $selectedRole,
        ]);
    }

    protected function isLockedRole(?Role $role): bool
    {
        return $role
            && $role->is_system
            && in_array($role->name, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_EMPLOYEE], true);
    }

    protected function canEditRole(?User $user, ?Role $role): bool
    {
        if (! $user || ! $role || $this->isLockedRole($role)) {
            return false;
        }

        if ($role->name === User::ROLE_ICT_TEAM) {
            return $user->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
        }

        return $user->hasRoles(User::ROLE_SUPER_ADMIN);
    }
}
