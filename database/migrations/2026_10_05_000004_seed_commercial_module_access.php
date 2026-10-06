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
            'commercial_officer' => 'Commercial Officer',
            'commercial_manager' => 'Commercial Manager',
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
            'commercial.view_dashboard',
            'commercial.view_billing',
            'commercial.view_reading',
            'commercial.view_reader_performance',
            'commercial.upload_reports',
            'commercial.resolve_matches',
            'commercial.void_batches',
            'commercial.export_reports',
            'commercial.manage_settings',
        ];

        foreach ($permissions as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_COMMERCIAL,
                    'description' => $displayName.' permission for COMMERCIAL module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // Every existing role gets an explicit row, true only for the roles that work with the module.
        $canAccess = [
            'super_admin',
            'commercial_officer',
            'commercial_manager',
            'chief_manager',
            'regional_chief_manager',
            'district_manager',
        ];

        foreach (DB::table('roles')->pluck('id', 'name') as $roleName => $roleId) {
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => Permission::MODULE_COMMERCIAL],
                [
                    'can_access' => in_array($roleName, $canAccess, true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('module', Permission::MODULE_COMMERCIAL)
            ->pluck('id', 'name');

        $managementView = [
            'commercial.view_dashboard',
            'commercial.view_billing',
            'commercial.view_reading',
        ];

        $permissionMap = [
            'super_admin' => $permissions,
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
            // Whether these also see individual readers is an open question (design section 10, question 6).
            'chief_manager' => $managementView,
            'regional_chief_manager' => $managementView,
            'district_manager' => $managementView,
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
        DB::table('module_access')->where('module', Permission::MODULE_COMMERCIAL)->delete();
        DB::table('permissions')->where('module', Permission::MODULE_COMMERCIAL)->delete();
        DB::table('roles')->whereIn('name', ['commercial_officer', 'commercial_manager'])->delete();
    }
};
