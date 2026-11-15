<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8e, step 0.2 (design 8.42): what the link between a Detailed Billing Report and the customer list can say about the accounts.
 *
 *   commercial_billing_detail_accounts (the staging table) gains the account's STATUS CODE and SERVICE CATEGORY CODE as billed (codes only,
 *       nothing personal), so that when the customer list is linked, every linked account's billing-time status and category can be set
 *       against its CURRENT ones.
 *   commercial_billing_detail_transitions   per batch, ROUTE (and its district), from status/category (billed) to status/category (the list now):
 *       how many accounts. The route is in the key because X6 needs "billed as active, now disconnected" per route and per CCA.
 *   commercial_billing_detail_district_matrix   per batch: the district of the billing route against the district the customer list puts
 *       the same account in (customers.district_id): how many accounts. The evidence for "which system owns a route's district" (Q12).
 *
 * Every constraint name here must be at most 64 characters (MySQL): Laravel's default name is table_column_foreign, which the matrix table's two district columns exceed, so those are named by hand.
 * No name, address, phone or meter number is ever stored. route_id references commercial_routes.id, an INT UNSIGNED (Phase 1), so it is
 * unsignedInteger with an explicit foreign key (a BIGINT cannot hold a foreign key to it on MySQL: design 8.39.13).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_billing_detail_accounts')) {
            Schema::table('commercial_billing_detail_accounts', function (Blueprint $table): void {
                if (! Schema::hasColumn('commercial_billing_detail_accounts', 'status_code')) {
                    $table->string('status_code', 12)->default('');
                }

                if (! Schema::hasColumn('commercial_billing_detail_accounts', 'category_code')) {
                    $table->string('category_code', 12)->default('');
                }
            });
        }

        if (! Schema::hasTable('commercial_billing_detail_transitions')) {
            Schema::create('commercial_billing_detail_transitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->unsignedInteger('route_id');
                $table->foreign('route_id')->references('id')->on('commercial_routes')->cascadeOnDelete();
                $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();   // the district of the billing route
                $table->string('from_status', 12)->default('');
                $table->string('to_status', 12)->default('');
                $table->string('from_category', 12)->default('');
                $table->string('to_category', 12)->default('');
                $table->unsignedInteger('customers')->default(0);

                $table->unique(['batch_id', 'route_id', 'from_status', 'to_status', 'from_category', 'to_category'], 'comm_bd_transitions_key_unique');
                $table->index(['batch_id', 'district_id'], 'comm_bd_transitions_batch_district_idx');
            });
        }

        if (! Schema::hasTable('commercial_billing_detail_district_matrix')) {
            Schema::create('commercial_billing_detail_district_matrix', function (Blueprint $table): void {
                $table->id();
                // the foreign keys are named by hand: Laravel's own names for these two columns are longer than MySQL's 64 characters (SQLite never complains)
                $table->foreignId('batch_id')->constrained('commercial_import_batches', indexName: 'comm_bd_matrix_batch_fk')->cascadeOnDelete();
                $table->foreignId('billing_district_id')->constrained('districts', indexName: 'comm_bd_matrix_billing_district_fk')->cascadeOnDelete();
                $table->foreignId('list_district_id')->constrained('districts', indexName: 'comm_bd_matrix_list_district_fk')->cascadeOnDelete();
                $table->unsignedInteger('customers')->default(0);

                $table->unique(['batch_id', 'billing_district_id', 'list_district_id'], 'comm_bd_matrix_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_billing_detail_district_matrix');
        Schema::dropIfExists('commercial_billing_detail_transitions');

        if (Schema::hasTable('commercial_billing_detail_accounts')) {
            Schema::table('commercial_billing_detail_accounts', function (Blueprint $table): void {
                foreach (['status_code', 'category_code'] as $column) {
                    if (Schema::hasColumn('commercial_billing_detail_accounts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
