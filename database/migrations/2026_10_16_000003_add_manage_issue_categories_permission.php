<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'assets.manage_issue_categories';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::SLUG],
            [
                'display_name' => 'Manage Issue Categories',
                'module' => Permission::MODULE_ASSETS,
                'description' => 'Manage Issue Categories permission for ASSETS module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('name', self::SLUG)->value('id');

        foreach (['super_admin', 'ict_team'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($permissionId && $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::SLUG)->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
