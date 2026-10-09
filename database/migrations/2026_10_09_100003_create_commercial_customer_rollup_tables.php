<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-aggregated facts, written once per batch by set-based SQL and never changed afterwards (voiding a batch deletes its
 * rows). Every dashboard, trend and comparison reads these, never the customers table.
 *
 * Money is in pesewas (bigint); consumption sums are decimal. Counts that depend on "days since" are measured against the
 * batch's as_of_date, so a rollup stays true however old it gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_rollups')) {
            Schema::create('commercial_customer_rollups', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedSmallInteger('region_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedInteger('route_id');
                $table->unsignedSmallInteger('category_id');
                $table->unsignedSmallInteger('status_id');
                $table->unsignedSmallInteger('meter_status_id');

                foreach (['customer_count', 'debit_count', 'credit_count', 'billed_count', 'never_read', 'never_billed', 'never_paid', 'zero_bill_count', 'ghost_count', 'est_count', 'avg_count'] as $column) {
                    $table->unsignedInteger($column)->default(0);
                }

                foreach (['balance_sum', 'debit_sum', 'credit_sum', 'billed_sum', 'paid_sum'] as $column) {
                    $table->bigInteger($column)->default(0);
                }

                foreach (range(0, 7) as $bucket) {
                    $table->unsignedInteger('bucket_'.$bucket)->default(0);
                }

                foreach ([30, 60, 90, 180] as $days) {
                    $table->unsignedInteger('no_read_'.$days)->default(0);
                    $table->unsignedInteger('no_bill_'.$days)->default(0);
                    $table->unsignedInteger('no_pay_'.$days)->default(0);
                    $table->unsignedInteger('billed_unpaid_'.$days)->default(0);
                }

                $table->decimal('est_sum', 20, 3)->default(0);
                $table->decimal('avg_sum', 20, 3)->default(0);

                $table->unique(['batch_id', 'region_id', 'district_id', 'route_id', 'category_id', 'status_id', 'meter_status_id'], 'cc_rollups_grain_unique');
                $table->index(['district_id', 'batch_id']);
            });
        }

        // The same facts one level up (no route): what every district-level dashboard reads, so a company-wide screen scans a few
        // hundred rows per district, not thousands. Built from the route-level rows when a batch is rolled up.
        if (! Schema::hasTable('commercial_customer_rollups_district')) {
            Schema::create('commercial_customer_rollups_district', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedSmallInteger('region_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedSmallInteger('category_id');
                $table->unsignedSmallInteger('status_id');
                $table->unsignedSmallInteger('meter_status_id');

                foreach (['customer_count', 'debit_count', 'credit_count', 'billed_count', 'never_read', 'never_billed', 'never_paid', 'zero_bill_count', 'ghost_count', 'est_count', 'avg_count'] as $column) {
                    $table->unsignedInteger($column)->default(0);
                }

                foreach (['balance_sum', 'debit_sum', 'credit_sum', 'billed_sum', 'paid_sum'] as $column) {
                    $table->bigInteger($column)->default(0);
                }

                foreach (range(0, 7) as $bucket) {
                    $table->unsignedInteger('bucket_'.$bucket)->default(0);
                }

                foreach ([30, 60, 90, 180] as $days) {
                    $table->unsignedInteger('no_read_'.$days)->default(0);
                    $table->unsignedInteger('no_bill_'.$days)->default(0);
                    $table->unsignedInteger('no_pay_'.$days)->default(0);
                    $table->unsignedInteger('billed_unpaid_'.$days)->default(0);
                }

                $table->decimal('est_sum', 20, 3)->default(0);
                $table->decimal('avg_sum', 20, 3)->default(0);

                $table->unique(['batch_id', 'region_id', 'district_id', 'category_id', 'status_id', 'meter_status_id'], 'cc_rollups_d_grain_unique');
                $table->index(['district_id', 'batch_id']);
            });
        }

        // The accounts behind the duplicate-style quality counts (shared meter / mobile / e-mail), stored when the batch is
        // rolled up so a drill-down is an index read and no screen ever groups a district's customers to find them.
        if (! Schema::hasTable('commercial_customer_issue_accounts')) {
            Schema::create('commercial_customer_issue_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('batch_id');
                $table->string('issue', 30);
                $table->unsignedBigInteger('customer_id');

                $table->primary(['batch_id', 'issue', 'customer_id']);
            });
        }

        if (! Schema::hasTable('commercial_customer_consumption')) {
            Schema::create('commercial_customer_consumption', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedSmallInteger('category_id');
                $table->unsignedInteger('customers')->default(0);               // billing customers with an average consumption
                $table->decimal('median_average', 14, 3)->nullable();
                $table->unsignedInteger('outliers')->default(0);                // above the outlier multiple of the category median
                $table->unsignedInteger('zero_consume_billing')->default(0);    // billing status, average consumption 0
                $table->unsignedInteger('factor_not_one')->default(0);
                $table->unsignedInteger('factor_odd')->default(0);              // factor outside 0.1 .. 100 (plausibility)

                foreach (range(0, 5) as $bin) {
                    $table->unsignedInteger('bin_'.$bin)->default(0);            // distribution of average consumption
                }

                $table->unique(['batch_id', 'district_id', 'category_id'], 'cc_consumption_unique');
            });
        }

        if (! Schema::hasTable('commercial_customer_quality')) {
            Schema::create('commercial_customer_quality', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedSmallInteger('district_id');
                $table->string('issue', 30);
                $table->unsignedInteger('issue_count')->default(0);

                $table->unique(['batch_id', 'district_id', 'issue'], 'cc_quality_unique');
            });
        }

        if (! Schema::hasTable('commercial_customer_debt_stats')) {
            Schema::create('commercial_customer_debt_stats', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedInteger('customers')->default(0);
                $table->unsignedInteger('debtors')->default(0);
                $table->bigInteger('total_debit')->default(0);
                $table->bigInteger('median_balance')->nullable();      // all customers, pesewas
                $table->bigInteger('median_debit')->nullable();        // debtors only, pesewas
                $table->bigInteger('top1_sum')->default(0);            // what the top 1% / 5% / 10% of debtors owe
                $table->bigInteger('top5_sum')->default(0);
                $table->bigInteger('top10_sum')->default(0);

                $table->unique(['batch_id', 'district_id'], 'cc_debt_unique');
            });
        }

        if (! Schema::hasTable('commercial_customer_connections')) {
            Schema::create('commercial_customer_connections', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedSmallInteger('category_id');
                $table->date('connect_month');
                $table->unsignedInteger('customers')->default(0);
                $table->unsignedInteger('billing_customers')->default(0);

                $table->unique(['batch_id', 'district_id', 'category_id', 'connect_month'], 'cc_connections_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['commercial_customer_issue_accounts', 'commercial_customer_rollups_district', 'commercial_customer_debt_stats', 'commercial_customer_connections', 'commercial_customer_quality', 'commercial_customer_consumption', 'commercial_customer_rollups'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
