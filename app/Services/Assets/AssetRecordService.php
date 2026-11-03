<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetRecordService
{
    public function __construct(
        protected IpRangeService $ipRanges,
        protected AssetTransferService $transfers,
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
        foreach (['ict_asset_model_id', 'assigned_to_employee_id', 'department_id', 'region_id', 'district_id', 'purchased_at', 'status_reason'] as $nullableKey) {
            if (($data[$nullableKey] ?? null) === '') {
                $data[$nullableKey] = null;
            }
        }

        $data = $this->normaliseUps($data, $category, $existing);
        $data = $this->normaliseProcessor($data, $category, $existing);

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

                // The transfers table is the detailed trail for these three fields; audit_logs keeps the snapshot below.
                $this->transfers->logChanges($asset, $old, Auth::id());

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
     * UPS fields only mean something for PC / AIO / Server / printer / photocopier. For any other device they are
     * forced to false/null/null (so a type changed to Laptop, or a phone, never keeps stale values), and without a UPS
     * the status and notes are cleared. A save that carries none of the UPS keys (an audit correction, the issue
     * popup) leaves them alone.
     */
    protected function normaliseUps(array $data, string $category, ?IctAsset $existing): array
    {
        $touchesUps = array_key_exists('has_ups', $data) || array_key_exists('ups_status', $data) || array_key_exists('ups_notes', $data);
        $typeChanged = array_key_exists('asset_type', $data) && $existing && $data['asset_type'] !== $existing->asset_type;

        if (! $touchesUps && ! $typeChanged) {
            return $data;
        }

        $type = $data['asset_type'] ?? $existing?->asset_type;

        if (! IctAsset::supportsUps($category, $type)) {
            return [...$data, 'has_ups' => false, 'ups_status' => null, 'ups_notes' => null];
        }

        if (array_key_exists('has_ups', $data) && ! $data['has_ups']) {
            return [...$data, 'has_ups' => false, 'ups_status' => null, 'ups_notes' => null];
        }

        foreach (['ups_status', 'ups_notes'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /**
     * The processor is recorded only for PC / AIO / Laptop / Server. A save that names a cpu_name for any other device,
     * or changes a device to such a type, drops it; a blank one is stored as NULL. A save that says nothing about it
     * (the agent pipeline does not come through here) leaves it alone.
     */
    protected function normaliseProcessor(array $data, string $category, ?IctAsset $existing): array
    {
        $typeChanged = array_key_exists('asset_type', $data) && $existing && $data['asset_type'] !== $existing->asset_type;

        if (! array_key_exists('cpu_name', $data) && ! $typeChanged) {
            return $data;
        }

        $type = $data['asset_type'] ?? $existing?->asset_type;

        if (! IctAsset::supportsProcessor($category, $type)) {
            return [...$data, 'cpu_name' => null];
        }

        if (array_key_exists('cpu_name', $data)) {
            $data['cpu_name'] = filled($data['cpu_name']) ? trim($data['cpu_name']) : null;
        }

        return $data;
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
