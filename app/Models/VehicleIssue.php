<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleIssue extends Model
{
    use HasFactory;
    use HasUuid;

    public const ISSUE_TYPES = [
        'engine',
        'tires',
        'lights',
        'brakes',
        'battery',
        'bodywork',
        'suspension',
        'other',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_IN_MAINTENANCE = 'in_maintenance';
    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_REVIEW,
        self::STATUS_IN_MAINTENANCE,
        self::STATUS_RESOLVED,
    ];

    protected $fillable = [
        'uuid',
        'vehicle_id',
        'reported_by',
        'issue_types',
        'description',
        'severity',
        'status',
        'photo_path',
        'reported_at',
        'resolved_at',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'reported_by' => 'integer',
        'issue_types' => 'array',
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (VehicleIssue $issue): void {
            $issue->reported_at ??= now();
            $issue->status ??= self::STATUS_OPEN;
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
