<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial analytics (billing coverage, arrears by time since the last payment): five more measures on the customer rollups.
 *
 *   billed_valid_30      customers whose last bill is dated in the 30 days before the file's date AND is for more than zero
 *   debit_no_pay_30..180 what customers OWE (balances above zero, in pesewas) among those with no payment in the last N days
 *                        (or never)
 *
 * A rollup is an immutable snapshot of one upload, so batches built before this migration do not have the measures: they carry
 * rollup_version 1 and the coverage screen leaves them out ("from the next upload") instead of showing a false 0%.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['commercial_customer_rollups', 'commercial_customer_rollups_district'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'billed_valid_30')) {
                    $table->unsignedInteger('billed_valid_30')->default(0);
                }

                foreach ([30, 60, 90, 180] as $days) {
                    if (! Schema::hasColumn($name, 'debit_no_pay_'.$days)) {
                        $table->bigInteger('debit_no_pay_'.$days)->default(0);
                    }
                }
            });
        }

        if (Schema::hasTable('commercial_customer_batches') && ! Schema::hasColumn('commercial_customer_batches', 'rollup_version')) {
            Schema::table('commercial_customer_batches', function (Blueprint $table): void {
                $table->unsignedTinyInteger('rollup_version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['commercial_customer_rollups', 'commercial_customer_rollups_district'] as $name) {
            if (Schema::hasTable($name)) {
                Schema::table($name, function (Blueprint $table) use ($name): void {
                    $drop = array_values(array_filter(['billed_valid_30', 'debit_no_pay_30', 'debit_no_pay_60', 'debit_no_pay_90', 'debit_no_pay_180'], fn (string $column) => Schema::hasColumn($name, $column)));
                    $drop === [] || $table->dropColumn($drop);
                });
            }
        }

        if (Schema::hasColumn('commercial_customer_batches', 'rollup_version')) {
            Schema::table('commercial_customer_batches', fn (Blueprint $table) => $table->dropColumn('rollup_version'));
        }
    }
};
