<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The CURRENT state of one customer account. Written only by the import (set-based SQL), never row by row. Balance and the
 * bill/payment amounts are integer pesewas; read them through the accessors below.
 */
class CommercialCustomer extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'balance' => 'integer',
        'last_bill_amount' => 'integer',
        'last_paid_amount' => 'integer',
        'connect_date' => 'date',
        'last_read_date' => 'date',
        'last_bill_date' => 'date',
        'last_paid_date' => 'date',
    ];

    public function contact(): HasOne
    {
        return $this->hasOne(CommercialCustomerContact::class, 'customer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CommercialCustomerCategory::class, 'category_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CommercialCustomerStatus::class, 'status_id');
    }

    public function meterStatus(): BelongsTo
    {
        return $this->belongsTo(CommercialMeterStatus::class, 'meter_status_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CommercialRoute::class, 'route_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public static function cedis(?int $pesewas): ?float
    {
        return $pesewas === null ? null : $pesewas / 100;
    }
}
