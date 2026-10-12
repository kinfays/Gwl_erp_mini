<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received against a credit sale (pesewas). Voided with a reason, never edited or deleted. */
class GwSaleReceipt extends Model
{
    use GwBooksRow;

    protected $fillable = [
        'region_id', 'district_id', 'sale_id', 'received_on', 'amount', 'reference', 'note', 'status',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'sale_id' => 'integer',
        'received_on' => 'date',
        'amount' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(GwSale::class, 'sale_id');
    }
}
