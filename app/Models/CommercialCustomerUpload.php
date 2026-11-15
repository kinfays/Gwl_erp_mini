<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One uploaded customer-list FILE (Phase 8b). A file may hold one district, several, or every district of a region; the parent keeps the
 * stored workbook, what the first cheap pass found in it (districts with their route and customer counts) and the overall progress,
 * and every district found has ONE child batch (CommercialCustomerBatch, upload_id) that carries its own lock, phases, undo data and
 * control totals exactly as a one-district file always did.
 *
 * The parent's status while its children are being worked on is {@see self::statusFromBatches()}, the ONE place that works it out
 * from them (never re-derived in a screen, a count or a filter).
 */
class CommercialCustomerUpload extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DISCOVERING = 'discovering';
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';
    public const STATUS_NEEDS_MATCH = 'needs_match';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_FAILED = 'failed';
    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_QUEUED, self::STATUS_DISCOVERING, self::STATUS_AWAITING_REVIEW, self::STATUS_NEEDS_MATCH, self::STATUS_PROCESSING,
        self::STATUS_IMPORTED, self::STATUS_PARTIAL, self::STATUS_BLOCKED, self::STATUS_FAILED, self::STATUS_VOIDED,
    ];

    public const PHASE_QUEUED = 'queued';
    public const PHASE_DISCOVER = 'discover';
    public const PHASE_REVIEW = 'review';
    public const PHASE_STAGE = 'stage';
    public const PHASE_CHILDREN = 'children';
    public const PHASE_DONE = 'done';

    protected $fillable = [
        'region_id', 'region_label_raw', 'source_filename', 'file_path', 'file_hash', 'as_of_date', 'period_type', 'period_key', 'status', 'phase', 'review',
        'districts_found', 'districts_selected', 'routes_found', 'rows_found', 'rows_skipped', 'districts', 'unrecognised', 'errors', 'warnings',
        'error_message', 'notes', 'imported_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'review' => 'boolean',
        'districts' => 'array',
        'unrecognised' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(CommercialCustomerBatch::class, 'upload_id')->orderBy('id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_REVIEW => 'Check the districts',
            self::STATUS_NEEDS_MATCH => 'Needs a match',
            self::STATUS_PARTIAL => 'Partly loaded',
            default => ucfirst($status),
        };
    }

    /** Still being worked on by a job (the screens poll while this is true). */
    public function isWorking(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_DISCOVERING, self::STATUS_PROCESSING], true);
    }

    /**
     * The status of a file from its children (the ONE place). Working on any child makes the file "processing"; a child waiting for a
     * match parks the file; once every child is settled it is imported (all loaded), partial (some loaded, some blocked / failed),
     * failed (none loaded, one stopped), blocked (none loaded) or voided (every child undone).
     *
     * @param  Collection<int, CommercialCustomerBatch>  $batches
     */
    public static function statusFromBatches(Collection $batches, string $current = self::STATUS_QUEUED): string
    {
        if ($batches->isEmpty()) {
            return $current;
        }

        $states = $batches->pluck('status');

        if ($states->contains(fn (string $s) => in_array($s, [CommercialCustomerBatch::STATUS_QUEUED, CommercialCustomerBatch::STATUS_PROCESSING], true))) {
            return self::STATUS_PROCESSING;
        }

        if ($states->contains(CommercialCustomerBatch::STATUS_NEEDS_MATCH)) {
            return self::STATUS_NEEDS_MATCH;
        }

        $standing = $states->reject(fn (string $s) => $s === CommercialCustomerBatch::STATUS_VOIDED);

        if ($standing->isEmpty()) {
            return self::STATUS_VOIDED;
        }

        $loaded = fn (string $s) => in_array($s, CommercialCustomerBatch::LIVE, true);

        if ($standing->every($loaded)) {
            return self::STATUS_IMPORTED;
        }

        if ($standing->contains($loaded)) {
            return self::STATUS_PARTIAL;
        }

        return $standing->contains(CommercialCustomerBatch::STATUS_FAILED) ? self::STATUS_FAILED : self::STATUS_BLOCKED;
    }

    /** A rough overall progress: the average of the children's phases (a file's size is not known up front). */
    public function progressPercent(?Collection $batches = null): int
    {
        $batches ??= $this->batches;

        if (in_array($this->status, [self::STATUS_QUEUED, self::STATUS_DISCOVERING], true) || $batches->isEmpty()) {
            return $this->status === self::STATUS_DISCOVERING ? 5 : 0;
        }

        $parts = $batches->reject(fn (CommercialCustomerBatch $b) => $b->isVoided())->map(fn (CommercialCustomerBatch $b) => $b->progressPercent());

        return $parts->isEmpty() ? 0 : (int) round($parts->avg());
    }
}
