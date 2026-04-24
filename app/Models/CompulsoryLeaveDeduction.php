<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompulsoryLeaveDeduction extends Model
{
    protected $fillable = [
        'year',
        'deduction_days',
        'applied_by_id',
        'applies_to_categories',
        'excludes_location_type',
        'notes',
        'applied_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'applies_to_categories' => 'array',
        'applied_at' => 'datetime',
    ];

    public function appliedBy()
    {
        return $this->belongsTo(Employee::class, 'applied_by_id');
    }
}