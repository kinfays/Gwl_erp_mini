<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleAssignmentHistory extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'uuid',
        'vehicle_id',
        'user_id',
        'driver_id',
        'assigned_by',
        'assigned_at',
        'unassigned_at',
        'notes',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'user_id' => 'integer',
        'driver_id' => 'integer',
        'assigned_by' => 'integer',
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
