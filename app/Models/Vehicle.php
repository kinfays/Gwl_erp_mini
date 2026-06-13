<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    public const TYPE_SEDAN = 'sedan';
    public const TYPE_SUV = 'suv';
    public const TYPE_PICKUP = 'pickup';
    public const TYPE_VAN = 'van';
    public const TYPE_BUS = 'bus';
    public const TYPE_MOTORCYCLE = 'motorcycle';
    public const TYPE_TRUCK = 'truck';

    public const TYPES = [
        self::TYPE_SEDAN,
        self::TYPE_SUV,
        self::TYPE_PICKUP,
        self::TYPE_VAN,
        self::TYPE_BUS,
        self::TYPE_MOTORCYCLE,
        self::TYPE_TRUCK,
    ];

    public const DRIVER_SELF_DRIVE = 'self_drive';
    public const DRIVER_ASSIGNED = 'assigned_driver';

    public const DRIVER_TYPES = [
        self::DRIVER_SELF_DRIVE,
        self::DRIVER_ASSIGNED,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_MAINTENANCE,
        self::STATUS_RETIRED,
    ];

    protected $fillable = [
        'uuid',
        'type',
        'brand',
        'model',
        'color',
        'number_plate',
        'year_purchased',
        'is_pool_car',
        'assigned_user_id',
        'department_id',
        'driver_type',
        'assigned_driver_id',
        'current_mileage',
        'maintenance_interval_km',
        'insurance_expiry_date',
        'road_worthiness_expiry_date',
        'status',
        'photo_path',
    ];

    protected $casts = [
        'is_pool_car' => 'boolean',
        'assigned_user_id' => 'integer',
        'department_id' => 'integer',
        'assigned_driver_id' => 'integer',
        'current_mileage' => 'integer',
        'maintenance_interval_km' => 'integer',
        'insurance_expiry_date' => 'date',
        'road_worthiness_expiry_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'age',
        'next_maintenance_mileage',
        'maintenance_remaining_km',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getAgeAttribute(): ?int
    {
        return $this->year_purchased ? max(0, now()->year - (int) $this->year_purchased) : null;
    }

    public function getNextMaintenanceMileageAttribute(): int
    {
        $scheduledMileage = $this->maintenanceRecords()
            ->whereNotNull('next_service_mileage')
            ->latest('service_date')
            ->value('next_service_mileage');

        return (int) ($scheduledMileage ?: ((int) $this->current_mileage + (int) $this->maintenance_interval_km));
    }

    public function getMaintenanceRemainingKmAttribute(): int
    {
        return max(0, (int) $this->next_maintenance_mileage - (int) $this->current_mileage);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(VehicleAssignmentHistory::class);
    }

    public function mileageLogs(): HasMany
    {
        return $this->hasMany(MileageLog::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(VehicleIssue::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
