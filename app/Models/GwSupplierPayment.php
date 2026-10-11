<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;

/** A payment request / payment to G Water. The table exists from Phase 1; its screen comes in Phase 2. */
class GwSupplierPayment extends Model
{
    use GwBooksRow;

    protected $fillable = [
        'region_id', 'district_id', 'request_date', 'reference', 'amount', 'paid_on', 'note', 'status',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'request_date' => 'date',
        'amount' => 'integer',
        'paid_on' => 'date',
        'voided_at' => 'datetime',
    ];
}
