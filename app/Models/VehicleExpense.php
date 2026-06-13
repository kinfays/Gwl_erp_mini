<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleExpense extends Model
{
    use HasFactory;
    use HasUuid;

    public const TYPES = [
        'fuel',
        'insurance',
        'maintenance',
        'repair',
        'road_worthiness',
        'parking',
        'toll',
        'fine',
        'other',
    ];

    protected $fillable = [
        'uuid',
        'vehicle_id',
        'expense_type',
        'amount',
        'currency',
        'description',
        'receipt_photo_path',
        'expense_date',
        'recorded_by',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'recorded_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
