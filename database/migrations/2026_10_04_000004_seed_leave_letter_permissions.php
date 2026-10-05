<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions for leave approval letters, granted here so a fresh `migrate` and one on a running database agree
 * (LeaveApprovalRolePermissionSeeder mirrors the grants for `migrate:fresh --seed`). The screens also check the role,
 * so a grant to some other role opens nothing.
 *
 *  leave.sign_letters           My Signature: the people who sign approval letters
 *  leave.manage_letter_settings Letter Settings: letterhead per location (own region for regional HR) and the company block
 *  leave.manage_acting          Acting assignments for the final-approver posts
 */
return new class extends Migration
{
    private const GRANTS = [
        'leave.sign_letters' => [
            'label' => 'Sign Letters',
            'roles' => ['chief_manager', 'regional_chief_manager', 'managing_director', 'hr_region', 'hr_headoffice'],
        ],
        'leave.manage_letter_settings' => [
            'label' => 'Manage Letter Settings',
            'roles' => ['super_admin', 'admin', 'hr_headoffice', 'hr_region'],
        ],
        'leave.manage_acting' => [
            'label' => 'Manage Acting Assignments',
            'roles' => ['super_admin', 'admin', 'hr_headoffice', 'hr_region'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GRANTS as $name => $grant) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $grant['label'],
                    'module' => Permission::MODULE_LEAVE,
                    'description' => $grant['label'].' permission for LEAVE module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            foreach (DB::table('roles')->whereIn('name', $grant['roles'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
