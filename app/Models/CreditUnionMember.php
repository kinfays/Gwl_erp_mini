<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditUnionMember extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_STAFF = 'staff';
    public const TYPE_ASSOCIATE = 'associate';

    public const TYPES = [
        self::TYPE_STAFF,
        self::TYPE_ASSOCIATE,
    ];

    public const SOURCE_HR_ADDED = 'hr_added';
    public const SOURCE_SELF_APPLIED = 'self_applied';
    public const SOURCE_ASSOCIATE_MANUAL = 'associate_manual';

    public const APPLICATION_SOURCES = [
        self::SOURCE_HR_ADDED,
        self::SOURCE_SELF_APPLIED,
        self::SOURCE_ASSOCIATE_MANUAL,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXITED = 'exited';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_EXITED,
    ];

    public const ASSOCIATE_NUMBER_PREFIX = 'P';

    protected $fillable = [
        'member_type',
        'member_number',
        'employee_id',
        'staff_id',
        'full_name',
        'phone',
        'address',
        'legacy_account_number',
        'application_source',
        'applied_by',
        'approved_by',
        'approved_at',
        'status',
        'registered_at',
        'exited_at',
        'exit_reason',
        'membership_form_fee_amount',
        'membership_form_fee_paid_at',
        'initial_share_amount',
        'initial_share_paid_at',
        'default_monthly_savings_amount',
        'default_monthly_shares_amount',
        'created_by',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'applied_by' => 'integer',
        'approved_by' => 'integer',
        'created_by' => 'integer',
        'approved_at' => 'datetime',
        'registered_at' => 'date',
        'exited_at' => 'date',
        'membership_form_fee_amount' => 'decimal:2',
        'membership_form_fee_paid_at' => 'date',
        'initial_share_amount' => 'decimal:2',
        'initial_share_paid_at' => 'date',
        'default_monthly_savings_amount' => 'decimal:2',
        'default_monthly_shares_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditUnionLedgerEntry::class, 'member_id');
    }

    public function isAssociate(): bool
    {
        return $this->member_type === self::TYPE_ASSOCIATE;
    }

    public function isStaff(): bool
    {
        return $this->member_type === self::TYPE_STAFF;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Sources a member of this type is allowed to move money through. Associate members
     * are never on GWL payroll, so cash/cheque are their only contribution routes.
     */
    public function allowedLedgerSources(): array
    {
        if ($this->isAssociate()) {
            return [
                CreditUnionLedgerEntry::SOURCE_CASH,
                CreditUnionLedgerEntry::SOURCE_CHEQUE,
                CreditUnionLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
            ];
        }

        return CreditUnionLedgerEntry::SOURCES;
    }

    public function balanceFor(string $accountType): float
    {
        return $this->balanceAsOf($accountType);
    }

    /**
     * The running balance on an account, optionally as it stood at the end of a given
     * date - the year-end snapshot an interest distribution is computed from.
     */
    public function balanceAsOf(string $accountType, ?string $asOfDate = null): float
    {
        $latest = $this->ledgerEntries()
            ->where('account_type', $accountType)
            ->when($asOfDate, fn ($query) => $query->whereDate('transaction_date', '<=', $asOfDate))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->value('balance_after');

        return (float) ($latest ?? 0);
    }

    /**
     * Combined shares + savings holdings, which is what an interest distribution is
     * shared out in proportion to.
     */
    public function assetBalanceAsOf(?string $asOfDate = null): float
    {
        return round(
            $this->balanceAsOf(CreditUnionLedgerEntry::ACCOUNT_SHARES, $asOfDate)
                + $this->balanceAsOf(CreditUnionLedgerEntry::ACCOUNT_SAVINGS, $asOfDate),
            2
        );
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOfType($query, string $memberType)
    {
        return $query->where('member_type', $memberType);
    }
}
