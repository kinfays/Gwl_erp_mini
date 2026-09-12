<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 5's own permission seeding. These slugs already exist on databases migrated
 * before the phases were split out, so everything here is updateOrInsert/insertOrIgnore
 * and a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissions = [
            'credit_union.manage_interest_distribution',
            'credit_union.approve_interest_distribution',
        ];

        foreach ($permissions as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_CREDIT_UNION,
                    'description' => $displayName.' permission for CREDIT_UNION module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissions)
            ->pluck('id', 'name');

        $permissionMap = [
            'super_admin' => $permissions,
            'credit_union_officer' => ['credit_union.manage_interest_distribution'],
            'credit_union_committee' => ['credit_union.approve_interest_distribution'],
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
        $permissionIds = DB::table('permissions')
            ->whereIn('name', [
                'credit_union.manage_interest_distribution',
                'credit_union.approve_interest_distribution',
            ])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
