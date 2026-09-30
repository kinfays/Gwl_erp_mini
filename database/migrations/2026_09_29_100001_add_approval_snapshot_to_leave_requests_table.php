<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a leave request was routed to when it was submitted.
 *
 *   manager_user_id  the recommender (null for a single-stage request, which has no recommender)
 *   chief_user_id    the final approver (the regional chief manager, the department's chief manager or the MD)
 *   is_single_stage  the applicant applies straight to the final approver; nobody recommends
 *
 * These are users (roles live on users). `manager_id` keeps pointing at employees and keeps meaning "the
 * recommender, or the final approver for a single-stage request" — the dashboards and exports rely on it.
 * Several people can hold the same role in a scope, so these name the first of them; whoever the resolver
 * finds at action time may also act (see LeaveApprovalChainResolver::canAct()).
 *
 * `manager_id` also becomes nullable: a Planned draft has no approver yet — they are resolved on submission.
 * `is_single_stage` cannot be derived from the two nullable FKs, because nullOnDelete would make a two-stage
 * request look single-stage as soon as its recommender's user is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }

        if (! Schema::hasColumn('leave_requests', 'manager_user_id')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->foreignId('manager_user_id')->nullable()->after('manager_id')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('leave_requests', 'chief_user_id')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->foreignId('chief_user_id')->nullable()->after('manager_user_id')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('leave_requests', 'is_single_stage')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->boolean('is_single_stage')->default(false)->after('chief_user_id');
            });
        }

        if (Schema::hasColumn('leave_requests', 'manager_id')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->foreignId('manager_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }

        foreach (['manager_user_id', 'chief_user_id'] as $column) {
            if (Schema::hasColumn('leave_requests', $column)) {
                Schema::table('leave_requests', function (Blueprint $table) use ($column) {
                    $table->dropConstrainedForeignId($column);
                });
            }
        }

        if (Schema::hasColumn('leave_requests', 'is_single_stage')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('is_single_stage');
            });
        }

        // manager_id stays nullable: putting NOT NULL back would fail for any draft saved since.
    }
};
