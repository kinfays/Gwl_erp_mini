<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissions = [
            'staff.manage_regions' => 'Manage Regions',
            'staff.manage_job_titles' => 'Manage Job Titles',
        ];

        foreach ($permissions as $name => $displayName) {
            Permission::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_STAFF,
                    'description' => $displayName.' permission for STAFF module',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $permissionIds = Permission::query()
            ->whereIn('name', array_keys($permissions))
            ->pluck('id')
            ->all();

        Role::query()
            ->whereIn('name', ['super_admin', 'admin', 'hr_headoffice', 'hr_region'])
            ->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        $permissionIds = Permission::query()
            ->whereIn('name', ['staff.manage_regions', 'staff.manage_job_titles'])
            ->pluck('id')
            ->all();

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->whereIn('permissions.id', $permissionIds))
            ->get()
            ->each(fn (Role $role) => $role->permissions()->detach($permissionIds));

        Permission::query()
            ->whereIn('id', $permissionIds)
            ->delete();
    }
};
