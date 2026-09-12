<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditUnionInterestDistribution extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMPUTED = 'computed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_COMPUTED,
        self::STATUS_APPROVED,
        self::STATUS_POSTED,
    ];

    protected $fillable = [
        'period_label',
        'period_start_date',
        'period_end_date',
        'total_interest_pool',
        'credit_account_type',
        'status',
        'computed_by',
        'computed_at',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'notes',
    ];

    protected $casts = [
        'period_start_date' => 'date',
        'period_end_date' => 'date',
        'total_interest_pool' => 'decimal:2',
        'computed_by' => 'integer',
        'approved_by' => 'integer',
        'posted_by' => 'integer',
        'computed_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CreditUnionInterestDistributionLine::class, 'interest_distribution_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isComputed(): bool
    {
        return $this->status === self::STATUS_COMPUTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isRecomputable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_COMPUTED], true);
    }

    /**
     * What the lines actually add up to - should equal total_interest_pool exactly once
     * the rounding remainder has been absorbed.
     */
    public function lineTotal(): float
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return round($lines->sum(fn (CreditUnionInterestDistributionLine $line) => (float) $line->amount), 2);
    }

    public function scopeForPeriod($query, string $periodLabel)
    {
        return $query->where('period_label', $periodLabel);
    }
}
