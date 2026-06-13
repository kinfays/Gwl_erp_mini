<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignmentHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleAssignmentHistory>
 */
class VehicleAssignmentHistoryFactory extends Factory
{
    protected $model = VehicleAssignmentHistory::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'user_id' => User::factory(),
            'driver_id' => null,
            'assigned_by' => User::factory(),
            'assigned_at' => now()->subDays($this->faker->numberBetween(1, 90)),
            'unassigned_at' => null,
            'notes' => $this->faker->sentence(),
        ];
    }
}
