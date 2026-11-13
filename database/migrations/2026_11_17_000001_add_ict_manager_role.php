<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ICT Manager role: a regional (or Head Office) ICT lead who grants roles to the users of their own location. It
 * holds what the ICT team holds (Assets, MDM and the UAC user permissions) and the same module access (UAC, Assets,
 * plus Health & Safety and the Blog, which every role may enter). What sets it apart is enforced in
 * Services/Uac/RoleGrantPolicy and the visibleTo() scopes, not here. ict_assignable stays false, so it is given only by
 * a Global Admin or super_admin. Idempotent; the seeders repeat the same grants for fresh installs and tests.
 */
return new class extends Migration
{
    public const ROLE = 'ict_manager';

    public const PERMISSIONS = [
        'uac.view_users',
        'uac.create_users',
        'uac.edit_users',
        'uac.assign_roles',
        'assets.view_dashboard',
        'assets.view_inventory',
        'assets.create',
        'assets.edit',
        'assets.manage_maintenance',
        'assets.manage_reports',
        'assets.agent_ingest',
        'assets.view_network_secrets',
        'assets.manage_issue_categories',
        'assets.manage_audits',
        'assets.export_audits',
        'assets.export_inventory',
        'assets.export_reports',
        'assets.export_maintenance',
        'assets.mdm_view',
        'assets.mdm_enroll',
        'assets.mdm_command',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['name' => self::ROLE],
            [
                'display_name' => 'ICT Manager',
                'description' => 'ICT Manager system role',
                'is_system' => true,
                'ict_assignable' => false,
                'is_protected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');

        foreach (Permission::MODULES as $module) {
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => $module],
                [
                    'can_access' => in_array($module, [
                        Permission::MODULE_UAC,
                        Permission::MODULE_ASSETS,
                        Permission::MODULE_HEALTH_SAFETY,
                        Permission::MODULE_BLOG,
                    ], true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach (DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');

        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('module_access')->where('role_id', $roleId)->delete();
            DB::table('user_roles')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
