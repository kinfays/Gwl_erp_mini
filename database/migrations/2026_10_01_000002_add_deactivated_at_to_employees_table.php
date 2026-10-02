<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a member of staff left (the exit date). Staff turnover and the exit-reason chart in the HR analytics need it:
 * until now only `is_active` and `deactivation_reason` existed, and the only hint of when was `updated_at`.
 *
 * Existing deactivated employees are backfilled from `updated_at` — the best that can be known, and only accurate if
 * the record wasn't edited after the deactivation. Set from now on by the deactivation itself; cleared on reactivation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        if (! Schema::hasColumn('employees', 'deactivated_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('deactivated_at')->nullable()->after('deactivation_reason')->index();
            });
        }

        DB::table('employees')
            ->where('is_active', false)
            ->whereNull('deactivated_at')
            ->update(['deactivated_at' => DB::raw('date(updated_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'deactivated_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex(['deactivated_at']);
            });

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('deactivated_at');
            });
        }
    }
};
