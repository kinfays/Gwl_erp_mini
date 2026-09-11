<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roles = [
            'credit_union_officer' => 'Credit Union Officer',
            'credit_union_committee' => 'Credit Union Committee',
        ];

        foreach ($roles as $name => $displayName) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'description' => $displayName.' system role',
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissions = [
            'credit_union.view_dashboard',
            'credit_union.view_own_statement',
            'credit_union.apply_membership',
            'credit_union.manage_members',
            'credit_union.approve_membership',
            'credit_union.manage_deductions',
            'credit_union.manage_loans',
            'credit_union.approve_loans',
            'credit_union.manage_withdrawals',
            'credit_union.approve_withdrawals',
            'credit_union.manage_refunds',
            'credit_union.manage_receipts',
            'credit_union.manage_interest_distribution',
            'credit_union.approve_interest_distribution',
            'credit_union.view_reports',
            'credit_union.export_reports',
            'credit_union.manage_settings',
        ];

        foreach ($permissions as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_CREDIT_UNION,
                    'description' => $displayName.' permission for CREDIT_UNION module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $accessMap = [
            'super_admin' => true,
            'credit_union_officer' => true,
            'credit_union_committee' => true,
            'employee' => true,
            'admin' => false,
            'ict_team' => false,
            'hr_headoffice' => false,
            'hr_region' => false,
            'secretary' => false,
            'manager' => false,
            'departmental_manager' => false,
            'district_manager' => false,
            'chief_manager' => false,
            'regional_chief_manager' => false,
            'receptionist' => false,
            'transport_manager' => false,
            'driver' => false,
        ];

        foreach ($accessMap as $roleName => $canAccess) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => Permission::MODULE_CREDIT_UNION],
                [
                    'can_access' => $canAccess,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->pluck('id', 'name');

        $baselinePermissions = [
            'credit_union.view_own_statement',
            'credit_union.apply_membership',
        ];

        $permissionMap = [
            'super_admin' => $permissions,
            'credit_union_officer' => [
                ...$baselinePermissions,
                'credit_union.view_dashboard',
                'credit_union.manage_members',
                'credit_union.manage_deductions',
                'credit_union.manage_loans',
                'credit_union.manage_withdrawals',
                'credit_union.manage_refunds',
                'credit_union.manage_receipts',
                'credit_union.manage_interest_distribution',
                'credit_union.view_reports',
                'credit_union.export_reports',
                'credit_union.manage_settings',
            ],
            'credit_union_committee' => [
                ...$baselinePermissions,
                'credit_union.view_dashboard',
                'credit_union.approve_membership',
                'credit_union.approve_loans',
                'credit_union.approve_withdrawals',
                'credit_union.approve_interest_distribution',
                'credit_union.view_reports',
                'credit_union.export_reports',
            ],
            'employee' => $baselinePermissions,
        ];

        foreach ($permissionMap as $roleName => $slugs) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($slugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;

                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('module_access')->where('module', Permission::MODULE_CREDIT_UNION)->delete();
        DB::table('permissions')->where('module', Permission::MODULE_CREDIT_UNION)->delete();
        DB::table('roles')->whereIn('name', ['credit_union_officer', 'credit_union_committee'])->delete();
    }
};
