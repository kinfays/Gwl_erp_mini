<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUnionInterestDistributionLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'interest_distribution_id',
        'member_id',
        'asset_balance_at_computation',
        'share_of_pool_percent',
        'amount',
        'ledger_entry_id',
    ];

    protected $casts = [
        'interest_distribution_id' => 'integer',
        'member_id' => 'integer',
        'ledger_entry_id' => 'integer',
        'asset_balance_at_computation' => 'decimal:2',
        'share_of_pool_percent' => 'decimal:4',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(CreditUnionInterestDistribution::class, 'interest_distribution_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(CreditUnionMember::class, 'member_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(CreditUnionLedgerEntry::class, 'ledger_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->ledger_entry_id !== null;
    }

    public function scopePayable($query)
    {
        return $query->where('amount', '>', 0);
    }
}
