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
            ->live()   // a queued, running, waiting, blocked or failed run is not a period anybody can see yet
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            $isOverridden = match (true) {
                $batch->isReading() => ! $batch->stats()->effective()->exists(),
                $batch->isReadingRoute(), $batch->isBillingDetails() => $this->periodIsShadowed($batch),
                default => $this->billingSnapshotIsReplaced($batch),
            };

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

        // Phase 8h: a derived billing summary follows its detailed batch (voided, superseded or restored): made to agree here, the one place that
        // already runs after every import and every void
        if ($reportType === CommercialImportBatch::TYPE_BILLING_DETAILS) {
            app(BillingSummaryFromDetails::class)->reconcile($regionId);
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

            // Phase 8h: a derived summary has no life of its own: it follows the detailed batch it was built from
            if ($locked->isDerived()) {
                throw new CommercialImportException('This summary is derived from the detailed billing report (batch #'.$locked->derived_from_batch_id.'): void that batch and this one goes with it.');
            }

            // (the three older report types are always imported or superseded, so this only ever refuses a Detailed Billing Report run that never went live)
            if (! $locked->isLive()) {
                throw new CommercialImportException('Only a loaded upload can be voided. Discard this one instead: it never counted in any analysis.');
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

    /**
     * A reading-by-route or detailed-billing batch is only a label "superseded" when a newer live batch of the region and type has the same period
     * or contains it (CommercialPeriods rules 1 and 2).
     */
    protected function periodIsShadowed(CommercialImportBatch $batch): bool
    {
        return CommercialImportBatch::query()
            ->ofType($batch->report_type)
            ->live()
            ->where('id', '>', $batch->id)
            ->where('region_id', $batch->region_id)
            ->whereDate('period_from', '<=', $batch->period_from->toDateString())
            ->whereDate('period_to', '>=', $batch->period_to->toDateString())
            ->exists();
    }

    protected function billingSnapshotIsReplaced(CommercialImportBatch $batch): bool
    {
        return CommercialImportBatch::query()
            ->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)
            ->notVoided()
            ->where('id', '>', $batch->id)
            ->where('source', $batch->source ?? CommercialImportBatch::SOURCE_UPLOAD)   // an uploaded and a derived summary never replace each other: the preference decides (CommercialPeriods)
            ->where('region_id', $batch->region_id)
            ->where('customer_segment', $batch->customer_segment)
            ->whereDate('period_from', $batch->period_from->toDateString())
            ->whereDate('period_to', $batch->period_to->toDateString())
            ->exists();
    }
}
