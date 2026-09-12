<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUnionManualReceipt extends Model
{
    use HasFactory;

    public const METHOD_CASH = 'cash';
    public const METHOD_CHEQUE = 'cheque';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_CHEQUE,
    ];

    public const PURPOSE_SAVINGS = 'savings';
    public const PURPOSE_SHARES = 'shares';
    public const PURPOSE_LOAN_REPAYMENT = 'loan_repayment';
    public const PURPOSE_MEMBERSHIP_FORM_FEE = 'membership_form_fee';

    public const PURPOSES = [
        self::PURPOSE_SAVINGS,
        self::PURPOSE_SHARES,
        self::PURPOSE_LOAN_REPAYMENT,
        self::PURPOSE_MEMBERSHIP_FORM_FEE,
    ];

    /** Purposes that land in the member's shares/savings ledger once banked. */
    public const LEDGER_PURPOSES = [
        self::PURPOSE_SAVINGS,
        self::PURPOSE_SHARES,
    ];

    protected $fillable = [
        'member_id',
        'method',
        'purpose',
        'cheque_no',
        'payer_name',
        'amount',
        'received_date',
        'banked_date',
        'banked',
        'recorded_by',
        'remarks',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'recorded_by' => 'integer',
        'amount' => 'decimal:2',
        'received_date' => 'date',
        'banked_date' => 'date',
        'banked' => 'boolean',
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

    public function isLedgerPurpose(): bool
    {
        return in_array($this->purpose, self::LEDGER_PURPOSES, true);
    }

    /**
     * The membership form fee is a non-refundable admin charge, not a member asset, so it
     * is recorded here and never posted to the ledger - same rule as at registration.
     */
    public function isMembershipFormFee(): bool
    {
        return $this->purpose === self::PURPOSE_MEMBERSHIP_FORM_FEE;
    }

    /**
     * A cash/cheque receipt maps straight onto the matching ledger source.
     */
    public function ledgerSource(): string
    {
        return $this->method === self::METHOD_CHEQUE
            ? CreditUnionLedgerEntry::SOURCE_CHEQUE
            : CreditUnionLedgerEntry::SOURCE_CASH;
    }

    public function scopeBanked($query)
    {
        return $query->where('banked', true);
    }

    public function scopeForMember($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }
}
