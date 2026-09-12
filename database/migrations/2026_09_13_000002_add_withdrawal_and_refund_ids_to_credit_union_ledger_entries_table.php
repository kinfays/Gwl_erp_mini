<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Phase 1 ledger table deliberately left out the withdrawal_id and refund_id links
 * because their parent tables did not exist yet. Now that they do, add the columns here
 * rather than editing the already-merged create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_union_ledger_entries')) {
            return;
        }

        if (Schema::hasTable('credit_union_withdrawal_requests')
            && ! Schema::hasColumn('credit_union_ledger_entries', 'withdrawal_id')) {
            Schema::table('credit_union_ledger_entries', function (Blueprint $table) {
                $table->foreignId('withdrawal_id')
                    ->nullable()
                    ->after('deduction_batch_id')
                    ->constrained('credit_union_withdrawal_requests')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('credit_union_refunds')
            && ! Schema::hasColumn('credit_union_ledger_entries', 'refund_id')) {
            Schema::table('credit_union_ledger_entries', function (Blueprint $table) {
                $table->foreignId('refund_id')
                    ->nullable()
                    ->after('withdrawal_id')
                    ->constrained('credit_union_refunds')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_union_ledger_entries', 'refund_id')) {
            Schema::table('credit_union_ledger_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('refund_id');
            });
        }

        if (Schema::hasColumn('credit_union_ledger_entries', 'withdrawal_id')) {
            Schema::table('credit_union_ledger_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('withdrawal_id');
            });
        }
    }
};
