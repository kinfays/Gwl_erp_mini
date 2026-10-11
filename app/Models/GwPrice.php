<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated row of the price list: selling and cost price (pesewas) of a product from `effective_from`. Region and district
 * both NULL = the system placeholder default. Rows are immutable (GwaterPricingService adds a new row for every change).
 */
class GwPrice extends Model
{
    protected $fillable = [
        'region_id', 'district_id', 'product_id', 'effective_from', 'selling_price', 'cost_price', 'note', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'product_id' => 'integer',
        'effective_from' => 'date',
        'selling_price' => 'integer',
        'cost_price' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDefault(): bool
    {
        return $this->region_id === null && $this->district_id === null;
    }
}
