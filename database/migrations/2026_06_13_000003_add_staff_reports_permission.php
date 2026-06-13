<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'staff.view_reports'],
            [
                'display_name' => 'View Reports',
                'module' => Permission::MODULE_STAFF,
                'description' => 'View Reports permission for STAFF module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')
            ->where('name', 'staff.view_reports')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('roles')
            ->whereIn('name', ['super_admin', 'hr_headoffice', 'hr_region'])
            ->pluck('id')
            ->each(fn ($roleId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]));
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'staff.view_reports')
            ->value('id');

        if ($permissionId) {
            DB::table('role_permissions')
                ->where('permission_id', $permissionId)
                ->delete();
        }

        DB::table('permissions')
            ->where('name', 'staff.view_reports')
            ->delete();
    }
};
