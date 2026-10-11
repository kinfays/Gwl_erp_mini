<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When an item was last reminded about at a location (GwaterReminderService). */
class GwReminderState extends Model
{
    public const LOW_STOCK = 'low_stock';

    public const PAYMENT_OVERDUE = 'payment_overdue';

    protected $fillable = ['region_id', 'district_id', 'kind', 'subject_id', 'last_reminded_at'];

    protected $casts = ['region_id' => 'integer', 'district_id' => 'integer', 'subject_id' => 'integer', 'last_reminded_at' => 'datetime'];
}
