<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRecord extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'uuid',
        'vehicle_id',
        'maintenance_type',
        'description',
        'performed_by',
        'mileage_at_service',
        'cost',
        'receipt_photo_path',
        'service_date',
        'next_service_date',
        'next_service_mileage',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'mileage_at_service' => 'integer',
        'cost' => 'decimal:2',
        'service_date' => 'date',
        'next_service_date' => 'date',
        'next_service_mileage' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
