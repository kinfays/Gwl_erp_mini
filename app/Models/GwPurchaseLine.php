<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GwPurchaseLine extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'packs', 'unit_cost'];

    protected $casts = ['packs' => 'integer', 'unit_cost' => 'integer'];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(GwPurchase::class, 'purchase_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }
}
