<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two permissions for the customer list (personal data): analytics = aggregates only; details = names, addresses, phones and
 * e-mails. Details are held back by default: only super_admin (which bypasses every check) has them until somebody grants
 * the permission to a role. Mirrored in CommercialRolePermissionSeeder for fresh installs and tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $slugs = ['commercial.view_customer_analytics', 'commercial.view_customer_details'];

        foreach ($slugs as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                ['display_name' => $displayName, 'module' => Permission::MODULE_COMMERCIAL, 'description' => $displayName.' permission for COMMERCIAL module', 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $slugs)->pluck('id', 'name');

        $grants = [
            'super_admin' => $slugs,
            'commercial_officer' => ['commercial.view_customer_analytics'],
            'commercial_manager' => ['commercial.view_customer_analytics'],
            'chief_manager' => ['commercial.view_customer_analytics'],
            'regional_chief_manager' => ['commercial.view_customer_analytics'],
            'district_manager' => ['commercial.view_customer_analytics'],
        ];

        foreach ($grants as $roleName => $roleSlugs) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($roleSlugs as $slug) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionIds[$slug]]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['commercial.view_customer_analytics', 'commercial.view_customer_details'])->delete();
    }
};
