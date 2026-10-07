<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When the "upload overdue" reminder last went out for a region and report type. */
class CommercialReminderState extends Model
{
    protected $fillable = ['region_id', 'report_type', 'last_reminded_at', 'last_batch_id'];

    protected $casts = [
        'region_id' => 'integer',
        'last_batch_id' => 'integer',
        'last_reminded_at' => 'datetime',
    ];
}
