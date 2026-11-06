<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Excel / PDF export of the Maintenance log. Granted like the other Assets export permissions. */
return new class extends Migration
{
    private const PERMISSION = 'assets.export_maintenance';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION],
            [
                'display_name' => 'Export Maintenance Log',
                'module' => Permission::MODULE_ASSETS,
                'description' => 'Export Maintenance Log permission for ASSETS module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        foreach (['super_admin', 'ict_team'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($roleId && $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
