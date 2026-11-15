<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the DISTRICT DISAGREEMENT MATRIX of a Detailed Billing Report (Phase 8e step 0.2, design 8.42): how many linked accounts the
 * billing report puts in `billing_district_id` (the district of the account's billing route) and the customer list puts in
 * `list_district_id` (customers.district_id). The diagonal is agreement. Counts only. The evidence for the Q12 question "which system owns a
 * route's district?".
 */
class CommercialBillingDetailDistrictCell extends Model
{
    protected $table = 'commercial_billing_detail_district_matrix';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['batch_id' => 'integer', 'billing_district_id' => 'integer', 'list_district_id' => 'integer', 'customers' => 'integer'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }
}
