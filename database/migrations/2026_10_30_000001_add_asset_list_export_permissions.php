<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Excel / PDF export of the Assets, Phones, Network and Issue Reports lists. Granted like assets.export_audits. */
return new class extends Migration
{
    private const PERMISSIONS = [
        'assets.export_inventory' => 'Export Inventory Lists',
        'assets.export_reports' => 'Export Issue Reports',
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

        foreach (['super_admin', 'ict_team'] as $roleName) {
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
