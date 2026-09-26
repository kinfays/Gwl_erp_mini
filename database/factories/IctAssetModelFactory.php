<?php

namespace Database\Factories;

use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IctAssetModel>
 */
class IctAssetModelFactory extends Factory
{
    protected $model = IctAssetModel::class;

    public function definition(): array
    {
        return [
            'name' => strtoupper($this->faker->unique()->bothify('??-####')),
            'category' => $this->faker->randomElement(array_keys(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_ASSET])),
            'ict_asset_manufacturer_id' => IctAssetManufacturer::factory(),
            'image_path' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
