<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    protected $model = Region::class;

    public function definition(): array
    {
        return [
            'region_name' => $this->faker->randomElement([
                'Accra West', 'Accra East', 'Tema', 'Ashanti North', 'Ashanti South', 'Western', 'Eastern', 'Central', 'Volta', 'Northern',
            ]),
            'hr_email' => $this->faker->unique()->safeEmail(),
        ];
    }
}
