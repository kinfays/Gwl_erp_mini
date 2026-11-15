<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialReadingStat;
use App\Models\District;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Region;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * What an officer does about an imported batch's unmatched locations and readers. Resolving a region or district writes
 * an alias, so the next upload matches by itself; linking a reader fixes the rows of that batch only (the staff ID in
 * the report is what the directory should hold, so the real fix is there).
 */
class BatchResolutionService
{
    public function __construct(
        protected LocationMatcher $locations,
        protected CommercialImportService $imports,
        protected ReadingSummaryImportService $reading,
        protected ReadingRouteImportService $readingRoutes,
    ) {
    }

    /** Remembers which region a report's REGION line means, before the batch is imported. */
    public function saveRegionAlias(string $rawLabel, Region $region): void
    {
        $alias = $this->locations->saveRegionAlias($rawLabel, $region);

        Audit::log(
            action: 'commercial.alias_saved',
            module: Permission::MODULE_COMMERCIAL,
            targetType: $alias::class,
            targetId: $alias->id,
            metadata: ['kind' => $alias->kind, 'alias' => $alias->alias_normalized, 'region_id' => $region->id, 'region' => $region->region_name]
        );
    }

    /**
     * Maps a district label of a billing batch to a real district and re-matches the batch.
     *
     * @return int routes that matched as a result
     */
    public function resolveDistrict(CommercialImportBatch $batch, string $rawLabel, District $district): int
    {
        if (! $batch->isBilling() && ! $batch->isReadingRoute() && ! $batch->isBillingDetails()) {
            throw new CommercialImportException('Only billing, detailed billing and reading-by-route batches have districts to resolve.');
        }

        if ($batch->region_id !== null && (int) $district->region_id !== (int) $batch->region_id) {
            throw new CommercialImportException('That district is not in the batch\'s region.');
        }

        return DB::transaction(function () use ($batch, $rawLabel, $district): int {
            $alias = $this->locations->saveDistrictAlias($rawLabel, $district);

            Audit::log(
                action: 'commercial.alias_saved',
                module: Permission::MODULE_COMMERCIAL,
                targetType: $alias::class,
                targetId: $alias->id,
                metadata: [
                    'kind' => $alias->kind,
                    'alias' => $alias->alias_normalized,
                    'district_id' => $district->id,
                    'district' => $district->district_name,
                    'batch_id' => $batch->id,
                ]
            );

            return $this->imports->rematchBatch($batch);
        });
    }

    /**
     * Ties a reader of a reading batch to a directory employee, for every month of that batch.
     *
     * @return int rows updated
     */
    public function linkReader(CommercialImportBatch $batch, string $readerStaffId, Employee $employee): int
    {
        if (! $batch->isReading() && ! $batch->isReadingRoute()) {
            throw new CommercialImportException('Only reading batches have CCAs to link.');
        }

        if ($batch->isReadingRoute()) {
            return $this->linkRouteCca($batch, $readerStaffId, $employee);
        }

        return DB::transaction(function () use ($batch, $readerStaffId, $employee): int {
            $updated = $batch->stats()
                ->where('reader_staff_id', $readerStaffId)
                ->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)
                ->update([
                    'employee_id' => $employee->id,
                    'district_id' => $employee->district_id,
                    'match_status' => CommercialReadingStat::MATCH_MATCHED,
                ]);

            $this->reading->refreshBatchCounts($batch);

            Audit::log(
                action: 'commercial.reader_linked',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $batch->id,
                metadata: ['reader_staff_id' => $readerStaffId, 'employee_id' => $employee->id, 'employee_staff_id' => $employee->staff_id, 'rows' => $updated]
            );

            return $updated;
        });
    }

    /** The same for a reading-by-route batch: every row of that CCA in the batch. */
    protected function linkRouteCca(CommercialImportBatch $batch, string $staffId, Employee $employee): int
    {
        return DB::transaction(function () use ($batch, $staffId, $employee): int {
            $updated = $batch->routeStats()->where('cca_staff_id', $staffId)->where('match_status', CommercialReadingRouteStat::MATCH_UNMATCHED)->update([
                'employee_id' => $employee->id,
                'match_status' => CommercialReadingRouteStat::MATCH_MATCHED,
            ]);

            // a CCA listed with no route takes the employee's home district; a CCA with routes keeps the routes' own
            $batch->routeStats()->where('cca_staff_id', $staffId)->whereNull('route_label_raw')->update(['district_id' => $employee->district_id]);
            $this->readingRoutes->refreshBatchCounts($batch);

            Audit::log(
                action: 'commercial.reader_linked',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $batch->id,
                metadata: ['reader_staff_id' => $staffId, 'employee_id' => $employee->id, 'employee_staff_id' => $employee->staff_id, 'rows' => $updated]
            );

            return $updated;
        });
    }

    /** Re-runs matching for the whole batch (after HR fixed the directory or an alias was added elsewhere). */
    public function rematch(CommercialImportBatch $batch): int
    {
        $matched = $this->imports->rematchBatch($batch);

        Audit::log(
            action: 'commercial.batch_rematched',
            module: Permission::MODULE_COMMERCIAL,
            targetType: CommercialImportBatch::class,
            targetId: $batch->id,
            metadata: ['matched' => $matched]
        );

        return $matched;
    }
}
