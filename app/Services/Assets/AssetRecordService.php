<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetRecordService
{
    public function __construct(
        protected IpRangeService $ipRanges
    ) {}

    /**
     * Shared create/update path for all three category forms (Assets,
     * Phones, Network). device_category is immutable once set — Maintenance
     * /Issue/Agent reports all key off ict_assets.id and assume a stable
     * identity, so a device can't change category after creation.
     */
    public function save(array $data, string $category, ?IctAsset $existing = null): IctAsset
    {
        if (! in_array($category, IctAsset::DEVICE_CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'form.asset_type' => 'Unknown asset category.',
            ]);
        }

        if ($existing && $existing->device_category !== $category) {
            throw ValidationException::withMessages([
                'form.asset_type' => 'This asset belongs to a different category and cannot be edited here.',
            ]);
        }

        $data['device_category'] = $category;

        // A cleared <select>/date input arrives as '' — store NULL rather than
        // hand a strict-mode database an empty string for an integer/date column.
        foreach (['ict_asset_model_id', 'assigned_to_employee_id', 'department_id', 'region_id', 'district_id', 'purchased_at'] as $nullableKey) {
            if (($data[$nullableKey] ?? null) === '') {
                $data[$nullableKey] = null;
            }
        }

        if (! empty($data['device_ip'])) {
            $error = $this->ipRanges->validateIpForLocation(
                $data['device_ip'],
                $data['district_id'] ?? $existing?->district_id,
                $data['region_id'] ?? $existing?->region_id,
            );

            if ($error) {
                throw ValidationException::withMessages(['form.device_ip' => $error]);
            }
        }

        return DB::transaction(function () use ($data, $category, $existing) {
            if (array_key_exists('assigned_to_employee_id', $data)) {
                $currentAssigned = $existing?->assigned_to_employee_id;
                $newAssigned = $data['assigned_to_employee_id'] ?: null;

                if ($currentAssigned && $currentAssigned !== $newAssigned) {
                    $data['previous_assigned_to_employee_id'] = $currentAssigned;
                }
            }

            if ($existing) {
                $old = $existing->toArray();
                $existing->update($data);
                $asset = $existing->refresh();

                AuditLog::record(
                    action: "update_{$category}",
                    module: Permission::MODULE_ASSETS,
                    targetType: 'ict_assets',
                    targetId: $asset->id,
                    old: $this->redact($old),
                    new: $this->redact($asset->toArray()),
                );

                return $asset;
            }

            $asset = IctAsset::query()->create($data);

            AuditLog::record(
                action: "create_{$category}",
                module: Permission::MODULE_ASSETS,
                targetType: 'ict_assets',
                targetId: $asset->id,
                old: null,
                new: $this->redact($asset->toArray()),
            );

            return $asset;
        });
    }

    /**
     * Network device secrets must never reach audit_logs, plaintext or
     * encrypted ciphertext alike.
     */
    protected function redact(array $payload): array
    {
        unset($payload['login_password'], $payload['ssid_password']);

        return $payload;
    }
}
