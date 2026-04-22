<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'deduction_days' => 'integer',
        'applies_to_categories' => 'array',
        'excludes_location_type' => LocationType::class,
        'applied_at' => 'datetime',
    ];

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'applied_by_id');
    }
}