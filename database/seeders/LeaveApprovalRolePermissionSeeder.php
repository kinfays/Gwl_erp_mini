<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Grants added with the leave approval routing (managing_director, leave.manage_hr_contacts). The migration
 * 2026_09_29_100003 makes the same grants on a running database; this covers `migrate:fresh --seed`, where the
 * roles and permissions only exist once the earlier seeders have run.
 *
 * Runs after UacRolePermissionSeeder, which sync()s the admin role's permissions and would drop these.
 */
class LeaveApprovalRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()->pluck('id', 'name');

        $map = [
            'managing_director' => ['leave.view_own', 'leave.apply', 'leave.approve_final'],
            'super_admin' => ['leave.manage_hr_contacts'],
            'admin' => ['leave.manage_hr_contacts'],
            'hr_headoffice' => ['leave.manage_hr_contacts'],
            'hr_region' => ['leave.manage_hr_contacts'],
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
