<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roles = [
            'transport_manager' => 'Transport Manager',
            'driver' => 'Driver',
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

        $permissions = [
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

        foreach ($permissions as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_TRANSPORT,
                    'description' => $displayName.' permission for TRANSPORT module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $accessMap = [
            'super_admin' => true,
            'transport_manager' => true,
            'driver' => true,
            'employee' => true,
            'admin' => false,
            'ict_team' => false,
            'hr_headoffice' => false,
            'hr_region' => false,
            'secretary' => false,
            'manager' => false,
            'departmental_manager' => false,
            'district_manager' => false,
            'chief_manager' => false,
            'regional_chief_manager' => false,
            'receptionist' => false,
        ];

        foreach ($accessMap as $roleName => $canAccess) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => Permission::MODULE_TRANSPORT],
                [
                    'can_access' => $canAccess,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('module', Permission::MODULE_TRANSPORT)
            ->pluck('id', 'name');

        $permissionMap = [
            'super_admin' => $permissions,
            'transport_manager' => $permissions,
            'driver' => [
                'transport.view_own_vehicle',
                'transport.log_mileage',
                'transport.report_issues',
            ],
            'employee' => [
                'transport.view_own_vehicle',
            ],
        ];

        foreach ($permissionMap as $roleName => $slugs) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($slugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;

                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('module_access')->where('module', Permission::MODULE_TRANSPORT)->delete();
        DB::table('permissions')->where('module', Permission::MODULE_TRANSPORT)->delete();
        DB::table('roles')->whereIn('name', ['transport_manager', 'driver'])->delete();
    }
};
