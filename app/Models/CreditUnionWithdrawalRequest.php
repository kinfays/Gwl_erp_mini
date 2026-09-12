<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditUnionWithdrawalRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
    ];

    public const METHOD_CASH = 'cash';
    public const METHOD_CHEQUE = 'cheque';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const PAYMENT_METHODS = [
        self::METHOD_CASH,
        self::METHOD_CHEQUE,
        self::METHOD_BANK_TRANSFER,
    ];

    /** Associate members are paid over the counter, never through payroll banking. */
    public const ASSOCIATE_PAYMENT_METHODS = [
        self::METHOD_CASH,
        self::METHOD_CHEQUE,
    ];

    protected $fillable = [
        'member_id',
        'savings_amount',
        'shares_amount',
        'reason',
        'status',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'rejection_reason',
        'payment_method',
        'payment_reference',
        'paid_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'requested_by' => 'integer',
        'decided_by' => 'integer',
        'savings_amount' => 'decimal:2',
        'shares_amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(CreditUnionMember::class, 'member_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditUnionLedgerEntry::class, 'withdrawal_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function totalAmount(): float
    {
        return round((float) $this->savings_amount + (float) $this->shares_amount, 2);
    }

    /**
     * @return array<string, float> account_type => amount, zero legs dropped
     */
    public function payableAccounts(): array
    {
        return collect([
            CreditUnionLedgerEntry::ACCOUNT_SAVINGS => round((float) $this->savings_amount, 2),
            CreditUnionLedgerEntry::ACCOUNT_SHARES => round((float) $this->shares_amount, 2),
        ])->filter(fn (float $amount) => $amount > 0)->all();
    }

    public static function paymentMethodsFor(CreditUnionMember $member): array
    {
        return $member->isAssociate() ? self::ASSOCIATE_PAYMENT_METHODS : self::PAYMENT_METHODS;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForMember($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }
}
