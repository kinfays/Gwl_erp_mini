<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last step of a letter: the holder records who took the hardcopy and when. The addressee needs no login, the
 * holder records the paper signature; it is either a staff member (delivered_to_employee_id) or an outside party by
 * name (delivered_to_name), exactly one of them (enforced in LetterWorkflowService::deliver()).
 *
 * A letter can be delivered more than once over its life (closed, reopened, delivered again), so there is no unique
 * index on letter_id and the history stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_deliveries') || ! Schema::hasTable('mail_letters') || ! Schema::hasTable('employees')) {
            return;
        }

        Schema::create('letter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('mail_letters')->cascadeOnDelete();
            $table->foreignId('delivered_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('delivered_to_name')->nullable();
            $table->foreignId('delivered_by_id')->constrained('employees')->restrictOnDelete();
            $table->timestamp('delivered_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['letter_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_deliveries');
    }
};
