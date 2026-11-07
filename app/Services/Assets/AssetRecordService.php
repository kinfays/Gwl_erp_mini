<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetRecordService
{
    public const DUPLICATE_NAME_MESSAGE = 'An asset with this name already exists.';

    public function __construct(
        protected IpRangeService $ipRanges,
        protected AssetTransferService $transfers,
        protected AssetAssignmentService $assignments,
    ) {}

    /** Whether another asset already uses this name (case-insensitive, surrounding spaces ignored). */
    public static function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        return IctAsset::query()
            ->where('asset_name_key', IctAsset::nameKey($name))
            ->when($ignoreId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * Shared create/update path for all three category forms (Assets,
     * Phones, Network). device_category is immutable once set — Maintenance
     * /Issue/Agent reports all key off ict_assets.id and assume a stable
     * identity, so a device can't change category after creation.
     *
     * The holder (assigned_to_employee_id) is never written here: it is handed to AssetAssignmentService, which keeps
     * the assignment history. Swapping one holder for another needs $assignmentChange ('reassigned' | 'correction');
     * a save without it is refused. Assigning an empty device, clearing the holder and creating a device need none.
     */
    public function save(array $data, string $category, ?IctAsset $existing = null, ?string $assignmentChange = null): IctAsset
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

        // The one choke point for every asset write (Assets, Phones and Network forms), so the rule holds even when a
        // caller skipped the form validation. The database index backs it up against a simultaneous save.
        if (array_key_exists('asset_name', $data)) {
            $data['asset_name'] = trim((string) $data['asset_name']);

            if (self::nameTaken($data['asset_name'], $existing?->id)) {
                throw ValidationException::withMessages(['form.asset_name' => self::DUPLICATE_NAME_MESSAGE]);
            }
        }

        // A cleared <select>/date input arrives as '' — store NULL rather than
        // hand a strict-mode database an empty string for an integer/date column.
        foreach (['ict_asset_model_id', 'assigned_to_employee_id', 'department_id', 'region_id', 'district_id', 'purchased_at', 'status_reason', 'actual_location'] as $nullableKey) {
            if (($data[$nullableKey] ?? null) === '') {
                $data[$nullableKey] = null;
            }
        }

        $data = $this->normaliseGeneralUse($data, $category, $existing);
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

        // Taken out of the row data: the assignment service is the only writer of the holder (and of the history).
        $changesHolder = array_key_exists('assigned_to_employee_id', $data);
        $newHolder = $changesHolder ? ($data['assigned_to_employee_id'] ?: null) : null;
        unset($data['assigned_to_employee_id'], $data['previous_assigned_to_employee_id']);

        try {
            return $this->persist($data, $category, $existing, $changesHolder, $newHolder ? (int) $newHolder : null, $assignmentChange);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with another save of the same name (the index is the final word).
            throw ValidationException::withMessages(['form.asset_name' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    protected function persist(array $data, string $category, ?IctAsset $existing, bool $changesHolder, ?int $newHolder, ?string $assignmentChange): IctAsset
    {
        return DB::transaction(function () use ($data, $category, $existing, $changesHolder, $newHolder, $assignmentChange) {
            if ($existing) {
                $old = $existing->toArray();
                $existing->update($data);

                if ($changesHolder) {
                    $this->assignments->change($existing, $newHolder, $assignmentChange);
                }

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

            if ($newHolder) {
                $this->assignments->assign($asset, $newHolder, null, true);
                $asset->refresh();
            }

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
     * General use (like a pool car): the asset is shared, so it has no holder. Network devices are never assigned to a
     * person, so the flag does not apply to them. When the asset is (or is being made) general use, any assignee sent is
     * ignored and an existing one is cleared; going through save() means the clearing is logged like any unassign
     * (ict_asset_transfers, previous holder, audit_logs). A save that mentions neither key leaves both alone.
     */
    protected function normaliseGeneralUse(array $data, string $category, ?IctAsset $existing): array
    {
        if (array_key_exists('is_general_use', $data)) {
            $data['is_general_use'] = $category !== IctAsset::DEVICE_CATEGORY_NETWORK && (bool) $data['is_general_use'];
        }

        $general = array_key_exists('is_general_use', $data) ? $data['is_general_use'] : (bool) $existing?->is_general_use;

        if ($general && (array_key_exists('is_general_use', $data) || array_key_exists('assigned_to_employee_id', $data))) {
            $data['assigned_to_employee_id'] = null;
        }

        return $data;
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
