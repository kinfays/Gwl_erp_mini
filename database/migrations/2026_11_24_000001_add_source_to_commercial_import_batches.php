<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8h: a billing summary can be DERIVED from a Detailed Billing Report (design 8.52). `source` says where a batch's rows came from
 * ('upload' = a workbook somebody uploaded, the default and what every existing batch is; 'derived' = built by BillingSummaryFromDetails from the
 * rollups of a billing_details batch, no workbook), and `derived_from_batch_id` names that source batch. The foreign key is named by hand: it
 * points at the table it lives in and must stay under MySQL's 64-character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_import_batches')) {
            return;
        }

        Schema::table('commercial_import_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('commercial_import_batches', 'source')) {
                $table->string('source', 12)->default('upload')->after('report_type');
            }

            if (! Schema::hasColumn('commercial_import_batches', 'derived_from_batch_id')) {
                $table->unsignedBigInteger('derived_from_batch_id')->nullable()->after('supersedes_batch_id');
                $table->foreign('derived_from_batch_id', 'comm_batches_derived_from_fk')->references('id')->on('commercial_import_batches')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('commercial_import_batches')) {
            return;
        }

        Schema::table('commercial_import_batches', function (Blueprint $table): void {
            if (Schema::hasColumn('commercial_import_batches', 'derived_from_batch_id')) {
                $table->dropForeign('comm_batches_derived_from_fk');
                $table->dropColumn('derived_from_batch_id');
            }

            if (Schema::hasColumn('commercial_import_batches', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
