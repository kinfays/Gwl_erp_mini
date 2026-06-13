<?php

namespace Database\Factories;

use App\Models\MileageLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MileageLog>
 */
class MileageLogFactory extends Factory
{
    protected $model = MileageLog::class;

    public function definition(): array
    {
        $before = $this->faker->numberBetween(1000, 120000);
        $after = $before + $this->faker->numberBetween(5, 350);

        return [
            'vehicle_id' => Vehicle::factory(),
            'driver_id' => User::factory(),
            'mileage_before' => $before,
            'mileage_after' => $after,
            'trip_date' => now()->subDays($this->faker->numberBetween(0, 30))->toDateString(),
            'trip_purpose' => $this->faker->randomElement(['Field inspection', 'Staff movement', 'Regional meeting']),
            'recorded_at' => now(),
        ];
    }
}
