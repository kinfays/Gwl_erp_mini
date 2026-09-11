<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUnionLoanGuarantor extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
    ];

    protected $fillable = [
        'loan_id',
        'guarantor_member_id',
        'guaranteed_amount',
        'guarantor_asset_balance_at_guarantee',
        'status',
        'disqualified_reason',
        'eligibility_checked_at',
        'was_in_good_standing',
        'responded_at',
    ];

    protected $casts = [
        'loan_id' => 'integer',
        'guarantor_member_id' => 'integer',
        'guaranteed_amount' => 'decimal:2',
        'guarantor_asset_balance_at_guarantee' => 'decimal:2',
        'was_in_good_standing' => 'boolean',
        'eligibility_checked_at' => 'datetime',
        'responded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CreditUnionLoan::class, 'loan_id');
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(CreditUnionMember::class, 'guarantor_member_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function wasDisqualified(): bool
    {
        return $this->disqualified_reason !== null;
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }
}
