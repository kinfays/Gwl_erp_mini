<?php

namespace App\Models;

use App\Enums\LeaveType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'staff_id',
        'leave_type',
        'entitle_days',
        'used_days',
        'remaining_days',
        'carry_over_days',
        'carry_over_expired_date',
        'current_year',
        'region_id',
        'district_id',
    ];

    protected $casts = [
        'leave_type' => LeaveType::class,
        'carry_over_expired_date' => 'date',
        'current_year' => 'integer',
    ];

    /* ---------------- Relationships ---------------- */

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'staff_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /* ---------------- Domain Logic ---------------- */

    public function deduct(int $days): void
    {
        $this->used_days += $days;
        $this->remaining_days = max(
            0,
            $this->entitle_days + $this->carry_over_days - $this->used_days
        );

        $this->save();
    }
    
}