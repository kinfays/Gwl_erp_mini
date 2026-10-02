<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. The Compulsory Leave page (`leave.manage_compulsory`) is for Head Office HR and Global Admin (slug `admin`);
 *    super_admin bypasses. Regional HR have no access. The grants are made here so a fresh `migrate` and a `migrate` on
 *    a running database end up the same (LeaveApprovalRolePermissionSeeder mirrors them for `migrate:fresh --seed`).
 *    Existing grants to other roles are left alone: the route also checks the role, so they don't open the page.
 *
 * 2. "Staff Leave Reports" is now "Staff Reports": the permission keeps its key (`staff.view_reports`) and gets the new label.
 */
return new class extends Migration
{
    private const COMPULSORY = 'leave.manage_compulsory';

    private const REPORTS = 'staff.view_reports';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::COMPULSORY],
            [
                'display_name' => 'Manage Compulsory',
                'module' => Permission::MODULE_LEAVE,
                'description' => 'Manage Compulsory permission for LEAVE module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('name', self::COMPULSORY)->value('id');

        foreach (DB::table('roles')->whereIn('name', ['super_admin', 'admin', 'hr_headoffice'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        DB::table('permissions')->where('name', self::REPORTS)->update([
            'display_name' => 'View Staff Reports',
            'description' => 'View Staff Reports permission for STAFF module',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', self::REPORTS)->update([
            'display_name' => 'View Reports',
            'description' => 'View Reports permission for STAFF module',
            'updated_at' => now(),
        ]);

        // The compulsory permission and its grants predate this migration and stay.
    }
};
