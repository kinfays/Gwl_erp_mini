<?php

namespace Database\Factories;

use App\Models\IctAssetManufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IctAssetManufacturer>
 */
class IctAssetManufacturerFactory extends Factory
{
    protected $model = IctAssetManufacturer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'is_active' => true,
            'notes' => null,
        ];
    }
}
