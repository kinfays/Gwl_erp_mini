<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IctAssetMaintenance extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'Open';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    /** What the maintenance form offers. A record is "closed" (and carries a completion date) only when Completed. */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'ict_asset_maintenances';

    protected $fillable = [
        'ict_asset_id',
        'maintenance_type',
        'status',
        'reported_date',
        'completion_date',
        'technician',
        'location',
        'notes',
        'performed_by_user_id',
    ];

    protected $casts = [
        'ict_asset_id' => 'integer',
        'performed_by_user_id' => 'integer',
        'reported_date' => 'date',
        'completion_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}

