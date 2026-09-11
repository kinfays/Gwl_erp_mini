<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces BANK DRAWINGS + the implicit monthly GWCL remittance cheque.
        if (! Schema::hasTable('credit_union_deduction_batches')) {
            Schema::create('credit_union_deduction_batches', function (Blueprint $table) {
                $table->id();
                $table->date('period_month')->index();
                $table->string('bank_reference')->nullable();
                $table->date('banked_date')->nullable();
                $table->decimal('amount_received', 15, 2)->default(0);
                $table->decimal('amount_posted', 15, 2)->default(0);
                $table->string('status')->default('draft')->index();
                $table->string('import_file_path')->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('imported_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['period_month', 'status']);
            });
        }

        // Replaces one member's cell-set across LEDGER 2026 + LOAN DEDUCTIONS.
        if (! Schema::hasTable('credit_union_deduction_batch_lines')) {
            Schema::create('credit_union_deduction_batch_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deduction_batch_id')->constrained('credit_union_deduction_batches')->cascadeOnDelete();
                // Null member_id means the uploaded row matched no member and needs manual resolution.
                $table->foreignId('member_id')->nullable()->constrained('credit_union_members')->nullOnDelete();
                $table->string('staff_id_raw');
                $table->string('name_raw')->nullable();
                $table->decimal('shares_amount', 15, 2)->default(0);
                $table->decimal('savings_amount', 15, 2)->default(0);
                $table->decimal('loan_repayment_amount', 15, 2)->default(0);
                // Loans arrive in Phase 3; until then a captured loan repayment stays unposted.
                $table->boolean('loan_repayment_posted')->default(false)->index();
                $table->string('match_status')->default('matched')->index();
                $table->text('resolution_notes')->nullable();
                $table->timestamps();

                $table->index(['deduction_batch_id', 'match_status'], 'cu_batch_lines_batch_status_idx');
                $table->index(['member_id', 'deduction_batch_id'], 'cu_batch_lines_member_batch_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_union_deduction_batch_lines');
        Schema::dropIfExists('credit_union_deduction_batches');
    }
};
