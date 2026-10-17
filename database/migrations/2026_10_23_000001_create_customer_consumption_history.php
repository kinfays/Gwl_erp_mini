<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumption history for the "against the account's own history" anomaly analysis.
 *
 * commercial_customers holds only the CURRENT average consumption, so nothing could be compared with the past. Every upload now
 * leaves one narrow row per billing account and calendar month (the latest upload of a month replaces that month's row):
 *
 *   commercial_customer_consumption_history   customer_id + period_month  ->  the file's average consumption at that upload
 *
 * No name, address, phone or e-mail is kept here. Rows older than commercial_customer_history_keep_months are pruned by the
 * daily purge. The rollup then counts, per category, the accounts far above / below / at zero against their own earlier months
 * (commercial_customer_consumption.hist_*), and keeps the accounts behind those counts as drill-down lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_consumption_history')) {
            Schema::create('commercial_customer_consumption_history', function (Blueprint $table): void {
                $table->unsignedBigInteger('customer_id');
                $table->date('period_month');                       // the first day of the month of the upload's file date
                $table->unsignedSmallInteger('district_id');
                $table->unsignedBigInteger('batch_id');             // the upload that wrote the row (a void takes its rows away)
                $table->decimal('average_consume', 14, 3);

                $table->primary(['customer_id', 'period_month']);
                $table->index(['district_id', 'period_month'], 'cc_history_district_month');
                $table->index('batch_id', 'cc_history_batch');
            });
        }

        if (Schema::hasTable('commercial_customer_consumption')) {
            Schema::table('commercial_customer_consumption', function (Blueprint $table): void {
                foreach (['hist_baseline', 'hist_high', 'hist_low', 'hist_zero'] as $column) {
                    if (! Schema::hasColumn('commercial_customer_consumption', $column)) {
                        $table->unsignedInteger($column)->default(0);
                    }
                }
            });
        }

        if (Schema::hasTable('commercial_customer_batches') && ! Schema::hasColumn('commercial_customer_batches', 'history_months')) {
            Schema::table('commercial_customer_batches', function (Blueprint $table): void {
                $table->unsignedTinyInteger('history_months')->nullable();   // earlier months of history in the baseline window when the batch was built
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_customer_consumption_history');

        if (Schema::hasTable('commercial_customer_consumption')) {
            Schema::table('commercial_customer_consumption', function (Blueprint $table): void {
                $drop = array_values(array_filter(['hist_baseline', 'hist_high', 'hist_low', 'hist_zero'], fn (string $column) => Schema::hasColumn('commercial_customer_consumption', $column)));
                $drop === [] || $table->dropColumn($drop);
            });
        }

        if (Schema::hasColumn('commercial_customer_batches', 'history_months')) {
            Schema::table('commercial_customer_batches', fn (Blueprint $table) => $table->dropColumn('history_months'));
        }
    }
};
