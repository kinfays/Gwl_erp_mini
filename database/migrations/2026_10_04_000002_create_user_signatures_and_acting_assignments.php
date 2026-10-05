<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1. user_signatures: a signer's saved signature, kept as an encrypted PNG on a private disk. Never more than one
 *    active row per user; a replaced version stays (inactive) while a printed letter still points at it.
 * 2. leave_acting_assignments: someone acting in a final-approver post (chief manager, regional chief manager, MD) for a
 *    date window, optionally limited to a region or department.
 * 3. leave_requests.final_approver_capacity: whether the final approver acted as the post-holder or as acting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_signatures')) {
            Schema::create('user_signatures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('storage_path');
                $table->string('sha256', 64);
                $table->unsignedSmallInteger('width');
                $table->unsignedSmallInteger('height');
                $table->string('method', 10); // drawn | uploaded
                $table->boolean('is_active')->default(true);
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('leave_acting_assignments')) {
            Schema::create('leave_acting_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('acting_for_role', 40);
                $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['acting_for_role', 'is_active']);
            });
        }

        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'final_approver_capacity')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->string('final_approver_capacity', 12)->nullable(); // substantive | acting
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leave_requests', 'final_approver_capacity')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('final_approver_capacity');
            });
        }

        Schema::dropIfExists('leave_acting_assignments');
        Schema::dropIfExists('user_signatures');
    }
};
