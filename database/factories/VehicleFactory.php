<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        $mileage = $this->faker->numberBetween(12000, 180000);

        return [
            'type' => $this->faker->randomElement(Vehicle::TYPES),
            'brand' => $this->faker->randomElement(['Toyota', 'Nissan', 'Ford', 'Hyundai', 'Kia']),
            'model' => $this->faker->randomElement(['Corolla', 'Hilux', 'Urvan', 'Ranger', 'Tucson']),
            'color' => $this->faker->safeColorName(),
            'number_plate' => strtoupper($this->faker->bothify('GV-####-##')),
            'year_purchased' => $this->faker->numberBetween(2017, (int) now()->year),
            'is_pool_car' => true,
            'assigned_user_id' => null,
            'department_id' => Department::query()->first()?->id,
            'driver_type' => Vehicle::DRIVER_SELF_DRIVE,
            'assigned_driver_id' => null,
            'current_mileage' => $mileage,
            'maintenance_interval_km' => 5000,
            'insurance_expiry_date' => now()->addDays($this->faker->numberBetween(20, 180))->toDateString(),
            'road_worthiness_expiry_date' => now()->addDays($this->faker->numberBetween(15, 160))->toDateString(),
            'status' => $this->faker->randomElement(Vehicle::STATUSES),
        ];
    }

    public function assigned(?User $user = null, ?User $driver = null): static
    {
        return $this->state(fn () => [
            'is_pool_car' => false,
            'assigned_user_id' => $user?->id ?? User::factory(),
            'driver_type' => $driver ? Vehicle::DRIVER_ASSIGNED : Vehicle::DRIVER_SELF_DRIVE,
            'assigned_driver_id' => $driver?->id,
        ]);
    }
}
