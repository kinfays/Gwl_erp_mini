<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bulk upload of assets (Assets > Assets > Upload assets). Granted to the roles that can create assets: super_admin,
 * ict_team and ict_manager (which exists only if the ICT Manager migration has run; the grant is skipped otherwise and
 * the seeders cover a fresh install). Idempotent.
 */
return new class extends Migration
{
    private const PERMISSION = 'assets.import';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION],
            [
                'display_name' => 'Import Assets',
                'module' => Permission::MODULE_ASSETS,
                'description' => 'Import Assets permission for ASSETS module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        foreach (['super_admin', 'ict_team', 'ict_manager'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($permissionId && $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
