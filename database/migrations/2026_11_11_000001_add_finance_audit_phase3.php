<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit, phase 3 (docs/20-module-finance-audit.md): analytics, exports, reminders and settings.
 *
 *  - fin_settings: a location's own override of a config default (the reminder thresholds). `unit` is the location's books:
 *    'ho' (Head Office) or 'r<region id>' (a region without Head Office), the same key FinanceBooksVersion uses. A value saved
 *    equal to its default is stored as no row.
 *  - fin_reminder_states: when a voucher that has waited too long was last reminded about, so a reminder goes out once per repeat
 *    interval and not every day (`since` = when the current wait began, so a NEW wait is reminded afresh).
 *  - the permission finance.manage_settings, granted to finance_officer and super_admin. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_settings')) {
            Schema::create('fin_settings', function (Blueprint $table) {
                $table->id();
                $table->string('unit', 20);
                $table->string('key', 60);
                $table->unsignedInteger('value');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['unit', 'key']);
            });
        }

        if (! Schema::hasTable('fin_reminder_states')) {
            Schema::create('fin_reminder_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pv_id')->constrained('fin_pvs')->cascadeOnDelete();
                $table->string('kind', 20);
                $table->dateTime('since');
                $table->dateTime('last_sent_at');
                $table->timestamps();

                $table->unique(['pv_id', 'kind']);
            });
        }

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'finance.manage_settings'],
            ['display_name' => 'Manage Settings', 'module' => 'finance', 'description' => 'Manage Settings permission for FINANCE module', 'created_at' => $now, 'updated_at' => $now]
        );

        $permissionId = DB::table('permissions')->where('name', 'finance.manage_settings')->value('id');

        foreach (['finance_officer', 'super_admin'] as $slug) {
            $roleId = DB::table('roles')->where('name', $slug)->value('id');

            if ($roleId && $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'finance.manage_settings')->delete();
        Schema::dropIfExists('fin_reminder_states');
        Schema::dropIfExists('fin_settings');
    }
};
