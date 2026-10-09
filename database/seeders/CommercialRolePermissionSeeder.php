<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors the role -> permission grants of migration 2026_10_05_000004_seed_commercial_module_access, for fresh installs
 * (the migration runs before any role exists there) and for tests that seed roles themselves.
 */
class CommercialRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_COMMERCIAL)
            ->pluck('id', 'name');

        $managementView = [
            'commercial.view_dashboard',
            'commercial.view_billing',
            'commercial.view_reading',
            'commercial.view_customer_analytics',   // aggregates only; names/phones (view_customer_details) go to super_admin until granted
        ];

        $map = [
            'super_admin' => $permissions->keys()->all(),
            'commercial_officer' => [
                ...$managementView,
                'commercial.view_reader_performance',
                'commercial.upload_reports',
                'commercial.resolve_matches',
                'commercial.void_batches',
                'commercial.export_reports',
            ],
            'commercial_manager' => [
                ...$managementView,
                'commercial.view_reader_performance',
                'commercial.export_reports',
            ],
            'chief_manager' => $managementView,
            'regional_chief_manager' => $managementView,
            'district_manager' => $managementView,
        ];

        foreach ($map as $roleName => $slugs) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching(
                collect($slugs)->map(fn (string $slug) => $permissions[$slug] ?? null)->filter()->values()->all()
            );
        }
    }
}
