<?php

namespace App\Services\Assets;

use App\Models\AgentReport;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetAssignment;
use App\Models\IctAssetAuditLine;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use App\Models\IctAssetTransfer;
use App\Models\MdmDevice;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetRecordService
{
    public const DUPLICATE_NAME_MESSAGE = 'An asset with this name already exists.';

    public const DUPLICATE_SERIAL_MESSAGE = 'An asset with this serial number already exists.';

    public const PHONE_IDENTITY_MESSAGE = 'Enter at least a serial number or an IMEI for a phone.';

    /** The one asset type that must carry a serial number or an IMEI (POS terminals and SIM cards need neither). */
    public const PHONE_IDENTITY_TYPE = 'Ph';

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

    public const DELETE_PERMISSION = 'assets.delete';

    /** Whether this person holds the permission to delete assets (super_admin always does). */
    public static function mayDelete(User $actor): bool
    {
        return $actor->hasRoles('super_admin') || $actor->hasPermission(self::DELETE_PERMISSION);
    }

    /**
     * Whether $actor may CHANGE this asset: the write scope. Anyone not ICT-scoped may; a regional or Head Office ICT
     * user only the assets stamped with their own region (the service-level twin of scopeAssetsForActor(); reading is
     * wider than this and is not decided here).
     */
    public static function mayWrite(User $actor, IctAsset $asset): bool
    {
        if (! $actor->isScopedIct()) {
            return true;
        }

        $regionId = ($actor->employee ?? $actor->employeeByStaffId)?->region_id;

        return $regionId !== null && (int) $regionId === (int) $asset->region_id;
    }

    /**
     * Why an asset cannot be deleted, as short phrases ([] = it can). A hard delete would cascade away its assignment,
     * transfer, maintenance and stock-check history, orphan its issue reports and is blocked by the database for a
     * managed phone, so anything that has such a trail is refused (retire it instead); only a record that never did
     * anything can go.
     *
     * @return array<int, string>
     */
    public static function deletionBlockers(IctAsset $asset): array
    {
        $blockers = [];

        if ($asset->assigned_to_employee_id || IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->where('is_current', 1)->exists()) {
            $blockers[] = 'it is currently assigned to a person';
        }

        // Any row, even a deleted one: the foreign key to the asset is RESTRICT.
        if (MdmDevice::query()->where('ict_asset_id', $asset->id)->exists()) {
            $blockers[] = 'it is linked to a managed phone (MDM)';
        }

        $history = [
            'assignment record' => IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->count(),
            'transfer record' => IctAssetTransfer::query()->where(fn ($q) => $q->where('ict_asset_id', $asset->id)->orWhere('related_asset_id', $asset->id))->count(),
            'maintenance record' => IctAssetMaintenance::query()->where('ict_asset_id', $asset->id)->count(),
            'issue report' => IctAssetIssueReport::query()->where('linked_asset_id', $asset->id)->count(),
            'stock-check line' => IctAssetAuditLine::query()->where('ict_asset_id', $asset->id)->count(),
            'agent report' => AgentReport::query()->where('ict_asset_id', $asset->id)->count(),
        ];

        foreach ($history as $label => $count) {
            if ($count > 0) {
                $blockers[] = "it has {$count} {$label}".($count === 1 ? '' : 's');
            }
        }

        return $blockers;
    }

    /**
     * Delete one asset. The permission is checked here (a hidden button is only a courtesy), then, inside one
     * transaction, the asset is re-read `lockForUpdate` and the write scope and the blockers are judged on THAT copy.
     *
     * - no permission: AuthorizationException (403);
     * - gone, or outside the actor's write scope: ModelNotFoundException (404, never "forbidden", so another region's
     *   asset is not confirmed to exist);
     * - assigned, MDM-linked or with a history: ValidationException on `delete` with the reasons; nothing changes.
     *
     * One audit row holds ids and codes only (never the name, serial, IMEI or holder).
     */
    public function delete(IctAsset $asset, ?User $actor = null): void
    {
        $actor ??= Auth::user();

        if (! $actor || ! self::mayDelete($actor)) {
            throw new AuthorizationException('You do not have permission to delete assets.');
        }

        DB::transaction(function () use ($asset, $actor) {
            $locked = IctAsset::query()->lockForUpdate()->find($asset->id);

            if (! $locked || ! self::mayWrite($actor, $locked)) {
                throw (new ModelNotFoundException)->setModel(IctAsset::class, [$asset->id]);
            }

            $blockers = self::deletionBlockers($locked);

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'delete' => 'This asset cannot be deleted because '.implode(', and ', $blockers).'. Set its status to Retired instead.',
                ]);
            }

            $record = [
                'id' => $locked->id,
                'device_category' => $locked->device_category,
                'asset_type' => $locked->asset_type,
                'status' => $locked->status,
                'ict_asset_model_id' => $locked->ict_asset_model_id,
                'region_id' => $locked->region_id,
                'district_id' => $locked->district_id,
            ];

            $locked->delete();

            AuditLog::record(
                action: "delete_{$locked->device_category}",
                module: Permission::MODULE_ASSETS,
                targetType: 'ict_assets',
                targetId: $record['id'],
                old: $record,
                new: null,
            );
        });
    }

    /** Whether another asset already uses this serial number (compared in lower case, as MySQL's index does). */
    public static function serialTaken(string $serial, ?int $ignoreId = null): bool
    {
        return IctAsset::query()
            ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower(trim($serial))])
            ->when($ignoreId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /** Trimmed text, or NULL when blank: a blank must never reach a unique index as ''. */
    public static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** True when a phone (type Ph) has neither a serial number nor an IMEI. Other types never need one. */
    public static function phoneIdentityMissing(?string $assetType, mixed $serial, mixed $imei): bool
    {
        return $assetType === self::PHONE_IDENTITY_TYPE
            && self::blankToNull($serial) === null
            && self::blankToNull($imei) === null;
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
     * $audit = false skips the per-asset audit row of a CREATE (only the asset import passes it, and writes its own summary).
     */
    public function save(array $data, string $category, ?IctAsset $existing = null, ?string $assignmentChange = null, bool $audit = true): IctAsset
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

        // Serial and IMEI: trimmed, a blank is NULL. `serial_number` carries a unique index, so a second blank stored as ''
        // would collide with the first (this is what made brand-new phones look like duplicate names).
        foreach (['serial_number', 'imei'] as $identityKey) {
            if (array_key_exists($identityKey, $data)) {
                $data[$identityKey] = self::blankToNull($data[$identityKey]);
            }
        }

        $this->guardIdentity($data, $category, $existing);

        // A cleared <select>/date input arrives as '' — store NULL rather than
        // hand a strict-mode database an empty string for an integer/date column.
        if (array_key_exists('tag_no', $data)) {
            $data['tag_no'] = trim((string) $data['tag_no']);
        }

        foreach (['ict_asset_model_id', 'assigned_to_employee_id', 'department_id', 'region_id', 'district_id', 'purchased_at', 'status_reason', 'actual_location', 'tag_no'] as $nullableKey) {
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
            return $this->persist($data, $category, $existing, $changesHolder, $newHolder ? (int) $newHolder : null, $assignmentChange, $audit);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with another save (the indexes are the final word). Say WHICH field clashed; anything else is
            // not a name problem and is not dressed up as one.
            if (isset($data['asset_name']) && self::nameTaken($data['asset_name'], $existing?->id)) {
                throw ValidationException::withMessages(['form.asset_name' => self::DUPLICATE_NAME_MESSAGE]);
            }

            if (filled($data['serial_number'] ?? null) && self::serialTaken($data['serial_number'], $existing?->id)) {
                throw ValidationException::withMessages(['form.serial_number' => self::DUPLICATE_SERIAL_MESSAGE]);
            }

            throw $e;
        }
    }

    /**
     * Serial number and IMEI rules, checked on the values the record will have after this save:
     *  - a serial number already used by another asset is refused, naming the serial field;
     *  - a phone (type Ph) needs a serial number or an IMEI. A save that creates a record, or that mentions the type,
     *    serial or IMEI, is checked; a partial save that touches none of them (a status change, an audit correction)
     *    leaves an old phone that has neither alone, so those records are not locked out of unrelated changes.
     */
    protected function guardIdentity(array $data, string $category, ?IctAsset $existing): void
    {
        if (filled($data['serial_number'] ?? null) && self::serialTaken($data['serial_number'], $existing?->id)) {
            throw ValidationException::withMessages(['form.serial_number' => self::DUPLICATE_SERIAL_MESSAGE]);
        }

        if ($category !== IctAsset::DEVICE_CATEGORY_PHONE) {
            return;
        }

        $touches = ! $existing || array_key_exists('asset_type', $data) || array_key_exists('serial_number', $data) || array_key_exists('imei', $data);

        if (! $touches) {
            return;
        }

        $type = array_key_exists('asset_type', $data) ? $data['asset_type'] : $existing?->asset_type;
        $serial = array_key_exists('serial_number', $data) ? $data['serial_number'] : $existing?->serial_number;
        $imei = array_key_exists('imei', $data) ? $data['imei'] : $existing?->imei;

        if (self::phoneIdentityMissing($type, $serial, $imei)) {
            throw ValidationException::withMessages([
                'form.serial_number' => self::PHONE_IDENTITY_MESSAGE,
                'form.imei' => self::PHONE_IDENTITY_MESSAGE,
            ]);
        }
    }

    protected function persist(array $data, string $category, ?IctAsset $existing, bool $changesHolder, ?int $newHolder, ?string $assignmentChange, bool $audit = true): IctAsset
    {
        return DB::transaction(function () use ($data, $category, $existing, $changesHolder, $newHolder, $assignmentChange, $audit) {
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

            // A bulk import ($audit = false) writes ONE audit row for the whole file, never one per asset with its name.
            if ($audit) {
                AuditLog::record(
                    action: "create_{$category}",
                    module: Permission::MODULE_ASSETS,
                    targetType: 'ict_assets',
                    targetId: $asset->id,
                    old: null,
                    new: $this->redact($asset->toArray()),
                );
            }

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
