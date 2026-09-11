<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUnionLedgerEntry extends Model
{
    use HasFactory;

    public const ACCOUNT_SHARES = 'shares';
    public const ACCOUNT_SAVINGS = 'savings';

    public const ACCOUNT_TYPES = [
        self::ACCOUNT_SHARES,
        self::ACCOUNT_SAVINGS,
    ];

    public const ENTRY_CONTRIBUTION = 'contribution';
    public const ENTRY_WITHDRAWAL = 'withdrawal';
    public const ENTRY_INTEREST = 'interest';
    public const ENTRY_ADJUSTMENT = 'adjustment';
    public const ENTRY_REFUND = 'refund';

    public const ENTRY_TYPES = [
        self::ENTRY_CONTRIBUTION,
        self::ENTRY_WITHDRAWAL,
        self::ENTRY_INTEREST,
        self::ENTRY_ADJUSTMENT,
        self::ENTRY_REFUND,
    ];

    public const SOURCE_PAYROLL_DEDUCTION = 'payroll_deduction';
    public const SOURCE_CASH = 'cash';
    public const SOURCE_CHEQUE = 'cheque';
    public const SOURCE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const SOURCES = [
        self::SOURCE_PAYROLL_DEDUCTION,
        self::SOURCE_CASH,
        self::SOURCE_CHEQUE,
        self::SOURCE_MANUAL_ADJUSTMENT,
    ];

    protected $fillable = [
        'member_id',
        'account_type',
        'entry_type',
        'amount',
        'balance_after',
        'transaction_date',
        'source',
        'deduction_batch_id',
        'reference_no',
        'remarks',
        'recorded_by',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'deduction_batch_id' => 'integer',
        'recorded_by' => 'integer',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'transaction_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(CreditUnionMember::class, 'member_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function deductionBatch(): BelongsTo
    {
        return $this->belongsTo(CreditUnionDeductionBatch::class, 'deduction_batch_id');
    }

    /**
     * The amount as it moves the running balance: withdrawals always debit, adjustments
     * carry their own sign, everything else credits.
     */
    public function signedAmount(): float
    {
        return self::signedAmountFor($this->entry_type, (float) $this->amount);
    }

    public static function signedAmountFor(string $entryType, float $amount): float
    {
        return match ($entryType) {
            self::ENTRY_WITHDRAWAL => -abs($amount),
            self::ENTRY_ADJUSTMENT => $amount,
            default => abs($amount),
        };
    }

    public function isDebit(): bool
    {
        return $this->signedAmount() < 0;
    }

    public function scopeForAccount($query, string $accountType)
    {
        return $query->where('account_type', $accountType);
    }
}
