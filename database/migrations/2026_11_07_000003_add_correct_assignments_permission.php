<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marking an old assignment-history row as a correction after the fact. Held by super_admin only (which bypasses every
 * permission check anyway); the Assets routes are closed to Global Admin, so no other role is granted it. ICT can be
 * given it later from the roles screen if the business wants that.
 */
return new class extends Migration
{
    private const PERMISSION = 'assets.correct_assignments';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION],
            [
                'display_name' => 'Correct Assignment History',
                'module' => Permission::MODULE_ASSETS,
                'description' => 'Mark a past assignment-history row as a correction on an asset',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
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
