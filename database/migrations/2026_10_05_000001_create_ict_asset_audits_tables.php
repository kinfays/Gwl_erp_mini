<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_asset_audits')) {
            Schema::create('ict_asset_audits', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('scope_device_category')->nullable();
                $table->foreignId('scope_region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->foreignId('scope_district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('status')->default('in_progress')->index();
                $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->decimal('reconciliation_rate', 5, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ict_asset_audit_lines')) {
            Schema::create('ict_asset_audit_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ict_asset_audit_id')->constrained('ict_asset_audits')->cascadeOnDelete();
                $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
                // Snapshot taken when the audit starts, so a later edit elsewhere cannot change what is checked against.
                $table->string('expected_status')->nullable();
                $table->foreignId('expected_assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('expected_district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('result')->nullable()->index(); // null = pending | matched | mismatch | not_found
                $table->string('actual_status')->nullable();
                $table->foreignId('actual_assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('actual_district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->string('actual_location')->nullable();
                $table->text('mismatch_reason')->nullable();
                $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->boolean('correction_applied')->default(false);
                $table->timestamps();

                $table->index(['ict_asset_audit_id', 'result']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ict_asset_audit_lines');
        Schema::dropIfExists('ict_asset_audits');
    }
};
