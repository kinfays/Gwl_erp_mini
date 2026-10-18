<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Commercial Manager runs the uploads (meter-reading and billing reports, and the customer list), the module settings
 * and SMS to customers. SMS provider settings (commercial.manage_sms_settings) stay with super_admin, and the SMS region lock
 * (Services/Commercial/Sms/SmsAccess) still applies on top of the permission. Mirrored in CommercialRolePermissionSeeder.
 */
return new class extends Migration
{
    private const SLUGS = [
        'commercial.manage_settings', 'commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches',
        'commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'commercial_manager')->value('id');

        if (! $roleId) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('name', self::SLUGS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'commercial_manager')->value('id');

        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::SLUGS)->pluck('id'))->delete();
        }
    }
};
