<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When a waiting voucher was last reminded about, for one kind of wait. Written only by the reminder services (FinanceReminderService, AuditReminderService). */
class FinReminderState extends Model
{
    protected $fillable = ['pv_id', 'kind', 'since', 'last_sent_at'];

    protected $casts = ['since' => 'datetime', 'last_sent_at' => 'datetime'];
}
