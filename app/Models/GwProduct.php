<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GwProduct extends Model
{
    public const BOTTLE = 'bottle';

    public const SACHET = 'sachet';

    protected $fillable = ['code', 'name', 'units_per_pack', 'is_active'];

    protected $casts = [
        'units_per_pack' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
