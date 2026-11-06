<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use App\Models\Permission;

/**
 * Create / update of a maintenance record. The caller (the Livewire screen) has already validated the form and resolved
 * the asset and the record through the actor's scope; this applies the rules that belong to the record itself:
 * only a Completed record carries a completion date, and every change leaves an audit row (ids, status and dates only,
 * never the notes or the technician).
 */
class MaintenanceService
{
    /**
     * @param  array{maintenance_type: string, status: string, reported_date: string, completion_date?: string|null, technician?: string|null, notes?: string|null}  $data
     */
    public function save(IctAsset $asset, array $data, ?IctAssetMaintenance $existing, ?int $userId): IctAssetMaintenance
    {
        $completed = ($data['status'] ?? null) === IctAssetMaintenance::STATUS_COMPLETED;

        $attributes = [
            'ict_asset_id' => $asset->id,
            'maintenance_type' => trim((string) $data['maintenance_type']),
            'status' => $data['status'],
            'reported_date' => $data['reported_date'],
            // A completion date sent for a record that is not Completed is ignored.
            'completion_date' => $completed ? ($data['completion_date'] ?: null) : null,
            'technician' => filled($data['technician'] ?? null) ? trim($data['technician']) : null,
            'notes' => $data['notes'] ?? null,
            'performed_by_user_id' => $userId,
        ];

        if ($existing) {
            $old = $this->auditShape($existing);
            $existing->update($attributes);
            $record = $existing->refresh();

            AuditLog::record('update_maintenance', Permission::MODULE_ASSETS, 'ict_asset_maintenances', $record->id, $old, $this->auditShape($record));

            return $record;
        }

        $record = IctAssetMaintenance::query()->create($attributes);

        AuditLog::record('create_maintenance', Permission::MODULE_ASSETS, 'ict_asset_maintenances', $record->id, null, $this->auditShape($record));

        return $record;
    }

    /** @return array<string, mixed> */
    protected function auditShape(IctAssetMaintenance $record): array
    {
        return [
            'ict_asset_id' => $record->ict_asset_id,
            'status' => $record->status,
            'reported_date' => $record->reported_date?->toDateString(),
            'completion_date' => $record->completion_date?->toDateString(),
        ];
    }
}
