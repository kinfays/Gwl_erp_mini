<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product of an opening balance: the packs brought forward (also posted as an `opening` ledger row), the cost per pack
 * they are valued at in the profit and loss (frozen from the price list on the opening date), and the packs already paid for
 * but not yet delivered on a waybill (the "outstanding" of the Excel sheets).
 */
class GwOpeningBalanceLine extends Model
{
    protected $fillable = ['opening_balance_id', 'product_id', 'packs', 'unit_cost', 'outstanding_packs'];

    protected $casts = ['packs' => 'integer', 'unit_cost' => 'integer', 'outstanding_packs' => 'integer'];

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(GwOpeningBalance::class, 'opening_balance_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }
}
