<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer list: one narrow CURRENT-STATE row per account, PII in a separate table, a change log (not snapshots), an
 * undo table for exact rollback of the newest batch of a district, and the staging table the set-based merge reads.
 *
 * Index choices (each one slows the bulk merge, so each has a named query behind it):
 *   customers.account_no UNIQUE                 merge join + lookup by account
 *   (district_id, category_id, status_id)       base mix and every filtered list
 *   (district_id, route_id)                     route lists and the workload view
 *   (district_id, balance)                      top debtors (keyset on balance, id)
 *   (district_id, meter_status_id)              the meter-status filter of a district list
 *   (district_id, arrears_bucket)               the balance-bucket filter of a district list
 *   (district_id, missing_since_batch_id)       "not in the latest file"
 *   meter_no                                    shared-meter data-quality drill-down
 * (No region-wide index: every list is for one district and region-wide questions are answered from the rollups, so an index
 * on region_id would only slow the bulk merge.)
 * Foreign keys are deliberately absent on the hot tables (millions of rows, bulk loaded); the lookup ids are written only
 * by the importer, which resolved them itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_batches')) {
            Schema::create('commercial_customer_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->cascadeOnUpdate()->nullOnDelete();
                $table->string('region_label_raw', 120)->nullable();
                $table->string('district_label_raw', 120)->nullable();
                $table->string('period_type', 10)->default('monthly');          // weekly | monthly
                $table->string('period_key', 10)->nullable();                    // 2026-10 or 2026-W41
                $table->date('as_of_date');
                $table->string('source_filename', 255)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->char('file_hash', 64)->index();
                $table->string('status', 20)->default('queued');                 // queued processing blocked failed imported superseded voided
                $table->string('phase', 20)->default('queued');                  // queued parse reconcile merge rollup done
                $table->unsignedInteger('staged_through_row')->default(0);       // last file row committed to staging (resume point)
                $table->unsignedBigInteger('merge_cursor')->default(0);          // last staging id merged (resume point)
                $table->unsignedInteger('rows_read')->default(0);
                $table->unsignedInteger('rows_new')->default(0);
                $table->unsignedInteger('rows_changed')->default(0);
                $table->unsignedInteger('rows_unchanged')->default(0);
                $table->unsignedInteger('rows_missing')->default(0);              // in the district before, not in this file
                $table->unsignedInteger('rows_moved')->default(0);                // account seen in another district before
                $table->unsignedInteger('rows_malformed')->default(0);
                $table->unsignedInteger('duplicate_accounts')->default(0);
                $table->unsignedInteger('routes_count')->default(0);               // distinct routes in the district when it was rolled up
                $table->unsignedInteger('previous_count')->nullable();
                $table->decimal('count_change_pct', 8, 2)->nullable();
                $table->unsignedBigInteger('previous_batch_id')->nullable();      // the district's newest batch when this one was merged
                $table->json('control_totals')->nullable();                       // per-route counts / balances read vs the file's own totals
                $table->json('errors')->nullable();
                $table->json('warnings')->nullable();
                $table->boolean('reconciliation_passed')->default(false);
                $table->string('error_message', 500)->nullable();                 // never holds customer data
                $table->text('notes')->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->timestamps();

                $table->index(['district_id', 'status', 'as_of_date']);
                $table->index(['region_id', 'status']);
            });
        }

        if (! Schema::hasTable('commercial_customers')) {
            Schema::create('commercial_customers', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('account_no', 12);
                $table->unsignedSmallInteger('region_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedInteger('route_id');
                $table->unsignedSmallInteger('category_id');
                $table->unsignedSmallInteger('status_id');
                $table->unsignedSmallInteger('meter_status_id');
                $table->string('meter_no', 20)->nullable();
                $table->date('connect_date')->nullable();
                $table->bigInteger('balance')->default(0);                        // pesewas: the sign convention is unconfirmed
                $table->date('last_read_date')->nullable();
                $table->decimal('last_reading', 14, 3)->nullable();
                $table->date('last_bill_date')->nullable();
                $table->bigInteger('last_bill_amount')->nullable();               // pesewas
                $table->date('last_paid_date')->nullable();
                $table->bigInteger('last_paid_amount')->nullable();               // pesewas
                $table->decimal('estimated_consume', 14, 3)->nullable();
                $table->decimal('average_consume', 14, 3)->nullable();
                $table->decimal('meter_factor', 10, 3)->nullable();
                $table->unsignedTinyInteger('arrears_bucket')->default(0);
                $table->unsignedBigInteger('first_seen_batch_id');
                $table->unsignedBigInteger('updated_batch_id');                   // the batch that last WROTE this row (unchanged rows are not rewritten)
                $table->unsignedBigInteger('missing_since_batch_id')->nullable();  // set when a file of the district no longer lists the account
                $table->char('attributes_hash', 16);

                $table->unique('account_no');
                $table->index(['district_id', 'category_id', 'status_id']);
                $table->index(['district_id', 'route_id']);
                $table->index(['district_id', 'balance']);
                $table->index(['district_id', 'meter_status_id']);
                $table->index(['district_id', 'arrears_bucket']);
                $table->index(['district_id', 'missing_since_batch_id']);
                $table->index('meter_no');
            });
        }

        if (! Schema::hasTable('commercial_customer_contacts')) {
            Schema::create('commercial_customer_contacts', function (Blueprint $table): void {
                $table->unsignedBigInteger('customer_id')->primary();
                $table->string('account_name', 191)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('mobiles', 255)->nullable();                       // normalised, comma separated
                $table->string('phone_primary', 20)->nullable();                  // first normalised number, for search and duplicate checks
                $table->string('email', 191)->nullable();
                $table->string('email_lower', 191)->nullable();
                $table->string('name_search', 191)->nullable();                   // lower-cased name, prefix search
                $table->char('contact_hash', 16);
                $table->unsignedSmallInteger('quality_flags')->default(0);         // contact-related data-quality bits (no mobile / e-mail / address / name, invalid phone)
                $table->unsignedBigInteger('updated_batch_id');

                $table->index('phone_primary');
                $table->index('email_lower');
                $table->index('name_search');
            });
        }

        if (! Schema::hasTable('commercial_customer_changes')) {
            Schema::create('commercial_customer_changes', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('batch_id');
                // 1 status, 2 category, 3 meter status, 4 route, 5 district (moved), 6 arrears bucket, 7 new, 8 missing, 9 returned
                $table->unsignedTinyInteger('field');
                $table->integer('old_value')->nullable();
                $table->integer('new_value')->nullable();

                $table->index(['batch_id', 'field']);
                $table->index('customer_id');
            });
        }

        if (! Schema::hasTable('commercial_customer_undo')) {
            Schema::create('commercial_customer_undo', function (Blueprint $table): void {
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedSmallInteger('region_id');
                $table->unsignedSmallInteger('district_id');
                $table->unsignedInteger('route_id');
                $table->unsignedSmallInteger('category_id');
                $table->unsignedSmallInteger('status_id');
                $table->unsignedSmallInteger('meter_status_id');
                $table->string('meter_no', 20)->nullable();
                $table->date('connect_date')->nullable();
                $table->bigInteger('balance')->default(0);
                $table->date('last_read_date')->nullable();
                $table->decimal('last_reading', 14, 3)->nullable();
                $table->date('last_bill_date')->nullable();
                $table->bigInteger('last_bill_amount')->nullable();
                $table->date('last_paid_date')->nullable();
                $table->bigInteger('last_paid_amount')->nullable();
                $table->decimal('estimated_consume', 14, 3)->nullable();
                $table->decimal('average_consume', 14, 3)->nullable();
                $table->decimal('meter_factor', 10, 3)->nullable();
                $table->unsignedTinyInteger('arrears_bucket')->default(0);
                $table->unsignedBigInteger('updated_batch_id');
                $table->unsignedBigInteger('missing_since_batch_id')->nullable();
                $table->char('attributes_hash', 16);

                $table->primary(['batch_id', 'customer_id']);
            });
        }

        // Which staged rows need any work at all, found in ONE pass over the staging rows and the current customers. Every later
        // step of the merge reads only these, so a file that is mostly unchanged does almost no work after the first pass.
        // flags: 1 = the customer row changes (or returns from "missing"), 2 = the contact row is written, 4 = a new account.
        if (! Schema::hasTable('commercial_customer_diff')) {
            Schema::create('commercial_customer_diff', function (Blueprint $table): void {
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('staging_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedTinyInteger('flags');

                $table->primary(['batch_id', 'staging_id']);
            });
        }

        if (! Schema::hasTable('commercial_customer_staging')) {
            Schema::create('commercial_customer_staging', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedInteger('row_no');
                $table->char('account_no', 12);
                $table->unsignedInteger('route_id');
                $table->unsignedSmallInteger('category_id');
                $table->unsignedSmallInteger('status_id');
                $table->unsignedSmallInteger('meter_status_id');
                $table->string('meter_no', 20)->nullable();
                $table->date('connect_date')->nullable();
                $table->bigInteger('balance')->default(0);
                $table->date('last_read_date')->nullable();
                $table->decimal('last_reading', 14, 3)->nullable();
                $table->date('last_bill_date')->nullable();
                $table->bigInteger('last_bill_amount')->nullable();
                $table->date('last_paid_date')->nullable();
                $table->bigInteger('last_paid_amount')->nullable();
                $table->decimal('estimated_consume', 14, 3)->nullable();
                $table->decimal('average_consume', 14, 3)->nullable();
                $table->decimal('meter_factor', 10, 3)->nullable();
                $table->unsignedTinyInteger('arrears_bucket')->default(0);
                $table->char('attributes_hash', 16);
                // contact fields (PII): cleared with the rest of the staging rows, always
                $table->string('account_name', 191)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('mobiles', 255)->nullable();
                $table->string('phone_primary', 20)->nullable();
                $table->string('email', 191)->nullable();
                $table->string('email_lower', 191)->nullable();
                $table->string('name_search', 191)->nullable();
                $table->char('contact_hash', 16);
                $table->unsignedSmallInteger('quality_flags')->default(0);         // data-quality bits, see CustomerQuality

                $table->index(['batch_id', 'account_no']);
            });
        }
    }

    public function down(): void
    {
        foreach (['commercial_customer_diff', 'commercial_customer_staging', 'commercial_customer_undo', 'commercial_customer_changes', 'commercial_customer_contacts', 'commercial_customers', 'commercial_customer_batches'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
