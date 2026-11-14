<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 8a, district scope (design 8.30).
 *
 *   commercial.view_all_districts  sees every district of the user's region; without it a user sees only the district on their
 *                                  employee record. Granted to EVERY role that holds any commercial.view_* permission, so nothing
 *                                  changes for an existing role.
 *   commercial.manage_routes       the route master (pin a route to a district, mark an alias). Commercial Manager and super_admin.
 *
 * and the role "District Commercial Viewer": the management views (dashboard, billing, reading, customer analytics) WITHOUT
 * view_all_districts, WITHOUT upload / resolve / void and WITHOUT any of the four SMS permissions. Mirrored in RoleSeeder,
 * ModuleAccessSeeder, PermissionSeeder and CommercialRolePermissionSeeder for fresh installs and tests.
 */
return new class extends Migration
{
    private const VIEWER_ROLE = 'district_commercial_viewer';

    private const VIEWER_PERMISSIONS = ['commercial.view_dashboard', 'commercial.view_billing', 'commercial.view_reading', 'commercial.view_customer_analytics'];

    public function up(): void
    {
        $now = now();

        foreach (['commercial.view_all_districts', 'commercial.manage_routes'] as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                ['display_name' => $displayName, 'module' => Permission::MODULE_COMMERCIAL, 'description' => $displayName.' permission for COMMERCIAL module', 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $ids = DB::table('permissions')->whereIn('name', ['commercial.view_all_districts', 'commercial.manage_routes'])->pluck('id', 'name');

        // Every role that holds any commercial view permission keeps seeing every district of its region.
        $viewPermissionIds = DB::table('permissions')->where('module', Permission::MODULE_COMMERCIAL)->pluck('name', 'id')
            ->filter(fn (string $name) => str_starts_with($name, 'commercial.view_') && $name !== 'commercial.view_all_districts')->keys()->all();

        // (not the district viewer itself: it is the one role that stays limited, also when this migration is run again)
        $viewerRoleId = DB::table('roles')->where('name', self::VIEWER_ROLE)->value('id');

        foreach (DB::table('role_permissions')->whereIn('permission_id', $viewPermissionIds)->distinct()->pluck('role_id') as $roleId) {
            if ($viewerRoleId !== null && (int) $roleId === (int) $viewerRoleId) {
                continue;
            }

            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids['commercial.view_all_districts']]);
        }

        foreach (['super_admin', 'commercial_manager'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids['commercial.manage_routes']]);
            }
        }

        // The district-limited role.
        DB::table('roles')->updateOrInsert(
            ['name' => self::VIEWER_ROLE],
            ['display_name' => 'District Commercial Viewer', 'description' => 'District Commercial Viewer system role', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        $viewerId = DB::table('roles')->where('name', self::VIEWER_ROLE)->value('id');

        DB::table('module_access')->updateOrInsert(
            ['role_id' => $viewerId, 'module' => Permission::MODULE_COMMERCIAL],
            ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        foreach (DB::table('permissions')->whereIn('name', self::VIEWER_PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $viewerId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $viewerId = DB::table('roles')->where('name', self::VIEWER_ROLE)->value('id');

        if ($viewerId) {
            DB::table('role_permissions')->where('role_id', $viewerId)->delete();
            DB::table('module_access')->where('role_id', $viewerId)->delete();
            DB::table('roles')->where('id', $viewerId)->delete();
        }

        DB::table('permissions')->whereIn('name', ['commercial.view_all_districts', 'commercial.manage_routes'])->delete();
    }
};
