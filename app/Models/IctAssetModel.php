<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IctAssetModel extends Model
{
    use HasFactory;

    /**
     * Folder on the "public" disk (storage/app/public) holding model images;
     * served via the public/storage link created by `php artisan storage:link`.
     */
    public const IMAGE_DIRECTORY = 'ict-asset-models';

    protected $table = 'ict_asset_models';

    protected $fillable = [
        'name',
        'category',
        'ict_asset_manufacturer_id',
        'image_path',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'ict_asset_manufacturer_id' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(IctAsset::class, 'ict_asset_model_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(IctAssetManufacturer::class, 'ict_asset_manufacturer_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
