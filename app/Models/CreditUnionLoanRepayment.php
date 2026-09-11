<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUnionLoanRepayment extends Model
{
    use HasFactory;

    public const SOURCE_PAYROLL_DEDUCTION = 'payroll_deduction';
    public const SOURCE_CASH = 'cash';
    public const SOURCE_CHEQUE = 'cheque';

    public const SOURCES = [
        self::SOURCE_PAYROLL_DEDUCTION,
        self::SOURCE_CASH,
        self::SOURCE_CHEQUE,
    ];

    /** Associate members are never on GWL payroll, so they repay in cash or by cheque. */
    public const ASSOCIATE_SOURCES = [
        self::SOURCE_CASH,
        self::SOURCE_CHEQUE,
    ];

    protected $fillable = [
        'loan_id',
        'amount',
        'repayment_date',
        'source',
        'deduction_batch_id',
        'reference_no',
        'balance_after',
        'recorded_by',
    ];

    protected $casts = [
        'loan_id' => 'integer',
        'deduction_batch_id' => 'integer',
        'recorded_by' => 'integer',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'repayment_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CreditUnionLoan::class, 'loan_id');
    }

    public function deductionBatch(): BelongsTo
    {
        return $this->belongsTo(CreditUnionDeductionBatch::class, 'deduction_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function sourcesFor(CreditUnionMember $member): array
    {
        return $member->isAssociate() ? self::ASSOCIATE_SOURCES : self::SOURCES;
    }
}
