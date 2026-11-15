<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8d, step 0.1 (design 8.37): the District Commercial Viewer role (Phase 8a, section 8.30.2) also holds
 * commercial.view_reader_performance.
 *
 * Why: the small-group rule hides every CCA's name from a user without that permission, so a district manager saw their district's
 * totals and routes but not their own CCAs, which is the point of tying a route to a CCA. It stays safe because the screens already
 * show a district user only the routes (and so only the CCA rows) of their own district: the permission widens the NAMES shown, never
 * the districts reached (CommercialScope decides those).
 *
 * Mirrored in CommercialRolePermissionSeeder for fresh installs and tests (a fresh `migrate` runs before any role exists). Safe to
 * run again.
 */
return new class extends Migration
{
    private const ROLE = 'district_commercial_viewer';

    private const PERMISSION = 'commercial.view_reader_performance';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($roleId === null || $permissionId === null) {
            return;   // a fresh install: the seeder grants it once the role exists
        }

        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        }
    }
};
