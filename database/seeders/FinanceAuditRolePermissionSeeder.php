<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors the role -> permission grants of the Finance / Audit migrations, for fresh installs (the migrations run before any role
 * exists there) and for tests that seed roles themselves. The lists are explicit so a permission added later is never granted by
 * accident:
 *  - finance_officer: prepares and sends vouchers, suppliers (not deactivating them), reports and exports; NO catalogue, NO reminders;
 *  - finance_manager: reads everything of its location, manages the catalogue and the reminders, deactivates suppliers; never writes a voucher;
 *  - audit_officer: the review of the vouchers assigned to them; audit_manager: assigns vouchers and batches to officers, sees only
 *    vouchers an Audit officer worked on (plus the assignment queue's minimal columns), reports, reminders;
 *  - super_admin: all of them.
 */
class FinanceAuditRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $finance = Permission::query()->where('module', Permission::MODULE_FINANCE)->pluck('name');
        $audit = Permission::query()->where('module', Permission::MODULE_AUDIT)->pluck('name');

        $grants = [
            'finance_officer' => $finance->reject(fn (string $name) => in_array($name, ['finance.deactivate_suppliers', 'finance.manage_categories', 'finance.manage_reminders'], true))->all(),
            'finance_manager' => ['finance.view', 'finance.view_reports', 'finance.export', 'finance.manage_categories', 'finance.manage_reminders', 'finance.deactivate_suppliers'],
            'audit_officer' => ['audit.view', 'audit.review', 'audit.view_reports', 'audit.export'],
            'audit_manager' => ['audit.view_reviewed', 'audit.view_reports', 'audit.export', 'audit.manage_reminders', 'audit.assign'],
            'super_admin' => $finance->merge($audit)->all(),
        ];

        foreach ($grants as $role => $names) {
            $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();
            Role::query()->where('name', $role)->first()?->permissions()->syncWithoutDetaching($ids);
        }
    }
}
