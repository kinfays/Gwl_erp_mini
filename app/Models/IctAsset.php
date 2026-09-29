<?php

namespace App\Models;

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
            'PC' => 'Computer (PC)',
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
        'assigned_to_employee_id',
        'previous_assigned_to_employee_id',
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
        'previous_assigned_to_employee_id' => 'integer',
        'department_id' => 'integer',
        'region_id' => 'integer',
        'district_id' => 'integer',
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

    public function assetModel(): BelongsTo
    {
        return $this->belongsTo(IctAssetModel::class, 'ict_asset_model_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to_employee_id');
    }

    public function previousAssignedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'previous_assigned_to_employee_id');
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
