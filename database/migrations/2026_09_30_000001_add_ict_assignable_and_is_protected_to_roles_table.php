<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role classification used by App\Services\Uac\RoleGrantPolicy:
 *
 *   ict_assignable  the ICT team may assign/remove this role (to users inside their own location scope).
 *                   Anything not flagged here can only be assigned by a Global Admin or super_admin.
 *   is_protected    the role's definition (name, permissions, module access) can only be changed by a super_admin,
 *                   and the role can never be deleted. It anchors the access model, so a Global Admin must not be
 *                   able to widen its own role.
 *
 * Both default to false, so a role created later is Global-Admin-only until someone flags it. The classification
 * below is applied to roles that already exist; on a fresh database RoleSeeder applies the same values (roles are
 * created after migrations there). The map is duplicated from RoleSeeder on purpose: migrations must not depend
 * on seeder code that may change later.
 */
return new class extends Migration
{
    /** Roles the ICT team may assign. */
    private const ICT_ASSIGNABLE = [
        'employee',
        'manager',
        'departmental_manager',
        'district_manager',
        'chief_manager',
        'regional_chief_manager',
        'hr_region',
        'hr_headoffice',
        'secretary',
        'receptionist',
        'transport_manager',
        'driver',
    ];

    private const PROTECTED = ['super_admin', 'admin'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        if (! Schema::hasColumn('roles', 'ict_assignable')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('ict_assignable')->default(false)->after('is_system');
            });
        }

        if (! Schema::hasColumn('roles', 'is_protected')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_protected')->default(false)->after('ict_assignable');
            });
        }

        $now = now();

        foreach (DB::table('roles')->pluck('name') as $name) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'ict_assignable' => in_array($name, self::ICT_ASSIGNABLE, true),
                    'is_protected' => in_array($name, self::PROTECTED, true),
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (['is_protected', 'ict_assignable'] as $column) {
            if (Schema::hasColumn('roles', $column)) {
                Schema::table('roles', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
