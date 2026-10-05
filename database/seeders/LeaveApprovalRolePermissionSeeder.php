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
            // Approval letters: who signs (leave.sign_letters), who edits letterheads (leave.manage_letter_settings) and
            // who sets acting assignments (leave.manage_acting). Migration 2026_10_04_000004 makes the same grants.
            'managing_director' => ['leave.view_own', 'leave.apply', 'leave.approve_final', 'leave.sign_letters'],
            'chief_manager' => ['leave.sign_letters'],
            'regional_chief_manager' => ['leave.sign_letters'],
            'super_admin' => ['leave.manage_hr_contacts', 'leave.manage_letter_settings', 'leave.manage_acting'],
            'admin' => ['leave.manage_hr_contacts', 'leave.manage_compulsory', 'leave.manage_letter_settings', 'leave.manage_acting'],
            // Compulsory Leave page: Head Office HR and Global Admin (admin is granted it by UacRolePermissionSeeder).
            'hr_headoffice' => ['leave.manage_hr_contacts', 'leave.manage_compulsory', 'leave.sign_letters', 'leave.manage_letter_settings', 'leave.manage_acting'],
            'hr_region' => ['leave.manage_hr_contacts', 'leave.sign_letters', 'leave.manage_letter_settings', 'leave.manage_acting'],
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
