<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditUnionRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'account_type',
        'amount',
        'reason',
        'refunded_at',
        'recorded_by',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'recorded_by' => 'integer',
        'amount' => 'decimal:2',
        'refunded_at' => 'date',
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

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditUnionLedgerEntry::class, 'refund_id');
    }

    public function scopeForMember($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }
}
