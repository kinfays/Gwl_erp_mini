<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's Annual leave entitlement for one year and how it is made up (gross - compulsory = net). The year's
 * Annual leave_balances.entitle_days comes from net_days; days taken are counted on the balance, not here.
 */
class LeaveEntitlement extends Model
{
    protected $fillable = [
        'employee_id',
        'year',
        'grade_snapshot',
        'tenure_years',
        'gross_days',
        'compulsory_days',
        'net_days',
    ];

    protected $casts = [
        'year' => 'integer',
        'tenure_years' => 'integer',
        'gross_days' => 'integer',
        'compulsory_days' => 'integer',
        'net_days' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
