<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors the role -> permission grants of migration 2026_10_18_000002_seed_gwater_module_access, for fresh installs (the
 * migration runs before any role exists there) and for tests that seed roles themselves. The g_water role and super_admin
 * hold every gwater.* permission; nobody else holds any (split duties later by making a second role on the roles screen).
 */
class GwaterRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = Permission::query()->where('module', Permission::MODULE_GWATER)->pluck('id')->all();

        Role::query()->whereIn('name', ['g_water', 'super_admin'])->get()->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds)
        );
    }
}
