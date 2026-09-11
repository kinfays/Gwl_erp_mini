<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditUnionLoan extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_GUARANTOR = 'awaiting_guarantor';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DISBURSED = 'disbursed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DEFAULTED = 'defaulted';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_GUARANTOR,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_DISBURSED,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_DEFAULTED,
    ];

    /** A loan in one of these states counts as money still out with the member. */
    public const OUTSTANDING_STATUSES = [
        self::STATUS_DISBURSED,
        self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'member_id',
        'loan_number',
        'principal_amount',
        'interest_amount',
        'total_repayable',
        'interest_rate',
        'term_months',
        'monthly_installment_amount',
        'savings_balance_at_application',
        'no_guarantor_limit',
        'guarantor_shortfall',
        'requires_guarantor',
        'status',
        'purpose',
        'applied_at',
        'applied_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'disbursed_at',
        'disbursement_reference',
        'outstanding_balance',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'applied_by' => 'integer',
        'approved_by' => 'integer',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_repayable' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'term_months' => 'integer',
        'monthly_installment_amount' => 'decimal:2',
        'savings_balance_at_application' => 'decimal:2',
        'no_guarantor_limit' => 'decimal:2',
        'guarantor_shortfall' => 'decimal:2',
        'requires_guarantor' => 'boolean',
        'outstanding_balance' => 'decimal:2',
        'applied_at' => 'datetime',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(CreditUnionMember::class, 'member_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(CreditUnionLoanGuarantor::class, 'loan_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(CreditUnionLoanRepayment::class, 'loan_id');
    }

    public function isDecidable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_AWAITING_GUARANTOR], true);
    }

    public function isRepayable(): bool
    {
        return in_array($this->status, self::OUTSTANDING_STATUSES, true);
    }

    /**
     * Sum of guaranteed_amount across guarantors who have actually accepted.
     */
    public function acceptedGuaranteeTotal(): float
    {
        $guarantors = $this->relationLoaded('guarantors') ? $this->guarantors : $this->guarantors()->get();

        return round(
            $guarantors
                ->where('status', CreditUnionLoanGuarantor::STATUS_ACCEPTED)
                ->sum(fn (CreditUnionLoanGuarantor $guarantor) => (float) $guarantor->guaranteed_amount),
            2
        );
    }

    public function guaranteeShortfallRemaining(): float
    {
        return round(max(0, (float) $this->guarantor_shortfall - $this->acceptedGuaranteeTotal()), 2);
    }

    public function isFullyGuaranteed(): bool
    {
        return ! $this->requires_guarantor || $this->guaranteeShortfallRemaining() <= 0;
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    public function scopeForMember($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }
}
