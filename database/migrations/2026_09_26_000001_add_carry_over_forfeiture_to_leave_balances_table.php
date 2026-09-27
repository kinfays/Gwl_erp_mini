<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_balances')) {
            return;
        }

        $needsDays = ! Schema::hasColumn('leave_balances', 'carry_over_forfeited_days');
        $needsAt = ! Schema::hasColumn('leave_balances', 'carry_over_forfeited_at');

        if (! $needsDays && ! $needsAt) {
            return;
        }

        Schema::table('leave_balances', function (Blueprint $table) use ($needsDays, $needsAt) {
            if ($needsDays) {
                $table->unsignedSmallInteger('carry_over_forfeited_days')->default(0)->after('carry_over_expired_date');
            }

            if ($needsAt) {
                $table->timestamp('carry_over_forfeited_at')->nullable()->after('carry_over_forfeited_days');
            }
        });
    }

    public function down(): void
    {
        $hasDays = Schema::hasColumn('leave_balances', 'carry_over_forfeited_days');
        $hasAt = Schema::hasColumn('leave_balances', 'carry_over_forfeited_at');

        if (! $hasDays && ! $hasAt) {
            return;
        }

        Schema::table('leave_balances', function (Blueprint $table) use ($hasDays, $hasAt) {
            if ($hasAt) {
                $table->dropColumn('carry_over_forfeited_at');
            }

            if ($hasDays) {
                $table->dropColumn('carry_over_forfeited_days');
            }
        });
    }
};
