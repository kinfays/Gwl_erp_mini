<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MileageLog extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'uuid',
        'vehicle_id',
        'driver_id',
        'mileage_before',
        'mileage_after',
        'distance_driven',
        'trip_date',
        'trip_purpose',
        'recorded_at',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'driver_id' => 'integer',
        'mileage_before' => 'integer',
        'mileage_after' => 'integer',
        'distance_driven' => 'integer',
        'trip_date' => 'date',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (MileageLog $log): void {
            $log->distance_driven = max(0, (int) $log->mileage_after - (int) $log->mileage_before);
            $log->recorded_at ??= now();
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
