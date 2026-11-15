<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8b: a customer-list file may hold one district, several, or every district of a region. The FILE becomes a parent record
 * (commercial_customer_uploads: the stored workbook, its checksum, the as-of date, what the first cheap pass found in it and the overall
 * progress); every district found in it gets ONE child batch (the existing commercial_customer_batches row: its own district, lock,
 * phases, undo data, control totals), tied to the parent by upload_id.
 *
 * Existing batches are each given a parent of their own (one file, one district), so the history is uniform. A migration is never run
 * with data it cannot read again: the parents are created from the batches' own columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_uploads')) {
            Schema::create('commercial_customer_uploads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnUpdate()->nullOnDelete();
                $table->string('region_label_raw', 120)->nullable();
                $table->string('source_filename', 255)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->char('file_hash', 64)->index();
                $table->date('as_of_date');
                $table->string('period_type', 10)->default('monthly');          // weekly | monthly
                $table->string('period_key', 10)->nullable();
                // queued discovering awaiting_review needs_match processing imported partial blocked failed voided
                $table->string('status', 20)->default('queued');
                // queued discover review stage children done
                $table->string('phase', 20)->default('queued');
                $table->boolean('review')->default(false);                      // the person asked to see the districts before anything is loaded
                $table->unsignedSmallInteger('districts_found')->default(0);
                $table->unsignedSmallInteger('districts_selected')->default(0);
                $table->unsignedInteger('routes_found')->default(0);
                $table->unsignedInteger('rows_found')->default(0);               // customer rows the first pass counted
                $table->unsignedInteger('rows_skipped')->default(0);             // rows of districts that were not loaded (not yours, unticked, blocked)
                $table->json('districts')->nullable();                            // what discovery found, per district (names and counts, never a customer)
                $table->json('unrecognised')->nullable();                         // district names nobody could place, with their row counts
                $table->json('errors')->nullable();
                $table->json('warnings')->nullable();
                $table->string('error_message', 500)->nullable();                 // never holds customer data
                $table->text('notes')->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['region_id', 'status']);
            });
        }

        if (Schema::hasTable('commercial_customer_batches') && ! Schema::hasColumn('commercial_customer_batches', 'upload_id')) {
            Schema::table('commercial_customer_batches', function (Blueprint $table): void {
                $table->foreignId('upload_id')->nullable()->after('id')->constrained('commercial_customer_uploads')->nullOnDelete();
            });
        }

        $this->adoptExistingBatches();
    }

    /** One parent per batch that has none yet (a file that held one district). */
    protected function adoptExistingBatches(): void
    {
        if (! Schema::hasTable('commercial_customer_batches') || ! Schema::hasColumn('commercial_customer_batches', 'upload_id')) {
            return;
        }

        DB::table('commercial_customer_batches')->whereNull('upload_id')->orderBy('id')->chunkById(200, function ($batches): void {
            foreach ($batches as $batch) {
                $inFlight = in_array($batch->status, ['queued', 'processing', 'needs_match', 'failed'], true) && $batch->file_path !== null;
                $status = match ($batch->status) {
                    'superseded' => 'imported',
                    default => $batch->status,
                };

                $uploadId = DB::table('commercial_customer_uploads')->insertGetId([
                    'region_id' => $batch->region_id, 'region_label_raw' => $batch->region_label_raw, 'source_filename' => $batch->source_filename,
                    'file_path' => $batch->file_path, 'file_hash' => $batch->file_hash, 'as_of_date' => $batch->as_of_date, 'period_type' => $batch->period_type,
                    'period_key' => $batch->period_key, 'status' => $status, 'phase' => $inFlight ? 'discover' : 'done', 'review' => false,
                    'districts_found' => $batch->district_id ? 1 : 0, 'districts_selected' => $batch->district_id ? 1 : 0, 'rows_found' => $batch->rows_read,
                    'notes' => $batch->notes, 'imported_by' => $batch->imported_by, 'started_at' => $batch->started_at, 'finished_at' => $batch->finished_at,
                    'created_at' => $batch->created_at, 'updated_at' => $batch->updated_at,
                ]);

                DB::table('commercial_customer_batches')->where('id', $batch->id)->update(['upload_id' => $uploadId]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('commercial_customer_batches', 'upload_id')) {
            Schema::table('commercial_customer_batches', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('upload_id');
            });
        }

        Schema::dropIfExists('commercial_customer_uploads');
    }
};
