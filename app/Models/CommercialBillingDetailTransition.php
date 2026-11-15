<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many linked accounts of ONE route moved from a (status, category) at the bill to a (status, category) in the customer list as it is
 * NOW (Phase 8e step 0.2, design 8.42). Written once, when a Detailed Billing Report goes live and the customer list is linked; codes and
 * counts only, never an account. `district_id` is the district of the BILLING route. A row where from = to is an account that did not move
 * (it is kept: it is the denominator of "how many changed").
 */
class CommercialBillingDetailTransition extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['batch_id' => 'integer', 'route_id' => 'integer', 'district_id' => 'integer', 'customers' => 'integer'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CommercialRoute::class, 'route_id');
    }
}
