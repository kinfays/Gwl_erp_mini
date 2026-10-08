<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors the role -> permission grants of migration 2026_10_07_000002_seed_health_safety_module_access, for fresh
 * installs (the migration runs before any role exists there) and for tests that seed roles themselves. Every role may
 * report an incident; the rest follows design section 2.1.
 */
class HealthSafetyRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_HEALTH_SAFETY)
            ->pluck('id', 'name');

        $officer = [
            'health_safety.view_incidents',
            'health_safety.view_injury_details',
            'health_safety.manage_incidents',
            'health_safety.record_on_behalf',
            'health_safety.view_dashboard',
            'health_safety.view_equipment',
            'health_safety.record_checks',
            'health_safety.manage_equipment',
            'health_safety.manage_ppe',
            'health_safety.manage_master_data',
            'health_safety.export_reports',
        ];

        $map = [
            'super_admin' => $permissions->keys()->all(),
            'hs_officer' => $officer,
            'hs_manager' => [...$officer, 'health_safety.approve_closure', 'health_safety.manage_settings'],
            'regional_chief_manager' => [
                'health_safety.view_incidents',
                'health_safety.approve_closure',
                'health_safety.view_dashboard',
                'health_safety.view_equipment',
                'health_safety.export_reports',
            ],
            'district_manager' => [
                'health_safety.view_incidents',
                'health_safety.view_dashboard',
                'health_safety.view_equipment',
                'health_safety.record_checks',
                'health_safety.record_on_behalf',
            ],
        ];

        foreach (Role::query()->get() as $role) {
            $slugs = array_unique(['health_safety.report_incident', ...($map[$role->name] ?? [])]);

            $role->permissions()->syncWithoutDetaching(
                collect($slugs)->map(fn (string $slug) => $permissions[$slug] ?? null)->filter()->values()->all()
            );
        }
    }
}
