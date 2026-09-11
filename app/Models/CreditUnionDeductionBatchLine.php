<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUnionDeductionBatchLine extends Model
{
    use HasFactory;

    public const MATCH_MATCHED = 'matched';
    public const MATCH_UNMATCHED = 'unmatched';
    public const MATCH_SKIPPED = 'skipped';
    public const MATCH_INVALID_ASSOCIATE = 'invalid_associate_member';

    public const MATCH_STATUSES = [
        self::MATCH_MATCHED,
        self::MATCH_UNMATCHED,
        self::MATCH_SKIPPED,
        self::MATCH_INVALID_ASSOCIATE,
    ];

    protected $fillable = [
        'deduction_batch_id',
        'member_id',
        'staff_id_raw',
        'name_raw',
        'shares_amount',
        'savings_amount',
        'loan_repayment_amount',
        'loan_repayment_posted',
        'match_status',
        'resolution_notes',
    ];

    protected $casts = [
        'deduction_batch_id' => 'integer',
        'member_id' => 'integer',
        'shares_amount' => 'decimal:2',
        'savings_amount' => 'decimal:2',
        'loan_repayment_amount' => 'decimal:2',
        'loan_repayment_posted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CreditUnionDeductionBatch::class, 'deduction_batch_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(CreditUnionMember::class, 'member_id');
    }

    public function isMatched(): bool
    {
        return $this->match_status === self::MATCH_MATCHED && $this->member_id !== null;
    }

    /**
     * Shares + savings only. loan_repayment_amount is captured but has nothing to post
     * against until Phase 3 introduces credit_union_loans.
     */
    public function postableAmount(): float
    {
        return round((float) $this->shares_amount + (float) $this->savings_amount, 2);
    }

    public function totalAmount(): float
    {
        return round($this->postableAmount() + (float) $this->loan_repayment_amount, 2);
    }

    public function hasUnpostedLoanRepayment(): bool
    {
        return (float) $this->loan_repayment_amount > 0 && ! $this->loan_repayment_posted;
    }

    public function scopeMatched($query)
    {
        return $query->where('match_status', self::MATCH_MATCHED)->whereNotNull('member_id');
    }

    public function scopeNeedingResolution($query)
    {
        return $query->whereIn('match_status', [
            self::MATCH_UNMATCHED,
            self::MATCH_SKIPPED,
            self::MATCH_INVALID_ASSOCIATE,
        ]);
    }
}
