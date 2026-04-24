<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type',
        'entitle_days',
        'used_days',
        'remaining_days',
        'carry_over_days',
        'carry_over_expired_date',
        'current_year',
        'district_id',
        'region_id',
    ];

    protected $casts = [
        'carry_over_expired_date' => 'date',
        'current_year' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }
}