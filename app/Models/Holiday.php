<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'holiday_name',
        'holiday_date',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeUpcoming($query)
    {
        return $query->whereDate('holiday_date', '>=', now()->toDateString())->orderBy('holiday_date');
    }
}