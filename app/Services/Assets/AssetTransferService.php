<?php

namespace App\Services\Assets;

use App\Models\IctAsset;
use App\Models\IctAssetTransfer;

class AssetTransferService
{
    /**
     * Record one change on an asset. $attrs may carry from_/to_ employee, status and district ids, related_asset_id,
     * reason and occurred_at; anything else is ignored. One call per changed dimension.
     */
    public function log(IctAsset $asset, string $type, array $attrs, ?int $actorUserId): IctAssetTransfer
    {
        if (! in_array($type, IctAssetTransfer::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown transfer type [{$type}].");
        }

        return IctAssetTransfer::query()->create([
            ...array_intersect_key($attrs, array_flip([
                'from_employee_id', 'to_employee_id', 'from_status', 'to_status',
                'from_district_id', 'to_district_id', 'related_asset_id', 'reason',
            ])),
            'ict_asset_id' => $asset->id,
            'transfer_type' => $type,
            'performed_by_user_id' => $actorUserId,
            'occurred_at' => $attrs['occurred_at'] ?? now(),
        ]);
    }

    /**
     * Compare an asset before and after an update and log one transfer per dimension that changed.
     *
     * @param array<string, mixed> $before attributes snapshot taken before the update
     */
    public function logChanges(IctAsset $asset, array $before, ?int $actorUserId): void
    {
        $id = fn ($value) => $value ? (int) $value : null;

        if ($id($before['assigned_to_employee_id'] ?? null) !== $id($asset->assigned_to_employee_id)) {
            $this->log($asset, IctAssetTransfer::TYPE_ASSIGNMENT_CHANGE, [
                'from_employee_id' => $id($before['assigned_to_employee_id'] ?? null),
                'to_employee_id' => $id($asset->assigned_to_employee_id),
            ], $actorUserId);
        }

        if (($before['status'] ?? null) !== $asset->status) {
            $this->log($asset, IctAssetTransfer::TYPE_STATUS_CHANGE, [
                'from_status' => $before['status'] ?? null,
                'to_status' => $asset->status,
                'reason' => $asset->status_reason,
            ], $actorUserId);
        }

        if ($id($before['district_id'] ?? null) !== $id($asset->district_id)) {
            $this->log($asset, IctAssetTransfer::TYPE_DISTRICT_CHANGE, [
                'from_district_id' => $id($before['district_id'] ?? null),
                'to_district_id' => $id($asset->district_id),
            ], $actorUserId);
        }
    }
}
