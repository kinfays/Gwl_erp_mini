<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IctAsset extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_IN_REPAIR = 'In Repair';
    public const STATUS_RETIRED = 'Retired';
    public const STATUS_LOST = 'Lost';
    public const STATUS_DAMAGED = 'Damaged';

    /** Every status a form, filter or report may offer. Add new statuses here, not at each call site. */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DAMAGED,
        self::STATUS_IN_REPAIR,
        self::STATUS_RETIRED,
        self::STATUS_LOST,
    ];

    public const UPS_STATUS_WORKING = 'Working';
    public const UPS_STATUS_SPOILED = 'Spoiled';

    public const UPS_STATUSES = [
        self::UPS_STATUS_WORKING,
        self::UPS_STATUS_SPOILED,
    ];

    /**
     * The only asset types whose UPS is tracked. Laptops run on their own battery, and phones and network devices
     * are not on the building UPS, so none of them carry these fields.
     */
    public const UPS_ASSET_TYPES = ['PC', 'AIO', 'Server', 'PRT', 'PTC'];

    /** Computers with a processor to record (printers and photocopiers have none worth tracking here). */
    public const PROCESSOR_ASSET_TYPES = ['PC', 'AIO', 'Laptop', 'Server'];

    /**
     * The asset types that can be assigned to an individual, and so are "unassigned" when nobody holds them: laptops,
     * desktops (PC, AIO), printers and photocopiers (PRT, PTC), phones and SIM cards. Everything else never counts as
     * unassigned: network devices and servers (shared infrastructure) and POS terminals (general use by default; a POS
     * given a holder anyway still shows it). This is the only place that grouping is written (there is no asset-types
     * table; the vocabulary is ASSET_TYPES below). Add a new personally assigned type here.
     */
    public const PERSONALLY_ASSIGNED_TYPES = ['PC', 'AIO', 'Laptop', 'PRT', 'PTC', 'Ph', 'SIM'];

    public const DEVICE_CATEGORY_ASSET = 'asset';
    public const DEVICE_CATEGORY_PHONE = 'phone';
    public const DEVICE_CATEGORY_NETWORK = 'network';

    public const DEVICE_CATEGORIES = [
        self::DEVICE_CATEGORY_ASSET,
        self::DEVICE_CATEGORY_PHONE,
        self::DEVICE_CATEGORY_NETWORK,
    ];

    /**
     * Canonical asset_type vocabulary per device_category. Used to populate
     * every form's AssetType/DeviceType dropdown and to bucket dashboard
     * KPI cards (e.g. the "Computers" card = PC + AIO).
     */
    public const ASSET_TYPES = [
        self::DEVICE_CATEGORY_ASSET => [
            'PC' => 'Desktop',
            'AIO' => 'All-in-One',
            'Laptop' => 'Laptop',
            'Server' => 'Server',
            'PRT' => 'Printer',
            'PTC' => 'Photocopier',
        ],
        self::DEVICE_CATEGORY_PHONE => [
            'POS' => 'POS Terminal',
            'SIM' => 'SIM Card',
            'Ph' => 'Phone',
        ],
        self::DEVICE_CATEGORY_NETWORK => [
            'RT' => 'Router',
            'SW' => 'Switch',
            'AP' => 'Access Point',
            'MiFi' => 'MiFi',
            'P2P' => 'P2P Radio',
            '4GRT' => '4G Router',
        ],
    ];

    protected $table = 'ict_assets';

    protected $fillable = [
        'asset_name',
        'serial_number',
        'asset_type',
        'device_category',
        'ict_asset_model_id',
        'status',
        'status_reason',
        'has_ups',
        'ups_status',
        'ups_notes',
        'is_general_use',
        'assigned_to_employee_id',
        'department_id',
        'region_id',
        'district_id',
        'device_ip',
        'mac_address',
        'hostname',
        'os_name',
        'os_version',
        'cpu_name',
        'ram_gb',
        'bios_version',
        'manufacturer',
        'model_name',
        'last_boot_at',
        'purchased_at',
        'warranty_expires_at',
        'notes',
        'last_seen_at',
        'agent_last_report_at',
        'imei',
        'user_phone_number',
        'device_phone_number',
        'device_username',
        'login_password',
        'ssid',
        'ssid_password',
        'actual_location',
    ];

    protected $casts = [
        'ict_asset_model_id' => 'integer',
        'assigned_to_employee_id' => 'integer',
        'department_id' => 'integer',
        'region_id' => 'integer',
        'district_id' => 'integer',
        'has_ups' => 'boolean',
        'is_general_use' => 'boolean',
        'ram_gb' => 'decimal:2',
        'last_boot_at' => 'datetime',
        'purchased_at' => 'date',
        'warranty_expires_at' => 'date',
        'last_seen_at' => 'datetime',
        'agent_last_report_at' => 'datetime',
        'login_password' => 'encrypted',
        'ssid_password' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Asset names are unique ignoring case and surrounding spaces: this is the comparable form, kept in
     * `asset_name_key` (which carries the unique index) by the saving hook below. Use it, never a LOWER()/TRIM()
     * written elsewhere, so every check agrees with the index.
     */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    protected static function booted(): void
    {
        static::saving(function (IctAsset $asset) {
            if (! is_string($asset->asset_name)) {
                return;
            }

            $asset->asset_name = trim($asset->asset_name);
            $asset->asset_name_key = self::nameKey($asset->asset_name);
        });
    }

    public static function isPersonallyAssignedType(?string $assetType): bool
    {
        return in_array($assetType, self::PERSONALLY_ASSIGNED_TYPES, true);
    }

    /** An asset needs a holder only if its type is issued to a person AND it is not marked general use. */
    public function requiresAssignee(): bool
    {
        return self::isPersonallyAssignedType($this->asset_type) && ! $this->is_general_use;
    }

    /** Assets that need a holder (personally issued type, not general use). Basis of scopeNeedsAssignee(). */
    public function scopeRequiresAssignee(Builder $query): Builder
    {
        $model = $query->getModel();

        return $query
            ->whereIn($model->qualifyColumn('asset_type'), self::PERSONALLY_ASSIGNED_TYPES)
            ->where(fn (Builder $inner) => $inner
                ->where($model->qualifyColumn('is_general_use'), false)
                ->orWhereNull($model->qualifyColumn('is_general_use')));
    }

    /**
     * THE definition of "unassigned" for dashboards, filters and exports: needs a holder and has none. Network devices,
     * servers, printers and general-use assets are never in it. Use this scope; never write the condition elsewhere.
     */
    public function scopeNeedsAssignee(Builder $query): Builder
    {
        return $query->requiresAssignee()->whereNull($query->getModel()->qualifyColumn('assigned_to_employee_id'));
    }

    /** What to print where the holder goes in plain text (exports): the holder, else General use, else Unassigned. */
    public function assigneeLabel(): string
    {
        return $this->assignedTo?->full_name ?: ($this->is_general_use ? 'General use' : 'Unassigned');
    }

    /** Whether a device of this category and type is one whose UPS is tracked. */
    public static function supportsUps(?string $category, ?string $assetType): bool
    {
        return $category === self::DEVICE_CATEGORY_ASSET && in_array($assetType, self::UPS_ASSET_TYPES, true);
    }

    /** Whether a device of this category and type is one whose processor is recorded. */
    public static function supportsProcessor(?string $category, ?string $assetType): bool
    {
        return $category === self::DEVICE_CATEGORY_ASSET && in_array($assetType, self::PROCESSOR_ASSET_TYPES, true);
    }

    public function assetModel(): BelongsTo
    {
        return $this->belongsTo(IctAssetModel::class, 'ict_asset_model_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to_employee_id');
    }

    /** Every spell anyone held this device, newest first (open row, handovers, unassigns and corrections). */
    public function assignments(): HasMany
    {
        return $this->hasMany(IctAssetAssignment::class, 'ict_asset_id')->orderByDesc('id');
    }

    /**
     * The most recent closed row that ended in a handover or an unassign (never a correction). "Previous assigned to"
     * is DERIVED from this: ict_assets.previous_assigned_to_employee_id is no longer written or read. Eager-load it as
     * 'previousAssignment.employee'.
     */
    public function previousAssignment(): HasOne
    {
        return $this->hasOne(IctAssetAssignment::class, 'ict_asset_id')->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->whereIn('end_reason', IctAssetAssignment::PREVIOUS_REASONS),
        );
    }

    /** The person who held this device before the current holder, from the assignment history. */
    public function previousHolder(): ?Employee
    {
        return $this->previousAssignment?->employee;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(IctAssetTransfer::class, 'ict_asset_id')->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(IctAssetMaintenance::class, 'ict_asset_id');
    }

    public function issueReports(): HasMany
    {
        return $this->hasMany(IctAssetIssueReport::class, 'linked_asset_id');
    }

    public function agentReports(): HasMany
    {
        return $this->hasMany(AgentReport::class, 'ict_asset_id');
    }

    /**
     * The Android Enterprise (MDM) record for a phone. Device identity stays on this asset; the MDM row only
     * holds what Google reports.
     */
    public function mdmDevice(): HasOne
    {
        return $this->hasOne(MdmDevice::class, 'ict_asset_id');
    }
}
