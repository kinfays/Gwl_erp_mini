<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompulsoryLeaveDeduction extends Model
{
    protected $fillable = [
        'year',
        'start_date',
        'end_date',
        'deduction_days',
        'applied_by_id',
        'applies_to_categories',
        'excludes_location_type',
        'notes',
        'applied_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'applies_to_categories' => 'array',
        'applied_at' => 'datetime',
    ];

    public function appliedBy()
    {
        return $this->belongsTo(Employee::class, 'applied_by_id');
    }
}
