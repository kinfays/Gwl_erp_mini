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

    /** Phase 8c: "Customer Meter Reading Report - CCA Summary - By Route": verified strength and counts per CCA per route, for any dates. */
    public const TYPE_READING_ROUTE_SUMMARY = 'reading_route_summary';

    /** Phase 8d: the Detailed Billing Report (rptBillingDetails_ExP): one row per customer in the file, kept as rollups and capped lists. */
    public const TYPE_BILLING_DETAILS = 'billing_details';

    public const TYPES = [
        self::TYPE_READING_SUMMARY,
        self::TYPE_BILLING_SUMMARY,
        self::TYPE_READING_ROUTE_SUMMARY,
        self::TYPE_BILLING_DETAILS,
    ];

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_IMPORTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_VOIDED,
    ];

    // Phase 8d: a Detailed Billing Report is read in the background, so its batch passes through states the other reports never have.
    // Only imported and superseded count in an analysis (scopeLive()).
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    /** Every status a batch can be in, for the history filter. */
    public const ALL_STATUSES = [
        self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_AWAITING_REVIEW, self::STATUS_IMPORTED, self::STATUS_SUPERSEDED,
        self::STATUS_BLOCKED, self::STATUS_FAILED, self::STATUS_VOIDED,
    ];

    /** The states a background run is still working or waiting in. */
    public const IN_FLIGHT = [self::STATUS_QUEUED, self::STATUS_PROCESSING];

    /** Phase 8h: where a batch's rows came from. 'upload' = a workbook somebody uploaded (every batch before 8h); 'derived' = built from a Detailed Billing Report. */
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_DERIVED = 'derived';

    public const GRANULARITY_MONTHLY = 'monthly';

    public const GRANULARITY_MULTI_MONTH = 'multi_month';

    /** The segment stored when a billing report carries no BILLING STATUS filter. */
    public const SEGMENT_ALL = 'all';

    public const SEGMENT_NEW_SERVICE = 'new_service';

    protected $fillable = [
        'report_type',
        'source',
        'derived_from_batch_id',
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
        'phase',
        'progress',
        'error_message',
        'review',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'period_from' => 'date',
        'period_to' => 'date',
        'row_count' => 'integer',
        'matched_count' => 'integer',
        'warning_count' => 'integer',
        'control_totals' => 'array',
        'progress' => 'array',
        'review' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'reconciliation_passed' => 'boolean',
        'supersedes_batch_id' => 'integer',
        'derived_from_batch_id' => 'integer',
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
            self::TYPE_READING_ROUTE_SUMMARY => 'Meter reading by route',
            self::TYPE_BILLING_DETAILS => 'Detailed billing',
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

    /** The rows of a reading-by-route batch (one per CCA per route). */
    public function routeStats(): HasMany
    {
        return $this->hasMany(CommercialReadingRouteStat::class, 'batch_id');
    }

    public function bands(): HasMany
    {
        return $this->hasMany(CommercialBillingBand::class, 'batch_id');
    }

    /** The aggregates of a Detailed Billing Report batch (Phase 8d). */
    public function detailRollups(): HasMany
    {
        return $this->hasMany(CommercialBillingDetailRollup::class, 'batch_id');
    }

    public function detailBands(): HasMany
    {
        return $this->hasMany(CommercialBillingDetailBand::class, 'batch_id');
    }

    public function detailIssues(): HasMany
    {
        return $this->hasMany(CommercialBillingDetailIssue::class, 'batch_id');
    }

    /** What the customer-list link says about the accounts (Phase 8e): status / category movements per route, and the district matrix. */
    public function detailTransitions(): HasMany
    {
        return $this->hasMany(CommercialBillingDetailTransition::class, 'batch_id');
    }

    public function detailDistrictMatrix(): HasMany
    {
        return $this->hasMany(CommercialBillingDetailDistrictCell::class, 'batch_id');
    }

    public function isReading(): bool
    {
        return $this->report_type === self::TYPE_READING_SUMMARY;
    }

    /** A billing summary built from a Detailed Billing Report (Phase 8h): no workbook, rebuilt whenever its source changes. */
    public function isDerived(): bool
    {
        return $this->source === self::SOURCE_DERIVED;
    }

    /** The Detailed Billing Report batch a derived summary was built from. */
    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'derived_from_batch_id');
    }

    /** The derived billing summary built from this (detailed) batch, if one exists. */
    public function derivedBatch(): HasMany
    {
        return $this->hasMany(self::class, 'derived_from_batch_id');
    }

    /** The label used wherever a figure of a derived batch is shown. */
    public function sourceLabel(): ?string
    {
        return $this->isDerived() ? 'from the detailed billing report' : null;
    }

    public function isBilling(): bool
    {
        return $this->report_type === self::TYPE_BILLING_SUMMARY;
    }

    /** The reading report BY ROUTE (Phase 8c); isReading() stays the legacy reader-by-month report only. */
    public function isReadingRoute(): bool
    {
        return $this->report_type === self::TYPE_READING_ROUTE_SUMMARY;
    }

    /** The Detailed Billing Report (Phase 8d). isBilling() stays the billing SUMMARY only. */
    public function isBillingDetails(): bool
    {
        return $this->report_type === self::TYPE_BILLING_DETAILS;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /** Counts in the analyses: imported or superseded (a label only). A queued, running, waiting, blocked or failed run does not. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_IMPORTED, self::STATUS_SUPERSEDED], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT, true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_REVIEW => 'Waiting for you to check it',
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_PROCESSING => 'Being read',
            default => str($status)->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), '!=', self::STATUS_VOIDED);
    }

    /**
     * The batches that count in an analysis: imported or superseded. For the three older report types that is exactly notVoided(); a
     * Detailed Billing Report run that is queued, running, waiting for review, blocked or failed is not live (Phase 8d).
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), [self::STATUS_IMPORTED, self::STATUS_SUPERSEDED]);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($query->qualifyColumn('report_type'), $type);
    }
}
