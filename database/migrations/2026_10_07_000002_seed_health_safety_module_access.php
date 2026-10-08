<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Health & Safety: the two new roles, the fourteen permissions, a module_access row for EVERY role (every member of
 * staff reports incidents, so access is true for all of them; the permissions decide what each one then sees), and the
 * role grants of design section 2.1. Idempotent. Database\Seeders\HealthSafetyRolePermissionSeeder repeats the grants
 * for fresh installs and tests.
 */
return new class extends Migration
{
    public const PERMISSIONS = [
        'health_safety.report_incident',
        'health_safety.view_incidents',
        'health_safety.view_injury_details',
        'health_safety.manage_incidents',
        'health_safety.approve_closure',
        'health_safety.record_on_behalf',
        'health_safety.view_dashboard',
        'health_safety.view_equipment',
        'health_safety.record_checks',
        'health_safety.manage_equipment',
        'health_safety.manage_ppe',
        'health_safety.manage_master_data',
        'health_safety.export_reports',
        'health_safety.manage_settings',
    ];

    public function up(): void
    {
        $now = now();

        $roles = [
            'hs_officer' => 'Health & Safety Officer',
            'hs_manager' => 'Health & Safety Manager',
        ];

        foreach ($roles as $name => $displayName) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'description' => $displayName.' system role',
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach (self::PERMISSIONS as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_HEALTH_SAFETY,
                    'description' => $displayName.' permission for HEALTH_SAFETY module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach (DB::table('roles')->pluck('id') as $roleId) {
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => Permission::MODULE_HEALTH_SAFETY],
                ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('module', Permission::MODULE_HEALTH_SAFETY)
            ->pluck('id', 'name');

        // Everyone may report; the rest follows the role table in design section 2.1.
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

        $permissionMap = [
            'super_admin' => self::PERMISSIONS,
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

        foreach (DB::table('roles')->pluck('id', 'name') as $roleName => $roleId) {
            $slugs = ['health_safety.report_incident', ...($permissionMap[$roleName] ?? [])];

            foreach (array_unique($slugs) as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;

                if ($permissionId) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('module_access')->where('module', Permission::MODULE_HEALTH_SAFETY)->delete();
        DB::table('permissions')->where('module', Permission::MODULE_HEALTH_SAFETY)->delete();
        DB::table('roles')->whereIn('name', ['hs_officer', 'hs_manager'])->delete();
    }
};
