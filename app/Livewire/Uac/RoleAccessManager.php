<?php

namespace App\Livewire\Uac;

use Livewire\Component;
use App\Models\Role;
use App\Models\Permission;
use App\Models\ModuleAccess;
use App\Support\Audit;

class RoleAccessManager extends Component
{
    public array $roles = [];
    public ?int $selectedRoleId = null;

    // UI state
    public array $permissionsByModule = []; // ['leave' => [..Permission..], ...]
    public array $selectedPermissionIds = []; // [1,2,3]
    public array $moduleAccess = []; // ['leave' => true, 'uac' => false...]

    public string $message = '';

    public array $modules = ['uac', 'leave', 'staff', 'letters', 'visitors'];
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
    ->where('name', '!=', 'super_admin')  // ✅ hide from list
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
        'newRoleSlug.regex' => 'Slug must be lowercase letters, numbers, or underscores only (e.g. finance_manager).'
    ]);

    $role = \App\Models\Role::create([
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
        ->where('name', '!=', 'super_admin')
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
        $role = Role::with(['permissions', 'moduleAccesses'])->findOrFail($roleId);

        $this->selectedPermissionIds = $role->permissions->pluck('id')->toArray();

        // build module access map
        $this->moduleAccess = [];
        foreach ($this->modules as $module) {
            $record = $role->moduleAccesses->firstWhere('module', $module);
            $this->moduleAccess[$module] = $record ? (bool) $record->can_access : false;
        }

        $this->message = '';
    }

    public function save(): void
    {
        $user = auth()->user();

        // Only super_admin can save changes
        if (! $user || ! $user->hasRoles('super_admin')) {
            abort(403, 'Only Super Admin can modify roles.');
        }

        if (! $this->selectedRoleId) {
            return;
        }

        $role = Role::with(['permissions', 'moduleAccesses'])->findOrFail($this->selectedRoleId);

        // Lock system roles like super_admin and employee
        if ($role->is_system && in_array($role->name, ['super_admin', 'employee'], true)) {
            abort(403, 'This role is locked.');
        }

        $oldPermissions = $role->permissions->pluck('name')->toArray();
        $oldModules = $role->moduleAccesses->pluck('can_access', 'module')->toArray();

        // Sync permissions
        $role->permissions()->sync($this->selectedPermissionIds);

   // Upsert module access
    /* 
     {{--   foreach ($this->modules as $module) {
            ModuleAccess::updateOrCreate(
                ['role_id' => $role->id, 'module' => $module],
                ['can_access' => (bool) ($this->moduleAccess[$module] ?? false)]
            );
        } */

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

    $role = Role::findOrFail($this->selectedRoleId);

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

    $role = Role::findOrFail($this->editRoleId);

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

    $role = Role::withCount('users')->findOrFail($this->selectedRoleId);

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

    $role = Role::findOrFail($this->deleteRoleId);

    \DB::transaction(function () use ($role) {
        $role->permissions()->detach();
        $role->users()->detach();
        \App\Models\ModuleAccess::where('role_id', $role->id)->delete();
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
        $selectedRole = $this->selectedRoleId ? Role::find($this->selectedRoleId) : null;

        $locked = $selectedRole
            ? ($selectedRole->is_system && in_array($selectedRole->name, ['super_admin', 'employee'], true))
            : false;

        $canEdit = auth()->check() && auth()->user()->hasRoles('super_admin') && ! $locked;

        return view('components.uac.role-access-manager', [
            'locked' => $locked,
            'canEdit' => $canEdit,
            'selectedRole' => $selectedRole,
        ]);
    }
}