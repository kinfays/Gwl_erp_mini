<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When each approval stage happened, so the leave dashboards can time the manager and the final
 * approver separately (created_at/updated_at can't: a request may be planned long before it is
 * submitted, and updated_at moves with every write). LeaveWorkflowService sets these from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }

        $columns = ['submitted_at', 'recommended_at', 'decided_at'];
        $missing = array_values(array_filter($columns, fn (string $column) => ! Schema::hasColumn('leave_requests', $column)));

        if ($missing !== []) {
            Schema::table('leave_requests', function (Blueprint $table) use ($missing) {
                $after = 'leave_status';

                foreach ($missing as $column) {
                    $table->timestamp($column)->nullable()->after($after);
                    $after = $column;
                }
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }

        $present = array_values(array_filter(
            ['decided_at', 'recommended_at', 'submitted_at'],
            fn (string $column) => Schema::hasColumn('leave_requests', $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table('leave_requests', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    /**
     * Best effort for rows that predate these columns. The query builder is used on purpose so
     * updated_at is left alone — it is the only record of when the last stage happened. A stage
     * time that can't be known stays null, and the dashboards skip it.
     */
    protected function backfill(): void
    {
        $requests = fn () => DB::table('leave_requests');

        // Submitted: a request created straight into the queue was submitted when it was created.
        // (One planned first and submitted later can't be told apart; created_at is the closest.)
        $requests()
            ->whereNull('submitted_at')
            ->whereIn('leave_status', ['Pending Approval', 'Approved', 'Denied'])
            ->update(['submitted_at' => DB::raw('created_at')]);

        // Decided: the decision is the last write to an approved or denied request.
        $requests()
            ->whereNull('decided_at')
            ->whereIn('leave_status', ['Approved', 'Denied'])
            ->update(['decided_at' => DB::raw('updated_at')]);

        // Recommended: knowable only when the recommendation was the last write — a manager's
        // rejection (which also denies the request), or a recommendation still awaiting the final
        // approver. For requests the chief has since decided, the recommendation time is lost.
        $requests()
            ->whereNull('recommended_at')
            ->where(function ($query) {
                $query->where('manager_recommendation', 'Rejected')
                    ->orWhere(function ($pending) {
                        $pending->where('manager_recommendation', 'Recommended')
                            ->where('leave_status', 'Pending Approval');
                    });
            })
            ->update(['recommended_at' => DB::raw('updated_at')]);
    }
};
