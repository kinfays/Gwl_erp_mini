<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One aggregate of the Detailed Billing Report (design 8.39): the customers of ONE route heading with the same service category,
 * account status, meter status and bill status, added up. The customers themselves are never stored (no name, address, phone or meter
 * number is read from the file). Immutable, tied to its batch. Money is cedis (decimal 15,2), consumption has three decimals.
 */
class CommercialBillingDetailRollup extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'batch_id' => 'integer', 'route_id' => 'integer', 'district_id' => 'integer', 'customers' => 'integer',
        'consume' => 'float', 'water_charge' => 'float', 'rural_water' => 'float', 'fire_fighting' => 'float', 'sewage' => 'float', 'service_charge' => 'float',
        'month_total' => 'float', 'previous_balance' => 'float', 'paid_this_month' => 'float', 'prev_month_payment' => 'float', 'offset_payment' => 'float',
        'adjusted' => 'float', 'balance_due' => 'float', 'billed_unpaid_amount' => 'float', 'owing_amount' => 'float', 'credit_amount' => 'float',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CommercialRoute::class, 'route_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
