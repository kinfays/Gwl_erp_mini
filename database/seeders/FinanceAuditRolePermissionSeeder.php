<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors the role -> permission grants of migration 2026_11_10_000002_seed_finance_audit_access, for fresh installs (the
 * migration runs before any role exists there) and for tests that seed roles themselves. finance_officer holds every
 * finance.* permission except finance.deactivate_suppliers, audit_officer every audit.* one, super_admin all of them.
 */
class FinanceAuditRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $finance = Permission::query()->where('module', Permission::MODULE_FINANCE)->get();
        $audit = Permission::query()->where('module', Permission::MODULE_AUDIT)->get();

        $grant = function (string $role, $permissions): void {
            Role::query()->where('name', $role)->first()?->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
        };

        $grant('finance_officer', $finance->reject(fn (Permission $permission) => $permission->name === 'finance.deactivate_suppliers'));
        $grant('audit_officer', $audit);
        $grant('super_admin', $finance->merge($audit));
    }
}
