<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit, change request 2, step I (printing): how many times a voucher, and a batch, has been printed. The footer of a
 * print says "printed n times"; only PvPrintService writes these columns (a print is not a change of the voucher, so neither the version
 * nor the timeline moves). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fin_pvs', 'fin_pv_batches'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'print_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedInteger('print_count')->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['fin_pvs', 'fin_pv_batches'] as $table) {
            if (Schema::hasColumn($table, 'print_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('print_count');
                });
            }
        }
    }
};
