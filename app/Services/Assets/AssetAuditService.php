<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use App\Models\IctAssetAuditLine;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical-verification audits: a header run plus one snapshotted line per asset in scope, each confirmed on the
 * ground. Corrections go through AssetRecordService::save(), so they are audit-logged and transfer-logged for free.
 * Per-line verification is deliberately not written to audit_logs; the lines are the detailed record.
 */
class AssetAuditService
{
    public function __construct(
        protected AssetRecordService $assetRecords,
    ) {}

    /** Assets matching a scope. Every key is optional: device_category, region_id, district_id. */
    public function scopeQuery(array $scope): Builder
    {
        return IctAsset::query()
            ->when(! empty($scope['device_category']), fn (Builder $q) => $q->where('device_category', $scope['device_category']))
            ->when(! empty($scope['region_id']), fn (Builder $q) => $q->where('region_id', (int) $scope['region_id']))
            ->when(! empty($scope['district_id']), fn (Builder $q) => $q->where('district_id', (int) $scope['district_id']));
    }

    public function start(array $scope, string $title, int $startedByUserId): IctAssetAudit
    {
        if (trim($title) === '') {
            throw ValidationException::withMessages(['title' => 'Give the audit a title.']);
        }

        if (! empty($scope['device_category']) && ! in_array($scope['device_category'], IctAsset::DEVICE_CATEGORIES, true)) {
            throw ValidationException::withMessages(['scope' => 'Unknown device category.']);
        }

        return DB::transaction(function () use ($scope, $title, $startedByUserId) {
            $assets = $this->scopeQuery($scope)->orderBy('id')->get(['id', 'status', 'assigned_to_employee_id', 'district_id']);

            if ($assets->isEmpty()) {
                throw ValidationException::withMessages(['scope' => 'No assets match this scope, so there is nothing to audit.']);
            }

            $audit = IctAssetAudit::query()->create([
                'title' => trim($title),
                'scope_device_category' => $scope['device_category'] ?? null ?: null,
                'scope_region_id' => $scope['region_id'] ?? null ?: null,
                'scope_district_id' => $scope['district_id'] ?? null ?: null,
                'status' => IctAssetAudit::STATUS_IN_PROGRESS,
                'started_by_user_id' => $startedByUserId,
                'started_at' => now(),
            ]);

            $now = now();
            foreach ($assets->chunk(500) as $chunk) {
                IctAssetAuditLine::query()->insert($chunk->map(fn (IctAsset $asset) => [
                    'ict_asset_audit_id' => $audit->id,
                    'ict_asset_id' => $asset->id,
                    'expected_status' => $asset->status,
                    'expected_assigned_to_employee_id' => $asset->assigned_to_employee_id,
                    'expected_district_id' => $asset->district_id,
                    'correction_applied' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            AuditLog::record('create_asset_audit', Permission::MODULE_ASSETS, 'ict_asset_audits', $audit->id, null, [
                'title' => $audit->title,
                'scope' => $scope,
                'lines' => $assets->count(),
            ]);

            return $audit;
        });
    }

    /**
     * @param array{status?: ?string, assigned_to_employee_id?: int|string|null, district_id?: int|string|null, location?: ?string} $actual
     */
    public function recordResult(IctAssetAuditLine $line, string $result, array $actual, ?string $reason, int $verifiedByUserId): void
    {
        if (! in_array($result, IctAssetAuditLine::RESULTS, true)) {
            throw ValidationException::withMessages(['result' => 'Result must be matched, mismatch or not found.']);
        }

        // Re-read: the caller's copy may be stale (a double submit, or the audit completed in another tab).
        $line->refresh();

        if (IctAssetAudit::query()->whereKey($line->ict_asset_audit_id)->value('status') === IctAssetAudit::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['result' => 'This audit is completed and can no longer be changed.']);
        }

        if ($line->correction_applied) {
            throw ValidationException::withMessages(['result' => 'A correction was already applied from this line, so its result is locked.']);
        }

        $reason = filled($reason) ? trim($reason) : null;

        if ($result !== IctAssetAuditLine::RESULT_MATCHED && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Say what was found: a reason is required for a mismatch or a missing asset.']);
        }

        $status = filled($actual['status'] ?? null) ? (string) $actual['status'] : null;

        if ($status !== null && ! in_array($status, IctAsset::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }

        $matched = $result === IctAssetAuditLine::RESULT_MATCHED;

        $line->forceFill([
            'result' => $result,
            // Matched means actual = expected by definition, so any actual input is discarded.
            'actual_status' => $matched ? null : $status,
            'actual_assigned_to_employee_id' => $matched ? null : (($actual['assigned_to_employee_id'] ?? null) ?: null),
            'actual_district_id' => $matched ? null : (($actual['district_id'] ?? null) ?: null),
            'actual_location' => $matched ? null : (filled($actual['location'] ?? null) ? trim($actual['location']) : null),
            'mismatch_reason' => $matched ? null : $reason,
            'verified_by_user_id' => $verifiedByUserId,
            'verified_at' => now(),
        ])->save();
    }

    /**
     * Push what was found onto the real asset. Goes through AssetRecordService::save(), which writes the audit_logs
     * snapshot and the ict_asset_transfers rows. Status is only touched when one was recorded; assignee and district
     * are taken as found (blank = none).
     */
    public function applyCorrection(IctAssetAuditLine $line, int $actorUserId): void
    {
        $line->refresh();

        if ($line->result !== IctAssetAuditLine::RESULT_MISMATCH) {
            throw ValidationException::withMessages(['result' => 'Only a mismatch can be corrected.']);
        }

        if ($line->correction_applied) {
            throw ValidationException::withMessages(['result' => 'This correction was already applied.']);
        }

        DB::transaction(function () use ($line) {
            $asset = IctAsset::query()->lockForUpdate()->findOrFail($line->ict_asset_id);

            $data = [
                'assigned_to_employee_id' => $line->actual_assigned_to_employee_id,
                'district_id' => $line->actual_district_id,
            ];

            if ($line->actual_status !== null) {
                $data['status'] = $line->actual_status;
                $data['status_reason'] = $line->mismatch_reason;
            }

            $this->assetRecords->save($data, $asset->device_category, $asset);

            $line->forceFill(['correction_applied' => true])->save();
        });
    }

    public function complete(IctAssetAudit $audit): void
    {
        if ($audit->isCompleted()) {
            throw ValidationException::withMessages(['audit' => 'This audit is already completed.']);
        }

        DB::transaction(function () use ($audit) {
            $audit = IctAssetAudit::query()->lockForUpdate()->findOrFail($audit->id);

            $total = $audit->lines()->count();
            $pending = $audit->lines()->whereNull('result')->count();

            if ($pending > 0) {
                throw ValidationException::withMessages(['audit' => "{$pending} line(s) are still pending. Verify every asset before completing."]);
            }

            $matched = $audit->lines()->where('result', IctAssetAuditLine::RESULT_MATCHED)->count();

            $audit->forceFill([
                'status' => IctAssetAudit::STATUS_COMPLETED,
                'completed_at' => now(),
                'reconciliation_rate' => $total > 0 ? round($matched / $total * 100, 2) : 0,
            ])->save();

            AuditLog::record('complete_asset_audit', Permission::MODULE_ASSETS, 'ict_asset_audits', $audit->id, null, [
                'lines' => $total,
                'matched' => $matched,
                'reconciliation_rate' => $audit->reconciliation_rate,
            ]);
        });

        $audit->refresh();
    }

    /** @return array{total: int, verified: int, pending: int, matched: int, mismatch: int, not_found: int} */
    public function summary(IctAssetAudit $audit): array
    {
        $counts = $audit->lines()->select('result', DB::raw('COUNT(*) as total'))->groupBy('result')->pluck('total', 'result');
        $pending = (int) ($counts[''] ?? 0); // a NULL result is keyed as ''
        $total = (int) $counts->sum();

        return [
            'total' => $total,
            'verified' => $total - $pending,
            'pending' => $pending,
            'matched' => (int) ($counts[IctAssetAuditLine::RESULT_MATCHED] ?? 0),
            'mismatch' => (int) ($counts[IctAssetAuditLine::RESULT_MISMATCH] ?? 0),
            'not_found' => (int) ($counts[IctAssetAuditLine::RESULT_NOT_FOUND] ?? 0),
        ];
    }

    /** Heading => cell for every export column, in order. One definition for the preview, Excel and PDF. */
    public function columns(): array
    {
        return [
            'no' => 'No.',
            'serial' => 'Serial',
            'asset' => 'Asset',
            'category' => 'Category',
            'type' => 'Type',
            'expected_status' => 'Expected status',
            'expected_holder' => 'Expected holder',
            'expected_district' => 'Expected location',
            'result' => 'Result',
            'actual_status' => 'Actual status',
            'actual_holder' => 'Actual holder',
            'actual_district' => 'Actual location',
            'actual_location' => 'Location found',
            'reason' => 'Reason',
            'verified_by' => 'Verified by',
            'verified_at' => 'Verified at',
            'correction' => 'Correction applied',
        ];
    }

    /**
     * Plain-text rows for one audit's lines, keyed like columns().
     *
     * @return Collection<int, array<string, string|int>>
     */
    public function exportRows(IctAssetAudit $audit): Collection
    {
        $resultLabels = [
            IctAssetAuditLine::RESULT_MATCHED => 'Matched',
            IctAssetAuditLine::RESULT_MISMATCH => 'Mismatch',
            IctAssetAuditLine::RESULT_NOT_FOUND => 'Not found',
        ];

        return $audit->lines()
            ->with(['asset', 'expectedEmployee', 'expectedDistrict', 'actualEmployee', 'actualDistrict', 'verifiedBy'])
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn (IctAssetAuditLine $line, int $index) => [
                'no' => $index + 1,
                'serial' => (string) $line->asset?->serial_number,
                'asset' => (string) $line->asset?->asset_name,
                'category' => (string) $line->asset?->device_category,
                'type' => (string) $line->asset?->asset_type,
                'expected_status' => (string) $line->expected_status,
                'expected_holder' => (string) $line->expectedEmployee?->full_name,
                'expected_district' => (string) $line->expectedDistrict?->district_name,
                'result' => $resultLabels[$line->result] ?? 'Pending',
                'actual_status' => (string) $line->actual_status,
                'actual_holder' => (string) $line->actualEmployee?->full_name,
                'actual_district' => (string) $line->actualDistrict?->district_name,
                'actual_location' => (string) $line->actual_location,
                'reason' => (string) $line->mismatch_reason,
                'verified_by' => (string) $line->verifiedBy?->full_name,
                'verified_at' => $line->verified_at?->format('d M Y H:i') ?? '',
                'correction' => $line->correction_applied ? 'Yes' : '',
            ]);
    }
}
