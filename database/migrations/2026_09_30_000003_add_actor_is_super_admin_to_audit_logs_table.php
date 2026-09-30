<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who did it, frozen at the moment they did it: whether the actor was a super_admin. AuditLog::visibleTo() hides
 * those rows from everyone else, and it must keep doing so if that account is later demoted or deleted (user_id
 * is nulled on delete), so the flag can't be derived from the actor's current roles.
 *
 * Existing rows are backfilled from the actors' current roles: the best that can be known now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        if (! Schema::hasColumn('audit_logs', 'actor_is_super_admin')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->boolean('actor_is_super_admin')->default(false)->index();
            });
        }

        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            DB::table('audit_logs')
                ->whereIn('user_id', DB::table('user_roles')->where('role_id', $superAdminRoleId)->select('user_id'))
                ->update(['actor_is_super_admin' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'actor_is_super_admin')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('actor_is_super_admin');
            });
        }
    }
};
