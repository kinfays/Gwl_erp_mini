<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'holiday_name' => $this->faker->randomElement([
                'Constitution Day', 'Independence Day', 'May Day', 'Africa Day', 'Founders Day', 'Kwame Nkrumah Day', 'Farmers Day',
            ]),
            'holiday_date' => $this->faker->dateTimeThisYear()->format('Y-m-d'),
        ];
    }
}
