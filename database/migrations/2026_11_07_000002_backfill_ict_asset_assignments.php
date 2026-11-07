<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the assignment history from the two old columns on ict_assets (assigned_to_employee_id and
 * previous_assigned_to_employee_id), which are NOT dropped or changed: after this migration nothing writes
 * previous_assigned_to_employee_id any more, and it is kept only so the change can be rolled back.
 *
 * For each asset with a current holder: one OPEN row (is_current = 1). assigned_at is the date of the latest recorded
 * assignment change to that person in ict_asset_transfers, else the day the asset was added.
 *
 * For each asset with a stored previous holder: one CLOSED row, written before the open one. end_reason is
 * "reassigned", or "unassigned" when the asset has no holder now (the old column was filled on both). ended_at and
 * assigned_at come from the matching ict_asset_transfers rows when they exist; otherwise they stay NULL (the date was
 * never recorded) rather than being guessed from updated_at. Every rebuilt row has is_backfilled = 1.
 *
 * Safe to re-run: an asset that already has any assignment row is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_asset_assignments') || ! Schema::hasTable('ict_assets')) {
            return;
        }

        $hasTransfers = Schema::hasTable('ict_asset_transfers');
        $hasPrevious = Schema::hasColumn('ict_assets', 'previous_assigned_to_employee_id');
        $now = now();
        $withCurrent = 0;
        $withPrevious = 0;

        DB::table('ict_assets')
            ->where(function ($query) use ($hasPrevious) {
                $query->whereNotNull('assigned_to_employee_id');

                if ($hasPrevious) {
                    $query->orWhereNotNull('previous_assigned_to_employee_id');
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($assets) use ($hasTransfers, $hasPrevious, $now, &$withCurrent, &$withPrevious) {
                $employeeIds = $assets->pluck('assigned_to_employee_id')
                    ->merge($hasPrevious ? $assets->pluck('previous_assigned_to_employee_id') : [])
                    ->filter()
                    ->unique()
                    ->all();
                $existingEmployees = array_flip(DB::table('employees')->whereIn('id', $employeeIds)->pluck('id')->all());
                $alreadyDone = array_flip(
                    DB::table('ict_asset_assignments')->whereIn('ict_asset_id', $assets->pluck('id'))->distinct()->pluck('ict_asset_id')->all()
                );

                foreach ($assets as $asset) {
                    if (isset($alreadyDone[$asset->id])) {
                        continue;
                    }

                    $current = $asset->assigned_to_employee_id && isset($existingEmployees[$asset->assigned_to_employee_id])
                        ? (int) $asset->assigned_to_employee_id : null;
                    $previous = $hasPrevious && $asset->previous_assigned_to_employee_id && isset($existingEmployees[$asset->previous_assigned_to_employee_id])
                        ? (int) $asset->previous_assigned_to_employee_id : null;

                    $transfer = function (string $column, int $employeeId, ?string $before = null) use ($hasTransfers, $asset) {
                        if (! $hasTransfers) {
                            return null;
                        }

                        return DB::table('ict_asset_transfers')
                            ->where('ict_asset_id', $asset->id)
                            ->where('transfer_type', 'assignment_change')
                            ->where($column, $employeeId)
                            ->when($before, fn ($query) => $query->where('occurred_at', '<=', $before))
                            ->orderByDesc('occurred_at')
                            ->orderByDesc('id')
                            ->value('occurred_at');
                    };

                    if ($previous) {
                        $endedAt = $transfer('from_employee_id', $previous);

                        DB::table('ict_asset_assignments')->insert([
                            'ict_asset_id' => $asset->id,
                            'employee_id' => $previous,
                            'assigned_at' => $transfer('to_employee_id', $previous, $endedAt),
                            'ended_at' => $endedAt,
                            'end_reason' => $current ? 'reassigned' : 'unassigned',
                            'is_current' => null,
                            'is_backfilled' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $withPrevious++;
                    }

                    if ($current) {
                        DB::table('ict_asset_assignments')->insert([
                            'ict_asset_id' => $asset->id,
                            'employee_id' => $current,
                            'assigned_at' => $transfer('to_employee_id', $current) ?? $asset->created_at,
                            'ended_at' => null,
                            'end_reason' => null,
                            'is_current' => true,
                            'is_backfilled' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $withCurrent++;
                    }
                }
            });

        Log::info("ict_asset_assignments backfill: {$withCurrent} open rows, {$withPrevious} closed rows from previous_assigned_to_employee_id.");
    }

    public function down(): void
    {
        // The old columns were left untouched, so removing the rebuilt rows is a full rollback of the data step.
        if (Schema::hasTable('ict_asset_assignments')) {
            DB::table('ict_asset_assignments')->where('is_backfilled', true)->delete();
        }
    }
};
