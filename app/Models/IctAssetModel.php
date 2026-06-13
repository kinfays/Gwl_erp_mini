<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IctAssetModel extends Model
{
    use HasFactory;

    protected $table = 'ict_asset_models';

    protected $fillable = [
        'name',
        'category',
        'manufacturer',
        'image_path',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(IctAsset::class, 'ict_asset_model_id');
    }
}

