<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A top-level payment voucher category. Shared company-wide (not location-scoped), never deleted, only deactivated, and
 * changed only through PvCatalogService.
 */
class FinPvCategory extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public function types(): HasMany
    {
        return $this->hasMany(FinPvType::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('fin_pv_categories.is_active', true);
    }
}
