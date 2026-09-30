<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transmittal: one hand-over of several letters from one holder to one recipient. Each letter in it is still an
 * ordinary routing_histories hop (which gets a batch_id); this row only groups them and carries the sheet number.
 * confirmed_count is maintained by LetterWorkflowService; completed_at is set when no line is left unconfirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_dispatch_batches')) {
            return;
        }

        Schema::create('letter_dispatch_batches', function (Blueprint $table) {
            $table->id();
            // 'TR-<year>-<id, 6 digits>', set from the id inside the creating transaction, hence nullable at insert.
            $table->string('batch_no')->nullable()->unique();
            $table->foreignId('from_secretariat_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('to_secretariat_id')->constrained('employees')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('letters_count');
            $table->unsignedSmallInteger('confirmed_count')->default(0);
            $table->timestamp('dispatched_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['to_secretariat_id', 'completed_at']);
            $table->index(['from_secretariat_id', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_dispatch_batches');
    }
};
