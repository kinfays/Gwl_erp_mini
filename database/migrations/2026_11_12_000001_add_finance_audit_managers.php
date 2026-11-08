<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit, change request step A (docs/20-module-finance-audit.md): the two manager roles and who sees what.
 *
 *  - new permissions finance.manage_reminders (replaces finance.manage_settings, which is renamed in place so super_admin keeps it),
 *    audit.manage_reminders and audit.view_reviewed (the Audit manager's view: only vouchers an Audit officer has worked on);
 *  - roles finance_manager and audit_manager (ICT-assignable, not protected, not system roles, in NEITHER
 *    RoleGrantPolicy::HEAD_OFFICE_ROLES nor REGIONAL_ROLES) with their module access;
 *  - finance_officer LOSES finance.manage_categories and the reminder settings permission on databases that already had them
 *    (a catalogue is the Finance manager's, and an officer has no reminder settings);
 *  - audit_officer is unchanged.
 * Idempotent. Database\Seeders\FinanceAuditRolePermissionSeeder repeats the grants for fresh installs and tests.
 */
return new class extends Migration
{
    public const PERMISSIONS = [
        'finance.manage_reminders' => ['finance', 'Manage Reminders'],
        'audit.manage_reminders' => ['audit', 'Manage Reminders'],
        'audit.view_reviewed' => ['audit', 'View Reviewed PVs'],
    ];

    public const MANAGERS = [
        'finance_manager' => [
            'name' => 'Finance Manager',
            'module' => Permission::MODULE_FINANCE,
            'permissions' => ['finance.view', 'finance.view_reports', 'finance.export', 'finance.manage_categories', 'finance.manage_reminders', 'finance.deactivate_suppliers'],
        ],
        'audit_manager' => [
            'name' => 'Audit Manager',
            'module' => Permission::MODULE_AUDIT,
            'permissions' => ['audit.view_reviewed', 'audit.view_reports', 'audit.export', 'audit.manage_reminders'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        // finance.manage_settings becomes finance.manage_reminders (renamed, so every existing grant moves with it).
        $old = DB::table('permissions')->where('name', 'finance.manage_settings')->first();

        if ($old && ! DB::table('permissions')->where('name', 'finance.manage_reminders')->exists()) {
            DB::table('permissions')->where('id', $old->id)->update(['name' => 'finance.manage_reminders', 'display_name' => 'Manage Reminders', 'description' => 'Manage Reminders permission for FINANCE module', 'updated_at' => $now]);
        } elseif ($old) {
            DB::table('role_permissions')->where('permission_id', $old->id)->delete();
            DB::table('permissions')->where('id', $old->id)->delete();
        }

        foreach (self::PERMISSIONS as $name => [$module, $label]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $label, 'module' => $module, 'description' => $label.' permission for '.strtoupper($module).' module', 'created_at' => $now, 'updated_at' => $now]
            );
        }

        foreach (self::MANAGERS as $slug => $definition) {
            $role = [
                'display_name' => $definition['name'],
                'description' => $definition['name'].' (payment vouchers)',
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach (['ict_assignable' => true, 'is_protected' => false] as $column => $value) {
                if (Schema::hasColumn('roles', $column)) {
                    $role[$column] = $value;
                }
            }

            DB::table('roles')->updateOrInsert(['name' => $slug], $role);

            $roleId = DB::table('roles')->where('name', $slug)->value('id');
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => $definition['module']],
                ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
            );

            foreach (DB::table('permissions')->whereIn('name', $definition['permissions'])->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        // The officer no longer manages the catalogue and has no reminder settings.
        $officer = DB::table('roles')->where('name', 'finance_officer')->value('id');

        if ($officer) {
            $taken = DB::table('permissions')->whereIn('name', ['finance.manage_categories', 'finance.manage_reminders'])->pluck('id');
            DB::table('role_permissions')->where('role_id', $officer)->whereIn('permission_id', $taken)->delete();
        }

        $superAdmin = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($superAdmin) {
            foreach (DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $superAdmin, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('roles')->whereIn('name', array_keys(self::MANAGERS))->pluck('id');

        DB::table('module_access')->whereIn('role_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('role_id', $ids)->delete();
        DB::table('roles')->whereIn('id', $ids)->delete();
        DB::table('permissions')->whereIn('name', ['audit.manage_reminders', 'audit.view_reviewed'])->delete();
        DB::table('permissions')->where('name', 'finance.manage_reminders')->update(['name' => 'finance.manage_settings', 'display_name' => 'Manage Settings']);
    }
};
