<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 7 (SMS to customers): four permissions. super_admin holds all of them; the Commercial Officer may send and see the
 * balance and the reports; nobody else gets anything (manage_sms_settings is super_admin only). Holding a permission is not
 * enough: Services/Commercial/Sms/SmsAccess also requires the user to belong to the region(s) the feature is locked to.
 * Mirrored in CommercialRolePermissionSeeder for fresh installs and tests.
 */
return new class extends Migration
{
    private const SLUGS = ['commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports', 'commercial.manage_sms_settings'];

    public function up(): void
    {
        $now = now();

        foreach (self::SLUGS as $slug) {
            $displayName = Str::of($slug)->after('.')->replace('_', ' ')->title()->toString();

            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                ['display_name' => $displayName, 'module' => Permission::MODULE_COMMERCIAL, 'description' => $displayName.' permission for COMMERCIAL module', 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', self::SLUGS)->pluck('id', 'name');

        $grants = [
            'super_admin' => self::SLUGS,
            'commercial_officer' => ['commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports'],
        ];

        foreach ($grants as $roleName => $slugs) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($slugs as $slug) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionIds[$slug]]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', self::SLUGS)->delete();
    }
};
