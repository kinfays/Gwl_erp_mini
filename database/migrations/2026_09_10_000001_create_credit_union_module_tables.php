<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_union_members')) {
            Schema::create('credit_union_members', function (Blueprint $table) {
                $table->id();
                $table->string('member_type')->default('staff')->index();
                $table->string('member_number')->unique();
                $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
                $table->string('staff_id')->nullable()->index();
                $table->string('full_name');
                $table->string('phone')->nullable();
                $table->string('address')->nullable();
                $table->string('legacy_account_number')->nullable()->unique();
                $table->string('application_source')->default('hr_added')->index();
                $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('status')->default('active')->index();
                $table->date('registered_at')->nullable();
                $table->date('exited_at')->nullable();
                $table->string('exit_reason')->nullable();
                $table->decimal('membership_form_fee_amount', 15, 2)->default(0);
                $table->date('membership_form_fee_paid_at')->nullable();
                $table->decimal('initial_share_amount', 15, 2)->default(0);
                $table->date('initial_share_paid_at')->nullable();
                $table->decimal('default_monthly_savings_amount', 15, 2)->nullable();
                $table->decimal('default_monthly_shares_amount', 15, 2)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['member_type', 'status']);
                $table->index(['application_source', 'status']);
            });
        }

        // Replaces the SHARES + SAVINGS sections of the per-member passbook workbooks.
        // The deduction_batch_id / withdrawal_id / refund_id links from the design spec are
        // deliberately left out here: their parent tables arrive in later phases and will add
        // their own columns via add_x_to_y_table migrations rather than editing this one.
        if (! Schema::hasTable('credit_union_ledger_entries')) {
            Schema::create('credit_union_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('credit_union_members')->cascadeOnDelete();
                $table->string('account_type')->index();
                $table->string('entry_type')->index();
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_after', 15, 2)->default(0);
                $table->date('transaction_date')->index();
                $table->string('source')->default('manual_adjustment')->index();
                $table->string('reference_no')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['member_id', 'account_type', 'transaction_date'], 'cu_ledger_member_account_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_union_ledger_entries');
        Schema::dropIfExists('credit_union_members');
    }
};
