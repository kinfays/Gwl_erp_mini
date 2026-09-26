<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds assets.manage_manufacturers for the Assets > Settings > Manufacturers
 * screen. Granted to super_admin only, mirroring assets.manage_models; other
 * roles get it through UAC. updateOrInsert/insertOrIgnore keeps a re-run a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $slug = 'assets.manage_manufacturers';
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

        $permissionId = DB::table('permissions')->where('name', $slug)->value('id');
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($permissionId && $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'assets.manage_manufacturers')->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
