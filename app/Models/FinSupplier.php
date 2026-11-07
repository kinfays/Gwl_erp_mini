<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One company-wide supplier list (phase 2 adds the screens, the service and the import). A voucher freezes the supplier id.
 * Changed only through SupplierService; a blank TIN, phone or code is NULL, never an empty string.
 */
class FinSupplier extends Model
{
    protected $fillable = ['name', 'name_key', 'code', 'tin', 'phone', 'is_active', 'created_by', 'first_region_id', 'first_district_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('fin_suppliers.is_active', true);
    }
}
