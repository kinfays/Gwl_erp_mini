<?php

namespace Database\Factories;

use App\Models\District;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Employee::boot() derives location_type from the district name, so use
 * regionalOffice() / headOffice() for Region / HeadOffice staff.
 *
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'district_name' => $this->faker->randomElement([
                'Darkuman', 'Sowutuom', 'Amasaman', 'Kaneshie', 'Odorkor', 'Dansoman', 'Weija', 'Kasoa', 'Madina', 'Adenta', 'Ashaiman', 'Nsawam',
            ]),
        ];
    }

    public function regionalOffice(): static
    {
        return $this->state(fn () => [
            // Closure attribute: evaluated after region_id has been created/resolved.
            'district_name' => fn (array $attributes) => (Region::query()->whereKey($attributes['region_id'])->value('region_name') ?? 'Regional').' Regional Office',
        ]);
    }

    public function headOffice(): static
    {
        return $this->state(fn () => ['district_name' => 'Head Office']);
    }
}
