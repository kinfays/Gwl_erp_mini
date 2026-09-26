<?php

namespace Database\Factories;

use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IctAssetMaintenance>
 */
class IctAssetMaintenanceFactory extends Factory
{
    protected $model = IctAssetMaintenance::class;

    public function definition(): array
    {
        return [
            'ict_asset_id' => IctAsset::factory(),
            'maintenance_type' => $this->faker->randomElement(['Hardware Repair', 'Toner Replacement', 'OS Reinstallation', 'Preventive Maintenance']),
            'status' => 'Open',
            'completion_date' => null,
            'technician' => $this->faker->randomElement(EmployeeFactory::MALE_FIRST_NAMES).' '.$this->faker->randomElement(EmployeeFactory::SURNAMES),
            'location' => 'ICT workshop',
            'notes' => null,
            'performed_by_user_id' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'Completed',
            'completion_date' => now()->subDays($this->faker->numberBetween(1, 60))->toDateString(),
        ]);
    }
}
