<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment voucher type; belongs to ONE category. See FinPvCategory. */
class FinPvType extends Model
{
    protected $fillable = ['category_id', 'name', 'unit', 'sort_order', 'is_active'];

    protected $casts = ['category_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinPvCategory::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('fin_pv_types.is_active', true);
    }
}
