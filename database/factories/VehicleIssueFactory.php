<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleIssue>
 */
class VehicleIssueFactory extends Factory
{
    protected $model = VehicleIssue::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'reported_by' => User::factory(),
            'issue_types' => [$this->faker->randomElement(VehicleIssue::ISSUE_TYPES)],
            'description' => $this->faker->sentence(10),
            'severity' => $this->faker->randomElement(VehicleIssue::SEVERITIES),
            'status' => $this->faker->randomElement(VehicleIssue::STATUSES),
            'reported_at' => now()->subDays($this->faker->numberBetween(0, 20)),
            'resolved_at' => null,
        ];
    }
}
