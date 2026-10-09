<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors the role -> permission grants of migration 2026_10_13_000002_seed_regional_blog_module_access, for fresh
 * installs (the migration runs before any role exists there) and for tests that seed roles themselves. Everyone reads
 * their region's blog (module access, see ModuleAccessSeeder); only the PR Officer (and super_admin) writes.
 */
class RegionalBlogRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionId = Permission::query()->where('name', 'blog.manage_posts')->value('id');

        if (! $permissionId) {
            return;
        }

        Role::query()->whereIn('name', ['pr_officer', 'super_admin'])->get()->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permissionId])
        );
    }
}
