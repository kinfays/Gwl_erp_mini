<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Permissions for the Android Enterprise (MDM) screens, all inside the existing `assets` module.
 *
 *   assets.mdm_view            see devices, the dashboard and policies
 *   assets.mdm_manage_policies create / edit / publish policies
 *   assets.mdm_enroll          generate enrollment QR codes
 *   assets.mdm_command         lock / reboot / reset passcode / lost mode
 *   assets.mdm_wipe            wipe (separate and more restricted)
 *
 * Defaults: ict_team gets view + enroll + command; admin and super_admin get everything.
 * admin also gets Assets module access here — until now only super_admin / ict_team could open the module,
 * so an admin holding these permissions would still have been redirected away (see docs/assets/mdm.md).
 * updateOrInsert / insertOrIgnore keep a re-run a no-op. On a fresh database the roles don't exist yet when
 * this runs, so the same grants are mirrored in PermissionSeeder, AssetsRolePermissionSeeder and ModuleAccessSeeder.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'assets.mdm_view',
        'assets.mdm_manage_policies',
        'assets.mdm_enroll',
        'assets.mdm_command',
        'assets.mdm_wipe',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_ASSETS,
                    'description' => $displayName.' permission for ASSETS module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id', 'name');

        $grants = [
            'super_admin' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'ict_team' => ['assets.mdm_view', 'assets.mdm_enroll', 'assets.mdm_command'],
        ];

        foreach ($grants as $roleName => $slugs) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($slugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;

                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($adminRoleId) {
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $adminRoleId, 'module' => Permission::MODULE_ASSETS],
                ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($adminRoleId) {
            DB::table('module_access')
                ->where('role_id', $adminRoleId)
                ->where('module', Permission::MODULE_ASSETS)
                ->update(['can_access' => false, 'updated_at' => now()]);
        }
    }
};
