<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces LOAN DEDUCTIONS' opening-balance columns.
        if (! Schema::hasTable('credit_union_loans')) {
            Schema::create('credit_union_loans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('credit_union_members')->restrictOnDelete();
                $table->string('loan_number')->unique();
                $table->decimal('principal_amount', 15, 2);
                $table->decimal('interest_amount', 15, 2)->default(0);
                $table->decimal('total_repayable', 15, 2)->default(0);
                // Snapshotted per loan so a future policy change never rewrites an existing loan.
                $table->decimal('interest_rate', 5, 2)->default(0);
                $table->unsignedSmallInteger('term_months');
                $table->decimal('monthly_installment_amount', 15, 2)->default(0);
                $table->decimal('savings_balance_at_application', 15, 2)->default(0);
                $table->decimal('no_guarantor_limit', 15, 2)->default(0);
                $table->decimal('guarantor_shortfall', 15, 2)->default(0);
                $table->boolean('requires_guarantor')->default(false)->index();
                $table->string('status')->default('pending')->index();
                $table->text('purpose')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('disbursed_at')->nullable();
                $table->string('disbursement_reference')->nullable();
                $table->decimal('outstanding_balance', 15, 2)->default(0);
                $table->timestamps();

                $table->index(['member_id', 'status']);
            });
        }

        // Multiple rows per loan are normal: guarantors may split the shortfall between them.
        if (! Schema::hasTable('credit_union_loan_guarantors')) {
            Schema::create('credit_union_loan_guarantors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->constrained('credit_union_loans')->cascadeOnDelete();
                $table->foreignId('guarantor_member_id')->constrained('credit_union_members')->restrictOnDelete();
                $table->decimal('guaranteed_amount', 15, 2);
                $table->decimal('guarantor_asset_balance_at_guarantee', 15, 2)->default(0);
                $table->string('status')->default('pending')->index();
                $table->string('disqualified_reason')->nullable();
                $table->timestamp('eligibility_checked_at')->nullable();
                $table->boolean('was_in_good_standing')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();

                $table->unique(['loan_id', 'guarantor_member_id'], 'cu_loan_guarantor_unique');
                $table->index(['guarantor_member_id', 'status'], 'cu_loan_guarantor_member_status_idx');
            });
        }

        // Replaces LOAN DEDUCTIONS' monthly columns + the CASH SHEET "LOAN" rows.
        if (! Schema::hasTable('credit_union_loan_repayments')) {
            Schema::create('credit_union_loan_repayments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->constrained('credit_union_loans')->cascadeOnDelete();
                $table->decimal('amount', 15, 2);
                $table->date('repayment_date')->index();
                $table->string('source')->default('cash')->index();
                $table->foreignId('deduction_batch_id')->nullable()->constrained('credit_union_deduction_batches')->nullOnDelete();
                $table->string('reference_no')->nullable();
                $table->decimal('balance_after', 15, 2)->default(0);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['loan_id', 'repayment_date'], 'cu_loan_repayment_loan_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_union_loan_repayments');
        Schema::dropIfExists('credit_union_loan_guarantors');
        Schema::dropIfExists('credit_union_loans');
    }
};
