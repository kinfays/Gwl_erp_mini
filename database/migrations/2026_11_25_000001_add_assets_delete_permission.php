<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleting an asset (Assets > Assets / Phones / Network > Delete). Granted to super_admin, ict_team and ict_manager;
 * for the two ICT roles it still only reaches assets of their own region (the write scope), and the service refuses an
 * asset that is assigned, linked to a managed phone or has any history. ict_manager exists only if the ICT Manager
 * migration has run; the grant is skipped for a role that is not there (the seeders cover a fresh install). Idempotent.
 */
return new class extends Migration
{
    private const PERMISSION = 'assets.delete';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION],
            [
                'display_name' => 'Delete Assets',
                'module' => Permission::MODULE_ASSETS,
                'description' => 'Delete Assets permission for ASSETS module',
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
