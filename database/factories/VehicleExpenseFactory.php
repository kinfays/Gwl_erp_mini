<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleExpense>
 */
class VehicleExpenseFactory extends Factory
{
    protected $model = VehicleExpense::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'expense_type' => $this->faker->randomElement(VehicleExpense::TYPES),
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'currency' => 'GHS',
            'description' => $this->faker->sentence(6),
            'expense_date' => now()->subDays($this->faker->numberBetween(0, 90))->toDateString(),
            'recorded_by' => User::factory(),
        ];
    }
}
