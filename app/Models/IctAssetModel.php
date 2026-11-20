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

    /**
     * Tabs of the Models settings screen: key => [label, asset-type codes (a model's "category")]. The one place the
     * grouping is written. Phones and Network are the whole device category; null means every type (the All tab).
     */
    public static function tabs(): array
    {
        return [
            'all' => ['label' => 'All', 'types' => null],
            'computers' => ['label' => 'Computers', 'types' => ['PC', 'Laptop', 'Server', 'AIO']],
            'printers' => ['label' => 'Printers', 'types' => ['PRT', 'TPR', 'CM', 'PTC']],
            'phones' => ['label' => 'Phones', 'types' => array_keys(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_PHONE])],
            'network' => ['label' => 'Network', 'types' => array_keys(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_NETWORK])],
        ];
    }

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
