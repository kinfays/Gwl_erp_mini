<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assets > Racks. `assets.view_racks` reads the rack list and a rack's detail; `assets.manage_racks` creates, edits and
 * deletes racks. Both go to super_admin, ict_team and ict_manager, like assets.create / assets.edit: racks sit at a
 * location, so they are written in the person's own region (the same write scope as the assets in them), unlike the
 * shared manufacturer / model catalogues that only super_admin edits. `admin` holds only MDM inside Assets, so it gets
 * neither. Export of the rack list reuses assets.export_inventory. ict_manager exists only after the ICT Manager
 * migration; a missing role is skipped (the seeders cover a fresh install). Idempotent.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'assets.view_racks' => 'View Racks',
        'assets.manage_racks' => 'Manage Racks',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $slug => $displayName) {
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

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        foreach (['super_admin', 'ict_team', 'ict_manager'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
