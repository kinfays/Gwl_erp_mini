<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces the WITHDRAWALS sheet, as an actual approve-then-pay workflow.
        if (! Schema::hasTable('credit_union_withdrawal_requests')) {
            Schema::create('credit_union_withdrawal_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('credit_union_members')->restrictOnDelete();
                $table->decimal('savings_amount', 15, 2)->default(0);
                $table->decimal('shares_amount', 15, 2)->default(0);
                $table->text('reason')->nullable();
                $table->string('status')->default('pending')->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('requested_at')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->string('payment_method')->nullable();
                $table->string('payment_reference')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'status']);
            });
        }

        // Replaces the REFUNDS sheet (and absorbs its ad-hoc Sheet1 breakdown pattern).
        if (! Schema::hasTable('credit_union_refunds')) {
            Schema::create('credit_union_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('credit_union_members')->restrictOnDelete();
                // Which account the wrongly-deducted amount is being credited back into.
                $table->string('account_type')->default('savings')->index();
                $table->decimal('amount', 15, 2);
                $table->string('reason');
                $table->date('refunded_at')->index();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['member_id', 'refunded_at']);
            });
        }

        // Replaces CASH SHEET + the per-member rows of CHEQUE REGISTER. member_id is
        // nullable because a receipt can arrive before it is matched to a member (the
        // bulk GWCL remittance itself belongs on a deduction batch, never here).
        if (! Schema::hasTable('credit_union_manual_receipts')) {
            Schema::create('credit_union_manual_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->nullable()->constrained('credit_union_members')->nullOnDelete();
                $table->string('method')->index();
                $table->string('purpose')->index();
                $table->string('cheque_no')->nullable();
                $table->string('payer_name')->nullable();
                $table->decimal('amount', 15, 2);
                $table->date('received_date')->index();
                $table->date('banked_date')->nullable();
                $table->boolean('banked')->default(false)->index();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('remarks')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'received_date'], 'cu_receipts_member_date_idx');
                $table->index(['purpose', 'banked'], 'cu_receipts_purpose_banked_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_union_manual_receipts');
        Schema::dropIfExists('credit_union_refunds');
        Schema::dropIfExists('credit_union_withdrawal_requests');
    }
};
