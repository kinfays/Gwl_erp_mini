<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional scanned copies of a letter's hardcopy (behind gwl.letters_scans_enabled). The file itself lives on a
 * private disk at letters/scans/{yyyy}/{mm}/{letter_id}/{uuid}.{ext}; this row is its record. Nothing is hard-deleted
 * in the app: voiding hides a scan and keeps the file and the row, for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_scans') || ! Schema::hasTable('mail_letters') || ! Schema::hasTable('employees')) {
            return;
        }

        Schema::create('letter_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('mail_letters')->cascadeOnDelete();
            $table->string('kind', 20)->default('original'); // original | commented | enclosure
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->string('note', 255)->nullable();
            $table->foreignId('uploaded_by_id')->constrained('employees')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['letter_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_scans');
    }
};
