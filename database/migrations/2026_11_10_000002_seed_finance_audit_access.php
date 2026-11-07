<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit (docs/20-module-finance-audit.md): the finance.* and audit.* permissions, the `finance_officer` and
 * `audit_officer` roles (ICT may assign them, not protected, not system roles; deliberately in NEITHER
 * RoleGrantPolicy::HEAD_OFFICE_ROLES nor REGIONAL_ROLES), their module_access, and super_admin's grants. Idempotent.
 * Database\Seeders\FinanceAuditRolePermissionSeeder repeats the grants for fresh installs and tests.
 *
 * finance.deactivate_suppliers is held by super_admin only until someone is given it on purpose (an edit or a deactivation
 * of a supplier affects every location).
 */
return new class extends Migration
{
    public const PERMISSIONS = [
        'finance' => [
            'finance.view' => 'View',
            'finance.create_pv' => 'Create / Edit / Send PV',
            'finance.void_pv' => 'Void PV',
            'finance.acknowledge' => 'Acknowledge PV',
            'finance.manage_categories' => 'Manage Categories',
            'finance.manage_suppliers' => 'Manage Suppliers',
            'finance.deactivate_suppliers' => 'Deactivate Suppliers',
            'finance.import_suppliers' => 'Import Suppliers',
            'finance.view_reports' => 'View Reports',
            'finance.export' => 'Export',
        ],
        'audit' => [
            'audit.view' => 'View',
            'audit.review' => 'Review PV',
            'audit.view_reports' => 'View Reports',
            'audit.export' => 'Export',
        ],
    ];

    public const ROLES = [
        'finance_officer' => ['name' => 'Finance Officer', 'module' => Permission::MODULE_FINANCE, 'except' => ['finance.deactivate_suppliers']],
        'audit_officer' => ['name' => 'Audit Officer', 'module' => Permission::MODULE_AUDIT, 'except' => []],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $module => $permissions) {
            foreach ($permissions as $name => $label) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $name],
                    ['display_name' => $label, 'module' => $module, 'description' => $label.' permission for '.strtoupper($module).' module', 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        foreach (self::ROLES as $slug => $definition) {
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

            $ids = DB::table('permissions')->where('module', $definition['module'])->whereNotIn('name', $definition['except'])->pluck('id');

            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $superAdmin = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($superAdmin) {
            foreach ([Permission::MODULE_FINANCE, Permission::MODULE_AUDIT] as $module) {
                DB::table('module_access')->updateOrInsert(
                    ['role_id' => $superAdmin, 'module' => $module],
                    ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
                );
            }

            foreach (DB::table('permissions')->whereIn('module', [Permission::MODULE_FINANCE, Permission::MODULE_AUDIT])->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $superAdmin, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('module_access')->whereIn('module', [Permission::MODULE_FINANCE, Permission::MODULE_AUDIT])->delete();
        DB::table('permissions')->whereIn('module', [Permission::MODULE_FINANCE, Permission::MODULE_AUDIT])->delete();
        DB::table('roles')->whereIn('name', array_keys(self::ROLES))->delete();
    }
};
