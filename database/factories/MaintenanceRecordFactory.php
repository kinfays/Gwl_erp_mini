<?php

namespace Database\Factories;

use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRecord>
 */
class MaintenanceRecordFactory extends Factory
{
    protected $model = MaintenanceRecord::class;

    public function definition(): array
    {
        $mileage = $this->faker->numberBetween(10000, 140000);

        return [
            'vehicle_id' => Vehicle::factory(),
            'maintenance_type' => $this->faker->randomElement(['routine_service', 'repair', 'inspection']),
            'description' => $this->faker->sentence(8),
            'performed_by' => $this->faker->company(),
            'mileage_at_service' => $mileage,
            'cost' => $this->faker->randomFloat(2, 350, 8500),
            'service_date' => now()->subDays($this->faker->numberBetween(0, 90))->toDateString(),
            'next_service_date' => now()->addMonths(3)->toDateString(),
            'next_service_mileage' => $mileage + 5000,
        ];
    }
}
