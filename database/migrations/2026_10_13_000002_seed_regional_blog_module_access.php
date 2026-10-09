<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regional Blog: the Public Relations Officer role, the one permission, and a module_access row for EVERY role (every
 * member of staff reads their region's blog, so access is true for all of them; only blog.manage_posts writes).
 * Idempotent. Database\Seeders\RegionalBlogRolePermissionSeeder repeats the grants for fresh installs and tests.
 */
return new class extends Migration
{
    public const PERMISSION = 'blog.manage_posts';

    public const ROLE = 'pr_officer';

    public function up(): void
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['name' => self::ROLE],
            [
                'display_name' => 'Public Relations Officer',
                'description' => 'Public Relations Officer system role',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION],
            [
                'display_name' => 'Manage Posts',
                'module' => Permission::MODULE_BLOG,
                'description' => 'Manage Posts permission for BLOG module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        foreach (DB::table('roles')->pluck('id') as $roleId) {
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => Permission::MODULE_BLOG],
                ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        foreach (DB::table('roles')->whereIn('name', [self::ROLE, 'super_admin'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('module_access')->where('module', Permission::MODULE_BLOG)->delete();
        DB::table('permissions')->where('module', Permission::MODULE_BLOG)->delete();
        DB::table('roles')->where('name', self::ROLE)->delete();
    }
};
