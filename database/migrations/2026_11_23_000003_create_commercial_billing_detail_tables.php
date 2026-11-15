<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8d: the Detailed Billing Report (rptBillingDetails_ExP; report type billing_details in commercial_import_batches). One row per
 * CUSTOMER in a file of about 67,000, so the customers are NOT stored: the importer streams the file, aggregates it in memory and keeps
 *
 *   commercial_billing_detail_rollups   one row per (route heading, category, status, meter status, bill status): sums and counts
 *   commercial_billing_detail_bands     consumption bands per (district, category)
 *   commercial_billing_detail_issues    capped exception lists: ACCOUNT NUMBERS and the figure that put them on the list, never a name
 *   commercial_billing_detail_accounts  a staging table: every account of one file with its route, so a duplicate account can be found
 *                                       without a PHP array of 67,000 accounts and the customer list can be linked at the end. Emptied
 *                                       when the batch goes live, is discarded or fails.
 *
 * Never stored: names, addresses, phones, meter numbers (the importer does not read those cells at all). Money is decimal(15,2) in cedis
 * (the unit of commercial_billing_routes); the importer adds in whole pesewas so a sum has no floating-point drift. Rollups and bands
 * follow their batch (cascade); issue rows are purged by age (commercial:billing-details:purge).
 *
 * route_id / district_id are the CANONICAL route of the route master (RouteResolver, source billing) and are NULL while the district a
 * heading prints is not known yet; route_label_raw / district_label_raw keep what was printed so the batch page can place them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_billing_detail_rollups')) {
            Schema::create('commercial_billing_detail_rollups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                // commercial_routes.id is an INT UNSIGNED (Phase 1), so this column must be too: a BIGINT column cannot hold a foreign key to it on MySQL
                $table->unsignedInteger('route_id')->nullable();
                $table->foreign('route_id')->references('id')->on('commercial_routes')->nullOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('route_label_raw', 120);                  // as printed ("AMASAMAN 4601")
                $table->string('district_label_raw', 120)->nullable();
                $table->string('category_code', 12)->default('');
                $table->string('customer_status', 12)->default('');      // the status code as printed (ACTB, DISC ...)
                $table->string('meter_status', 12)->default('');         // W / F / N, or '' when blank
                $table->string('bill_status', 12)->default('');          // REGULAR / ESTIMATED / ESTIMATE / UNBILLED (upper case, as printed)

                $table->unsignedInteger('customers')->default(0);
                $table->decimal('consume', 15, 3)->default(0);
                $table->decimal('water_charge', 15, 2)->default(0);
                $table->decimal('rural_water', 15, 2)->default(0);
                $table->decimal('fire_fighting', 15, 2)->default(0);
                $table->decimal('sewage', 15, 2)->default(0);
                $table->decimal('service_charge', 15, 2)->default(0);
                $table->decimal('month_total', 15, 2)->default(0);
                $table->decimal('previous_balance', 15, 2)->default(0);
                $table->decimal('paid_this_month', 15, 2)->default(0);
                $table->decimal('prev_month_payment', 15, 2)->default(0);
                $table->decimal('offset_payment', 15, 2)->default(0);
                $table->decimal('adjusted', 15, 2)->default(0);
                $table->decimal('balance_due', 15, 2)->default(0);      // negative = a credit

                $table->unsignedInteger('zero_consume')->default(0);
                $table->unsignedInteger('billed_unpaid')->default(0);   // month total above zero and nothing paid this month
                $table->decimal('billed_unpaid_amount', 15, 2)->default(0);
                $table->unsignedInteger('owing')->default(0);           // balance due above zero
                $table->unsignedInteger('credit')->default(0);          // balance due below zero
                $table->decimal('owing_amount', 15, 2)->default(0);
                $table->decimal('credit_amount', 15, 2)->default(0);    // the credits as a positive number: never netted off the owing
                $table->unsignedInteger('adjusted_count')->default(0);  // customers with an adjustment (not zero)
                $table->unsignedInteger('tariff_checked')->default(0);  // customers with a rate and a consumption: the ones B10 can test
                $table->unsignedInteger('tariff_mismatch')->default(0);
                $table->unsignedInteger('read_days_0_30')->default(0);  // days from the last read date to the END of the bill period
                $table->unsignedInteger('read_days_31_60')->default(0);
                $table->unsignedInteger('read_days_61_90')->default(0);
                $table->unsignedInteger('read_days_over_90')->default(0);
                $table->unsignedInteger('read_never')->default(0);      // 2000-01-01 and blanks
                $table->unsignedInteger('bill_month_outside')->default(0);   // Bill Month outside the batch's period
                $table->unsignedInteger('components_off')->default(0);  // month total is not the five charges added
                $table->unsignedInteger('negative_consume')->default(0);

                $table->unique(['batch_id', 'route_label_raw', 'category_code', 'customer_status', 'meter_status', 'bill_status'], 'comm_bd_rollups_key_unique');
                $table->index(['batch_id', 'district_id'], 'comm_bd_rollups_batch_district_idx');
                $table->index(['batch_id', 'route_id'], 'comm_bd_rollups_batch_route_idx');
            });
        }

        if (! Schema::hasTable('commercial_billing_detail_bands')) {
            Schema::create('commercial_billing_detail_bands', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('district_key', 125);                    // the district id as text, or "l:<label printed>" while the district is unknown
                $table->string('category_code', 12)->default('');
                $table->string('band', 12);                              // 0, 1-10, 11-20, 21-50, 51-100, 101-500, 500+
                $table->unsignedInteger('customers')->default(0);
                $table->decimal('consume', 15, 3)->default(0);

                $table->unique(['batch_id', 'district_key', 'category_code', 'band'], 'comm_bd_bands_key_unique');
                $table->index(['batch_id', 'district_id'], 'comm_bd_bands_batch_district_idx');
            });
        }

        if (! Schema::hasTable('commercial_billing_detail_issues')) {
            Schema::create('commercial_billing_detail_issues', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->string('kind', 30);
                $table->char('account_no', 12);
                // commercial_routes.id is an INT UNSIGNED (Phase 1), so this column must be too: a BIGINT column cannot hold a foreign key to it on MySQL
                $table->unsignedInteger('route_id')->nullable();
                $table->foreign('route_id')->references('id')->on('commercial_routes')->nullOnDelete();
                $table->string('route_label_raw', 120)->default('');
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('district_label_raw', 120)->default('');
                $table->decimal('amount', 15, 2)->default(0);           // the figure that put the account on the list (see the design, 8.39)
                $table->timestamp('created_at')->nullable();

                $table->index(['batch_id', 'kind', 'district_id'], 'comm_bd_issues_batch_kind_district_idx');
                $table->index('created_at', 'comm_bd_issues_created_idx');
            });
        }

        if (! Schema::hasTable('commercial_billing_detail_accounts')) {
            Schema::create('commercial_billing_detail_accounts', function (Blueprint $table): void {
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->char('account_no', 12);
                $table->unsignedSmallInteger('route_seq');               // which route heading of the file (batch.control_totals.route_labels)

                $table->unique(['batch_id', 'account_no'], 'comm_bd_accounts_batch_account_unique');
                $table->index(['batch_id', 'route_seq'], 'comm_bd_accounts_batch_route_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_billing_detail_accounts');
        Schema::dropIfExists('commercial_billing_detail_issues');
        Schema::dropIfExists('commercial_billing_detail_bands');
        Schema::dropIfExists('commercial_billing_detail_rollups');
    }
};
