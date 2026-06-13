<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class TransportRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_TRANSPORT)
            ->pluck('id', 'name');

        $managerPermissions = [
            'transport.view_dashboard',
            'transport.view_vehicles',
            'transport.create_vehicles',
            'transport.edit_vehicles',
            'transport.assign_vehicles',
            'transport.import_vehicles',
            'transport.view_own_vehicle',
            'transport.log_mileage',
            'transport.report_issues',
            'transport.manage_issues',
            'transport.manage_maintenance',
            'transport.manage_expenses',
            'transport.export_expenses',
            'transport.view_reports',
            'transport.renew_documents',
        ];

        $map = [
            'super_admin' => $managerPermissions,
            'transport_manager' => $managerPermissions,
            'driver' => [
                'transport.view_own_vehicle',
                'transport.log_mileage',
                'transport.report_issues',
            ],
            'employee' => [
                'transport.view_own_vehicle',
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
