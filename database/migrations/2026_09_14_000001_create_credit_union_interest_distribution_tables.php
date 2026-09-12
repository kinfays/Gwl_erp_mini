<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The annual pro-rata distribution of loan interest income back to members.
        if (! Schema::hasTable('credit_union_interest_distributions')) {
            Schema::create('credit_union_interest_distributions', function (Blueprint $table) {
                $table->id();
                $table->string('period_label');
                // The window total_interest_pool was summed over, kept so the figure can
                // be re-derived later; the balance snapshot is taken as of period_end_date.
                $table->date('period_start_date')->nullable();
                $table->date('period_end_date')->index();
                $table->decimal('total_interest_pool', 15, 2)->default(0);
                $table->string('credit_account_type')->default('savings');
                $table->string('status')->default('draft')->index();
                $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('computed_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['period_end_date', 'status']);
            });
        }

        if (! Schema::hasTable('credit_union_interest_distribution_lines')) {
            Schema::create('credit_union_interest_distribution_lines', function (Blueprint $table) {
                $table->id();
                // Constraint names are given explicitly: this table's name is long enough
                // that Laravel's generated "<table>_<column>_foreign" would exceed MySQL's
                // 64-character identifier limit.
                $table->unsignedBigInteger('interest_distribution_id');
                $table->foreign('interest_distribution_id', 'cu_interest_line_dist_fk')
                    ->references('id')
                    ->on('credit_union_interest_distributions')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('member_id');
                $table->foreign('member_id', 'cu_interest_line_member_fk')
                    ->references('id')
                    ->on('credit_union_members')
                    ->cascadeOnDelete();
                $table->decimal('asset_balance_at_computation', 15, 2)->default(0);
                $table->decimal('share_of_pool_percent', 7, 4)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                // Set once the line has actually been posted to the member's ledger.
                $table->unsignedBigInteger('ledger_entry_id')->nullable();
                $table->foreign('ledger_entry_id', 'cu_interest_line_entry_fk')
                    ->references('id')
                    ->on('credit_union_ledger_entries')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['interest_distribution_id', 'member_id'], 'cu_interest_line_unique');
                $table->index(['member_id', 'interest_distribution_id'], 'cu_interest_line_member_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_union_interest_distribution_lines');
        Schema::dropIfExists('credit_union_interest_distributions');
    }
};
