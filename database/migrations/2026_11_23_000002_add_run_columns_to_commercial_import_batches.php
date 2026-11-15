<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8d: a batch of the Detailed Billing Report (billing_details) is read in the BACKGROUND (a 130 MB sheet), so its batch row has to
 * carry what the customer list's batches carry: where the job is, how far it got, whether the person asked to look at the figures
 * before they are loaded, and why it failed. Every existing batch leaves these columns empty (a finished report has no run).
 *
 * The existing `status` column gets new VALUES for this type only (queued, processing, awaiting_review, blocked, failed); no column
 * changes. A batch counts in an analysis only while its status is imported or superseded (CommercialImportBatch::scopeLive()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_import_batches')) {
            return;
        }

        Schema::table('commercial_import_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('commercial_import_batches', 'phase')) {
                $table->string('phase', 30)->nullable()->after('status');
            }

            if (! Schema::hasColumn('commercial_import_batches', 'progress')) {
                $table->json('progress')->nullable()->after('phase');
            }

            if (! Schema::hasColumn('commercial_import_batches', 'error_message')) {
                $table->string('error_message', 255)->nullable()->after('progress');
            }

            if (! Schema::hasColumn('commercial_import_batches', 'review')) {
                $table->boolean('review')->default(false)->after('error_message');
            }

            if (! Schema::hasColumn('commercial_import_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('review');
            }

            if (! Schema::hasColumn('commercial_import_batches', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('commercial_import_batches')) {
            return;
        }

        Schema::table('commercial_import_batches', function (Blueprint $table): void {
            foreach (['finished_at', 'started_at', 'review', 'error_message', 'progress', 'phase'] as $column) {
                if (Schema::hasColumn('commercial_import_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
