<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leave approval routing additions, seeded here so a fresh `migrate` and a `migrate` on a running database
 * end up in the same state (the seeders mirror the grants for `migrate:fresh --seed`).
 *
 *   managing_director            final approver for a chief_manager / regional_chief_manager's own leave
 *   leave.manage_hr_contacts     the screen where HR sets who is told about finally-approved leave
 *
 * Approval itself is decided by role (LeaveApprovalChainResolver), like the other managerial roles, so the
 * MD needs the Leave module and the basic leave permissions, not a dedicated approval permission.
 */
return new class extends Migration
{
    private const MD_ROLE = 'managing_director';

    private const HR_CONTACTS_PERMISSION = 'leave.manage_hr_contacts';

    private const MD_PERMISSIONS = ['leave.view_own', 'leave.apply', 'leave.approve_final'];

    public function up(): void
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['name' => self::MD_ROLE],
            [
                'display_name' => 'Managing Director',
                'description' => 'Managing Director system role',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $mdRoleId = DB::table('roles')->where('name', self::MD_ROLE)->value('id');

        DB::table('module_access')->updateOrInsert(
            ['role_id' => $mdRoleId, 'module' => Permission::MODULE_LEAVE],
            ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        DB::table('permissions')->updateOrInsert(
            ['name' => self::HR_CONTACTS_PERMISSION],
            [
                'display_name' => 'Manage Hr Contacts',
                'module' => Permission::MODULE_LEAVE,
                'description' => 'Manage Hr Contacts permission for LEAVE module',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->grant([self::MD_ROLE], self::MD_PERMISSIONS);
        $this->grant(['super_admin', 'admin', 'hr_headoffice', 'hr_region'], [self::HR_CONTACTS_PERMISSION]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::HR_CONTACTS_PERMISSION)->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        $mdRoleId = DB::table('roles')->where('name', self::MD_ROLE)->value('id');

        if (! $mdRoleId) {
            return;
        }

        DB::table('role_permissions')->where('role_id', $mdRoleId)->delete();
        DB::table('module_access')->where('role_id', $mdRoleId)->delete();

        // Keep the role if someone has been given it: deleting it would silently strip their approvals.
        if (! DB::table('user_roles')->where('role_id', $mdRoleId)->exists()) {
            DB::table('roles')->where('id', $mdRoleId)->delete();
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $permissionNames
     */
    private function grant(array $roleNames, array $permissionNames): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id');

        foreach (DB::table('roles')->whereIn('name', $roleNames)->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
};
