<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per uploaded report file: the unit of audit, void and supersede.
        if (! Schema::hasTable('commercial_import_batches')) {
            Schema::create('commercial_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('report_type')->index();
                $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->string('region_label_raw')->nullable();
                $table->date('period_from');
                $table->date('period_to');
                $table->string('granularity')->default('monthly');
                $table->string('billing_status_raw')->nullable();
                $table->string('customer_segment')->nullable();
                $table->string('source_filename');
                $table->string('file_path')->nullable();
                $table->string('file_hash', 64)->unique();
                $table->string('status')->default('imported')->index();
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('warning_count')->default(0);
                $table->json('control_totals')->nullable();
                $table->boolean('reconciliation_passed')->default(false);
                $table->foreignId('supersedes_batch_id')->nullable()->constrained('commercial_import_batches')->nullOnDelete();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('imported_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->text('void_reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['report_type', 'region_id', 'status'], 'comm_batches_type_region_status_idx');
            });
        }

        // Verified customer strength is region-wide, so it is one row per month, not per reader.
        if (! Schema::hasTable('commercial_reading_strengths')) {
            Schema::create('commercial_reading_strengths', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->date('month');
                $table->unsignedInteger('verified_strength');
                $table->timestamps();

                $table->unique(['batch_id', 'month'], 'comm_strengths_batch_month_unique');
            });
        }

        // The source's percentage and Unvisited columns are deliberately not stored: they are measured against the
        // region's whole strength and mean nothing per reader. Rates are recomputed from these counts.
        if (! Schema::hasTable('commercial_reading_stats')) {
            Schema::create('commercial_reading_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->date('month');
                $table->string('reader_staff_id', 50);
                $table->string('reader_name_raw')->nullable();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                // The reader's home district in the directory at import time.
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->unsignedInteger('read_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('visited_count')->default(0);
                $table->string('match_status')->default('matched');
                $table->timestamps();

                $table->unique(['batch_id', 'month', 'reader_staff_id'], 'comm_stats_batch_month_reader_unique');
                $table->index(['month', 'employee_id'], 'comm_stats_month_employee_idx');
                $table->index(['reader_staff_id', 'month'], 'comm_stats_reader_month_idx');
                $table->index(['batch_id', 'match_status'], 'comm_stats_batch_match_idx');
            });
        }

        // Collection Ratio is not stored either: it is recomputed (and is #VALUE! on zero-billing routes in the source).
        if (! Schema::hasTable('commercial_billing_routes')) {
            Schema::create('commercial_billing_routes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('district_label_raw');
                $table->string('route_code');

                // Volumes, in thousand litres.
                $table->decimal('volume_actual', 15, 2)->nullable();
                $table->decimal('volume_average', 15, 2)->nullable();
                $table->decimal('volume_total', 15, 2)->nullable();

                // Amounts, GH¢. Balances can be negative.
                $table->decimal('opening_balance', 15, 2)->nullable();
                $table->decimal('billing_for_period', 15, 2)->nullable();
                $table->decimal('total_receivable', 15, 2)->nullable();
                $table->decimal('revenue_adjustment', 15, 2)->nullable();
                $table->decimal('payment_for_month', 15, 2)->nullable();
                $table->decimal('prev_month_payment', 15, 2)->nullable();
                $table->decimal('offset_payments', 15, 2)->nullable();
                $table->decimal('total_payments', 15, 2)->nullable();
                $table->decimal('closing_balance', 15, 2)->nullable();

                // "Number Of Customers" does not reconcile to the billed count; its meaning is an open question.
                $table->unsignedInteger('customers_count')->nullable();

                $table->unsignedInteger('billed_average_metered')->nullable();
                $table->unsignedInteger('billed_average_unmetered')->nullable();
                $table->unsignedInteger('billed_actual_reading')->nullable();
                $table->unsignedInteger('billed_total')->nullable();

                $table->unsignedInteger('unbilled_suspense_metered')->nullable();
                $table->unsignedInteger('unbilled_suspense_unmetered')->nullable();
                $table->unsignedInteger('unbilled_disconn_metered')->nullable();
                $table->unsignedInteger('unbilled_disconn_unmetered')->nullable();
                $table->unsignedInteger('unbilled_other')->nullable();
                $table->unsignedInteger('unbilled_total')->nullable();

                $table->timestamps();

                $table->unique(['batch_id', 'district_label_raw', 'route_code'], 'comm_routes_batch_district_route_unique');
                $table->index(['district_id', 'batch_id'], 'comm_routes_district_batch_idx');
            });
        }

        if (! Schema::hasTable('commercial_billing_bands')) {
            Schema::create('commercial_billing_bands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
                $table->string('category_code', 10);
                $table->string('band', 20);
                $table->unsignedInteger('customers')->default(0);
                $table->decimal('volume', 15, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->timestamps();

                $table->index(['batch_id', 'category_code'], 'comm_bands_batch_category_idx');
            });
        }

        // Resolving an unmatched region/district once writes a row here so every later upload matches by itself.
        if (! Schema::hasTable('commercial_location_aliases')) {
            Schema::create('commercial_location_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20);
                $table->string('alias_normalized');
                $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['kind', 'alias_normalized'], 'comm_aliases_kind_alias_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_location_aliases');
        Schema::dropIfExists('commercial_billing_bands');
        Schema::dropIfExists('commercial_billing_routes');
        Schema::dropIfExists('commercial_reading_stats');
        Schema::dropIfExists('commercial_reading_strengths');
        Schema::dropIfExists('commercial_import_batches');
    }
};
