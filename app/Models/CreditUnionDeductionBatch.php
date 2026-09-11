<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditUnionDeductionBatch extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_POSTED = 'posted';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_VARIANCE = 'variance';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IMPORTED,
        self::STATUS_POSTED,
        self::STATUS_RECONCILED,
        self::STATUS_VARIANCE,
    ];

    protected $fillable = [
        'period_month',
        'bank_reference',
        'banked_date',
        'amount_received',
        'amount_posted',
        'status',
        'import_file_path',
        'imported_by',
        'imported_at',
        'posted_by',
        'posted_at',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'date',
        'banked_date' => 'date',
        'amount_received' => 'decimal:2',
        'amount_posted' => 'decimal:2',
        'imported_by' => 'integer',
        'posted_by' => 'integer',
        'imported_at' => 'datetime',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CreditUnionDeductionBatchLine::class, 'deduction_batch_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditUnionLedgerEntry::class, 'deduction_batch_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPostable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_IMPORTED], true);
    }

    public function hasBeenPosted(): bool
    {
        return in_array($this->status, [self::STATUS_POSTED, self::STATUS_RECONCILED, self::STATUS_VARIANCE], true);
    }

    /**
     * What the batch still owes (or over-posted) against the bulk remittance it came from.
     */
    public function variance(): float
    {
        return round((float) $this->amount_posted - (float) $this->amount_received, 2);
    }

    public function scopeForPeriod($query, string $periodMonth)
    {
        return $query->whereDate('period_month', $periodMonth);
    }
}
