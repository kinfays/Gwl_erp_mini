<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A day's packs of one product sold at the selling price in force that day (frozen on the row). Money in pesewas. */
class GwSale extends Model
{
    use GwBooksRow;

    protected $fillable = [
        'region_id', 'district_id', 'sale_date', 'product_id', 'packs', 'unit_price', 'amount', 'note', 'status',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'sale_date' => 'date',
        'packs' => 'integer',
        'unit_price' => 'integer',
        'amount' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }
}
