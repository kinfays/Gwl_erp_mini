<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CreditUnionRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->pluck('id', 'name');

        $baselinePermissions = [
            'credit_union.view_own_statement',
            'credit_union.apply_membership',
        ];

        $officerPermissions = [
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
        ];

        $committeePermissions = [
            ...$baselinePermissions,
            'credit_union.view_dashboard',
            'credit_union.approve_membership',
            'credit_union.approve_loans',
            'credit_union.approve_withdrawals',
            'credit_union.approve_interest_distribution',
            'credit_union.view_reports',
            'credit_union.export_reports',
        ];

        $map = [
            'super_admin' => $permissions->keys()->all(),
            'credit_union_officer' => $officerPermissions,
            'credit_union_committee' => $committeePermissions,
            'employee' => $baselinePermissions,
        ];

        foreach ($map as $roleName => $slugs) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching(
                collect($slugs)
                    ->map(fn (string $slug) => $permissions[$slug] ?? null)
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}
