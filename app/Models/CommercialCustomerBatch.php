<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded customer-list file for one district. The customers table holds the CURRENT state; the batch holds the
 * lifecycle (queued -> processing -> imported), the resume points and the counts. Its rollup rows are immutable.
 */
class CommercialCustomerBatch extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_NEEDS_MATCH = 'needs_match';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_FAILED = 'failed';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_NEEDS_MATCH, self::STATUS_BLOCKED,
        self::STATUS_FAILED, self::STATUS_IMPORTED, self::STATUS_SUPERSEDED, self::STATUS_VOIDED,
    ];

    /** Statuses in which a batch counts as data (rollups exist and are usable). */
    public const LIVE = [self::STATUS_IMPORTED, self::STATUS_SUPERSEDED];

    public const PHASE_QUEUED = 'queued';
    public const PHASE_PARSE = 'parse';
    public const PHASE_MERGE = 'merge';
    public const PHASE_MISSING = 'missing';
    public const PHASE_ROLLUP = 'rollup';
    public const PHASE_DONE = 'done';

    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    protected $fillable = [
        'region_id', 'district_id', 'region_label_raw', 'district_label_raw', 'period_type', 'period_key', 'as_of_date',
        'source_filename', 'file_path', 'file_hash', 'status', 'phase', 'staged_through_row', 'merge_cursor', 'rows_read',
        'rows_new', 'rows_changed', 'rows_unchanged', 'rows_missing', 'rows_moved', 'rows_malformed', 'duplicate_accounts', 'routes_count',
        'previous_count', 'count_change_pct', 'previous_batch_id', 'control_totals', 'errors', 'warnings',
        'reconciliation_passed', 'error_message', 'notes', 'imported_by', 'started_at', 'finished_at', 'imported_at',
        'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'control_totals' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'reconciliation_passed' => 'boolean',
        'count_change_pct' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'imported_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public static function periodKey(string $periodType, \DateTimeInterface $asOf): string
    {
        $date = \Illuminate\Support\Carbon::instance($asOf);

        return $periodType === self::PERIOD_WEEKLY ? $date->isoFormat('GGGG-[W]WW') : $date->format('Y-m');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isWorking(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), self::LIVE);
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), '!=', self::STATUS_VOIDED);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_NEEDS_MATCH => 'Needs a match',
            default => ucfirst($status),
        };
    }

    /** Where the job is, as a percentage for the progress bar (the file's size is not known up front, so phases count). */
    public function progressPercent(): int
    {
        return match ($this->phase) {
            self::PHASE_QUEUED => 0,
            self::PHASE_PARSE => 10,
            self::PHASE_MERGE => 45,
            self::PHASE_MISSING => 75,
            self::PHASE_ROLLUP => 85,
            self::PHASE_DONE => 100,
            default => 0,
        };
    }
}
