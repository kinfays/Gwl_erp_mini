<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Keeps batch statuses honest. Rows are immutable snapshots tied to a batch and analytics read the "effective" ones
 * (design section 2.3), so imported / superseded is only a label for what the data already says; it is recomputed from
 * the data after every import and every void, which is also what lets a voided batch bring the batch it replaced back.
 */
class BatchLifecycleService
{
    /**
     * Recomputes imported / superseded for the non-voided batches of one region and report type.
     *
     * @return array{superseded: list<int>, restored: list<int>} ids of the batches whose status changed
     */
    public function refreshStatuses(?int $regionId, string $reportType, ?int $causedByBatchId = null): array
    {
        $superseded = [];
        $restored = [];

        $batches = CommercialImportBatch::query()
            ->where('region_id', $regionId)
            ->where('report_type', $reportType)
            ->notVoided()
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            $isOverridden = $batch->isReading()
                ? ! $batch->stats()->effective()->exists()
                : $this->billingSnapshotIsReplaced($batch);

            $target = $isOverridden ? CommercialImportBatch::STATUS_SUPERSEDED : CommercialImportBatch::STATUS_IMPORTED;

            if ($batch->status === $target) {
                continue;
            }

            $batch->update(['status' => $target]);

            if ($isOverridden) {
                $superseded[] = $batch->id;

                Audit::log(
                    action: 'commercial.batch_superseded',
                    module: Permission::MODULE_COMMERCIAL,
                    targetType: CommercialImportBatch::class,
                    targetId: $batch->id,
                    metadata: [
                        'report_type' => $batch->report_type,
                        'source_filename' => $batch->source_filename,
                        'superseded_by_batch_id' => $causedByBatchId,
                    ]
                );
            } else {
                $restored[] = $batch->id;
            }
        }

        return ['superseded' => $superseded, 'restored' => $restored];
    }

    /**
     * Voids a batch: its rows stay on record but drop out of every analysis, and anything it had replaced is effective
     * again.
     */
    public function void(CommercialImportBatch $batch, string $reason, ?int $actorId = null): CommercialImportBatch
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new CommercialImportException('A reason is required to void a batch.');
        }

        return DB::transaction(function () use ($batch, $reason, $actorId): CommercialImportBatch {
            $locked = CommercialImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new CommercialImportException('This batch has already been voided.');
            }

            $locked->update([
                'status' => CommercialImportBatch::STATUS_VOIDED,
                'voided_by' => $actorId ?? auth()->id(),
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $changes = $this->refreshStatuses($locked->region_id, $locked->report_type, $locked->id);

            Audit::log(
                action: 'commercial.batch_voided',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $locked->id,
                metadata: [
                    'report_type' => $locked->report_type,
                    'region_id' => $locked->region_id,
                    'source_filename' => $locked->source_filename,
                    'reason' => $reason,
                    'restored_batch_ids' => $changes['restored'],
                ]
            );

            return $locked->refresh();
        });
    }

    protected function billingSnapshotIsReplaced(CommercialImportBatch $batch): bool
    {
        return CommercialImportBatch::query()
            ->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)
            ->notVoided()
            ->where('id', '>', $batch->id)
            ->where('region_id', $batch->region_id)
            ->where('customer_segment', $batch->customer_segment)
            ->whereDate('period_from', $batch->period_from->toDateString())
            ->whereDate('period_to', $batch->period_to->toDateString())
            ->exists();
    }
}
