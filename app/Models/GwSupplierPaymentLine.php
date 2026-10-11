<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The packs a payment request to G Water was raised for ("550 bottle packs x 26.00"), which the waybill-outstanding view sets
 * against the packs delivered on waybills. A payment may have no lines (a plain amount); then it adds nothing to that view.
 */
class GwSupplierPaymentLine extends Model
{
    protected $fillable = ['payment_id', 'product_id', 'packs', 'unit_price'];

    protected $casts = ['packs' => 'integer', 'unit_price' => 'integer'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(GwSupplierPayment::class, 'payment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }
}
