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

        // Phase 8a: a role that already saw the module keeps seeing every district of its region; only the district viewer is limited.
        $allDistricts = 'commercial.view_all_districts';

        $map = [
            'super_admin' => $permissions->keys()->all(),
            'commercial_officer' => [
                ...$managementView,
                'commercial.view_reader_performance',
                'commercial.upload_reports',
                'commercial.resolve_matches',
                'commercial.void_batches',
                'commercial.export_reports',
                // Phase 7: the officer sends SMS to Accra West customers (the region lock is SmsAccess's, not the permission's).
                'commercial.send_sms',
                'commercial.view_sms_balance',
                'commercial.view_sms_reports',
                $allDistricts,
            ],
            'commercial_manager' => [
                ...$managementView,
                'commercial.view_reader_performance',
                'commercial.export_reports',
                // The manager also runs the uploads (reports and customer list), the settings and SMS, but not the SMS provider settings.
                'commercial.manage_settings',
                'commercial.upload_reports',
                'commercial.resolve_matches',
                'commercial.void_batches',
                'commercial.send_sms',
                'commercial.view_sms_balance',
                'commercial.view_sms_reports',
                $allDistricts,
                'commercial.manage_routes',
            ],
            'chief_manager' => [...$managementView, $allDistricts],
            'regional_chief_manager' => [...$managementView, $allDistricts],
            'district_manager' => [...$managementView, $allDistricts],
            // Phase 8a: the management views, limited to the district on the employee record; no upload, no SMS, no all-districts.
            // Phase 8d (step 0.1): and the CCAs of THEIR district's routes by name (the districts reached are CommercialScope's, not the permission's).
            'district_commercial_viewer' => [...$managementView, 'commercial.view_reader_performance'],
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
