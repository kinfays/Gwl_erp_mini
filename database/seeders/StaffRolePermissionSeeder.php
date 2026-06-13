<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class StaffRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_STAFF)
            ->pluck('id', 'name');

        $map = [
            'super_admin' => [
                'staff.view',
                'staff.create',
                'staff.edit',
                'staff.deactivate',
                'staff.import',
                'staff.export',
                'staff.view_reports',
                'staff.manage_departments',
                'staff.manage_regions',
                'staff.manage_locations',
                'staff.manage_job_titles',
            ],
            'hr_headoffice' => [
                'staff.view',
                'staff.create',
                'staff.edit',
                'staff.deactivate',
                'staff.import',
                'staff.export',
                'staff.view_reports',
                'staff.manage_departments',
                'staff.manage_regions',
                'staff.manage_locations',
                'staff.manage_job_titles',
            ],
            'hr_region' => [
                'staff.view',
                'staff.create',
                'staff.edit',
                'staff.deactivate',
                'staff.import',
                'staff.export',
                'staff.view_reports',
                'staff.manage_departments',
                'staff.manage_regions',
                'staff.manage_locations',
                'staff.manage_job_titles',
            ],
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
