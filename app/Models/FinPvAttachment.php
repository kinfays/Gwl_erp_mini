<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A scan or supporting document on a voucher. The file is on the private disk under a random name and has no URL. */
class FinPvAttachment extends Model
{
    protected $fillable = ['pv_id', 'path', 'display_name', 'mime', 'size', 'uploaded_by'];

    protected $casts = ['pv_id' => 'integer', 'size' => 'integer'];

    public function pv(): BelongsTo
    {
        return $this->belongsTo(FinPaymentVoucher::class, 'pv_id');
    }
}
