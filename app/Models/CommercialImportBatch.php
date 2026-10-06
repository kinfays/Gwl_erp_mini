<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialImportBatch extends Model
{
    public const TYPE_READING_SUMMARY = 'reading_summary';
    public const TYPE_BILLING_SUMMARY = 'billing_summary';

    public const TYPES = [
        self::TYPE_READING_SUMMARY,
        self::TYPE_BILLING_SUMMARY,
    ];

    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_IMPORTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_VOIDED,
    ];

    public const GRANULARITY_MONTHLY = 'monthly';
    public const GRANULARITY_MULTI_MONTH = 'multi_month';

    /** The segment stored when a billing report carries no BILLING STATUS filter. */
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_NEW_SERVICE = 'new_service';

    protected $fillable = [
        'report_type',
        'region_id',
        'region_label_raw',
        'period_from',
        'period_to',
        'granularity',
        'billing_status_raw',
        'customer_segment',
        'source_filename',
        'file_path',
        'file_hash',
        'status',
        'row_count',
        'matched_count',
        'warning_count',
        'control_totals',
        'reconciliation_passed',
        'supersedes_batch_id',
        'imported_by',
        'imported_at',
        'voided_by',
        'voided_at',
        'void_reason',
        'notes',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'period_from' => 'date',
        'period_to' => 'date',
        'row_count' => 'integer',
        'matched_count' => 'integer',
        'warning_count' => 'integer',
        'control_totals' => 'array',
        'reconciliation_passed' => 'boolean',
        'supersedes_batch_id' => 'integer',
        'imported_by' => 'integer',
        'voided_by' => 'integer',
        'imported_at' => 'datetime',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_READING_SUMMARY => 'Meter reading summary',
            self::TYPE_BILLING_SUMMARY => 'Billing summary',
            default => str($type)->replace('_', ' ')->title()->toString(),
        };
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_batch_id');
    }

    public function strengths(): HasMany
    {
        return $this->hasMany(CommercialReadingStrength::class, 'batch_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(CommercialReadingStat::class, 'batch_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(CommercialBillingRoute::class, 'batch_id');
    }

    public function bands(): HasMany
    {
        return $this->hasMany(CommercialBillingBand::class, 'batch_id');
    }

    public function isReading(): bool
    {
        return $this->report_type === self::TYPE_READING_SUMMARY;
    }

    public function isBilling(): bool
    {
        return $this->report_type === self::TYPE_BILLING_SUMMARY;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), '!=', self::STATUS_VOIDED);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($query->qualifyColumn('report_type'), $type);
    }
}
