<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The lifecycle of a customer batch after it has been merged: completing it, keeping the rollback data of only the newest
 * batches, labelling superseded batches, and voiding (with exact rollback) the newest one.
 *
 * ROLLBACK MODEL. commercial_customers is the current state; the history is the change log plus per-batch rollups. Before a
 * batch rewrites an existing row, the row's pre-image goes to commercial_customer_undo. Voiding the NEWEST batch of a
 * district therefore restores it exactly: new accounts are removed, rewritten rows are put back from the undo table,
 * "missing" marks are cleared, and its rollups / change-log rows go. Only the newest `commercial_customer_undo_keep_batches`
 * batches of a district keep their undo rows, so an older batch cannot be voided (upload a corrected file instead).
 * Contact details (PII) are NOT rolled back: a newer phone number stays.
 */
class CustomerBatchLifecycle
{
    /** The uploaded workbook holds personal data: it is deleted the moment the batch is finished (or blocked). */
    public function discardFile(CommercialCustomerBatch $batch): void
    {
        if ($batch->file_path) {
            Storage::disk('local')->delete($batch->file_path);
            $batch->forceFill(['file_path' => null])->save();
        }
    }

    public function complete(CommercialCustomerBatch $batch): void
    {
        CustomerMergeService::clearStaging($batch->id);
        $this->discardFile($batch);
        $this->releaseOldUndo((int) $batch->district_id);
        $this->releaseOldIssueLists($batch);

        $batch->forceFill([
            'status' => CommercialCustomerBatch::STATUS_IMPORTED,
            'phase' => CommercialCustomerBatch::PHASE_DONE,
            'finished_at' => now(),
            'imported_at' => now(),
            'error_message' => null,
        ])->save();

        $this->refreshStatuses((int) $batch->district_id);

        $this->audit($batch->imported_by, 'commercial.customer_batch_imported', $batch, [
            'district_id' => $batch->district_id, 'period' => $batch->period_key, 'rows' => $batch->rows_read, 'new' => $batch->rows_new,
            'changed' => $batch->rows_changed, 'unchanged' => $batch->rows_unchanged, 'missing' => $batch->rows_missing, 'moved' => $batch->rows_moved,
        ]);
    }

    /**
     * imported / superseded is only a label: of the live batches of one district and period, the newest wins and the others
     * are superseded (their rollups stay, but trends use the winner). Recomputed after every import and void.
     */
    public function refreshStatuses(int $districtId): void
    {
        $live = CommercialCustomerBatch::query()->live()->where('district_id', $districtId)->orderBy('as_of_date')->orderBy('id')->get();
        $winners = $live->groupBy('period_key')->map(fn ($group) => $group->last()->id);

        foreach ($live as $batch) {
            $target = $winners[$batch->period_key] === $batch->id ? CommercialCustomerBatch::STATUS_IMPORTED : CommercialCustomerBatch::STATUS_SUPERSEDED;

            if ($batch->status !== $target) {
                $batch->forceFill(['status' => $target])->save();
            }
        }
    }

    /** The newest batch of the district that still counts (imported or superseded). */
    public function newest(int $districtId): ?CommercialCustomerBatch
    {
        return CommercialCustomerBatch::query()->live()->where('district_id', $districtId)->orderByDesc('as_of_date')->orderByDesc('id')->first();
    }

    /** Why a batch cannot be voided now, or null when it can. */
    public function voidBlocker(CommercialCustomerBatch $batch): ?string
    {
        if (! $batch->isLive()) {
            return 'Only an imported batch can be voided.';
        }

        $newest = $this->newest((int) $batch->district_id);

        if (! $newest || $newest->id !== $batch->id) {
            return 'Only the newest upload of a district can be voided (its rollback data is released when a newer file arrives). Upload a corrected file instead, or void the newer upload first.';
        }

        $undo = (int) DB::table('commercial_customer_undo')->where('batch_id', $batch->id)->count();

        if ($undo < (int) $batch->rows_changed + (int) $batch->rows_missing) {
            return 'The rollback data of this upload has been released, so it can no longer be undone exactly. Upload a corrected file instead.';
        }

        return null;
    }

    public function void(CommercialCustomerBatch $batch, User $actor, string $reason): void
    {
        if ($blocker = $this->voidBlocker($batch)) {
            throw new CustomerImportException($blocker);
        }

        $b = (int) $batch->id;
        $batch->forceFill(['phase' => 'voiding'])->save();

        // 1. accounts this batch created go (their contacts first). One pass over the district finds them; the ids are then
        //    deleted in chunks, so a void never repeats a district scan per chunk.
        foreach (array_chunk(DB::table('commercial_customers')->where('district_id', $batch->district_id)->where('first_seen_batch_id', $b)->pluck('id')->all(), 5000) as $ids) {
            DB::table('commercial_customer_contacts')->whereIn('customer_id', $ids)->delete();
            DB::table('commercial_customers')->whereIn('id', $ids)->where('first_seen_batch_id', $b)->delete();
        }

        // 2. every row it rewrote (or marked missing) is put back from its pre-image
        $bounds = DB::table('commercial_customer_undo')->where('batch_id', $b)->selectRaw('MIN(customer_id) AS lo, MAX(customer_id) AS hi')->first();

        if ($bounds && $bounds->lo !== null) {
            for ($from = (int) $bounds->lo - 1; $from < (int) $bounds->hi; $from += 200000) {
                CustomerSql::restoreFromUndo($b, $from, $from + 200000);
            }
        }

        // 3. the batch's derived data goes
        DB::transaction(function () use ($b): void {
            foreach ([...CustomerRollupService::TABLES, 'commercial_customer_changes', 'commercial_customer_undo'] as $table) {
                DB::table($table)->where('batch_id', $b)->delete();
            }
        });

        $batch->forceFill([
            'status' => CommercialCustomerBatch::STATUS_VOIDED,
            'phase' => CommercialCustomerBatch::PHASE_DONE,
            'voided_by' => $actor->id,
            'voided_at' => now(),
            'void_reason' => mb_substr($reason, 0, 500),
        ])->save();

        $this->refreshStatuses((int) $batch->district_id);

        $this->audit($actor->id, 'commercial.customer_batch_voided', $batch, ['district_id' => $batch->district_id, 'period' => $batch->period_key, 'rows' => $batch->rows_read]);
    }

    /** Keeps undo rows for the newest N batches of the district only. */
    protected function releaseOldUndo(int $districtId): void
    {
        $keep = max(1, (int) config('gwl.commercial_customer_undo_keep_batches', 1));

        $old = CommercialCustomerBatch::query()->live()->where('district_id', $districtId)->orderByDesc('as_of_date')->orderByDesc('id')->skip(max(0, $keep - 1))->take(1000)->pluck('id');

        // The batch being completed is not "live" yet in this query, so it counts as one of the N kept.
        if ($old->isNotEmpty()) {
            DB::table('commercial_customer_undo')->whereIn('batch_id', $old->all())->delete();
        }
    }

    /**
     * The account lists behind the data-quality counts are only needed for the current state. The previous batch's lists are
     * kept as well, so that voiding this batch (which puts that state back) leaves working drill-downs; older ones go.
     */
    protected function releaseOldIssueLists(CommercialCustomerBatch $batch): void
    {
        $older = CommercialCustomerBatch::query()->where('district_id', $batch->district_id)->where('id', '<', $batch->id)->orderByDesc('id')->skip(1)->take(10000)->pluck('id');

        if ($older->isNotEmpty()) {
            DB::table('commercial_customer_issue_accounts')->whereIn('batch_id', $older->all())->delete();
        }
    }

    /** Audit rows hold counts and ids only, never a name, phone, e-mail or address. */
    protected function audit(?int $userId, string $action, CommercialCustomerBatch $batch, array $metadata): void
    {
        $previous = Auth::user();
        $actor = $previous ?? ($userId ? User::query()->find($userId) : null);

        if ($actor && ! $previous) {
            Auth::setUser($actor);
        }

        Audit::log(action: $action, module: Permission::MODULE_COMMERCIAL, targetType: CommercialCustomerBatch::class, targetId: $batch->id, metadata: $metadata);

        if ($actor && ! $previous) {
            Auth::forgetUser();
        }
    }
}
