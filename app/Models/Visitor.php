<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'visitor_name',
        'phone',
        'staff_id',
        'purpose',
        'signature',
        'checkout_code',
        'check_in_at',
        'check_out_at',
        'checked_out_by',
    ];

    protected $casts = [
        'staff_id' => 'integer',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'staff_id');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('check_in_at', today());
    }

    public function scopeInside($query)
    {
        return $query->whereNull('check_out_at');
    }

    public function scopeCheckedOut($query)
    {
        return $query->whereNotNull('check_out_at');
    }

    public function getStatusAttribute(): string
    {
        return $this->check_out_at ? 'Checked Out' : 'Inside';
    }
}
