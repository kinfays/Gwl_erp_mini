<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The domestic (category 611) split of one Detailed Billing Report batch at 5 thousand litres, per district (Phase 8h): billed customers, their
 * consumption (in the unit of the billing summary) and month total. The only thing the derived billing summary's band table is built from.
 */
class CommercialBillingDetailDomesticBand extends Model
{
    /** The category the summary's band table is about. */
    public const CATEGORY = '611';

    /** The two bands and the limit between them (thousand litres), as the summary prints them. */
    public const UNDER = '<=5';

    public const OVER = '>5';

    public const LIMIT = 5;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['batch_id' => 'integer', 'district_id' => 'integer', 'customers' => 'integer', 'consume' => 'float', 'amount' => 'float'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }
}
