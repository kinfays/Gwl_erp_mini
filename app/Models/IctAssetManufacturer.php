<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IctAssetManufacturer extends Model
{
    use HasFactory;

    protected $table = 'ict_asset_manufacturers';

    protected $fillable = [
        'name',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function models(): HasMany
    {
        return $this->hasMany(IctAssetModel::class, 'ict_asset_manufacturer_id');
    }
}
