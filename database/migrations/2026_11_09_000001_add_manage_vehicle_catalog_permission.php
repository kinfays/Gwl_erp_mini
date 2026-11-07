<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeds transport.manage_vehicle_catalog: the Transport > Settings > Vehicle Brands / Vehicle Models screens and the
 * "Add new brand / Add new model" panels on the vehicle form. Granted to super_admin and transport_manager; other roles
 * can be given it through UAC. updateOrInsert/insertOrIgnore keeps a re-run a no-op.
 */
return new class extends Migration
{
    private const SLUG = 'transport.manage_vehicle_catalog';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $displayName = Str::of(self::SLUG)->after('.')->replace('_', ' ')->title()->toString();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::SLUG],
            [
                'display_name' => $displayName,
                'module' => Permission::MODULE_TRANSPORT,
                'description' => $displayName.' permission for TRANSPORT module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', self::SLUG)->value('id');

        foreach (['super_admin', 'transport_manager'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($permissionId && $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', self::SLUG)->value('id');

        if ($permissionId) {
            if (Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            }

            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
