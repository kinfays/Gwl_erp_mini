<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Phase 1 ledger table deliberately left out the deduction_batch_id link because its
 * parent table did not exist yet. Now that it does, add the column here rather than
 * editing the already-merged create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_union_ledger_entries') || ! Schema::hasTable('credit_union_deduction_batches')) {
            return;
        }

        if (Schema::hasColumn('credit_union_ledger_entries', 'deduction_batch_id')) {
            return;
        }

        Schema::table('credit_union_ledger_entries', function (Blueprint $table) {
            $table->foreignId('deduction_batch_id')
                ->nullable()
                ->after('source')
                ->constrained('credit_union_deduction_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('credit_union_ledger_entries', 'deduction_batch_id')) {
            return;
        }

        Schema::table('credit_union_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deduction_batch_id');
        });
    }
};
