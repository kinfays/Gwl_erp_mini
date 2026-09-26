<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Writes the row directly; go through AssetRecordService::save() when a test
 * needs its audit entry, IP-range check or previous-assignee tracking.
 *
 * @extends Factory<IctAsset>
 */
class IctAssetFactory extends Factory
{
    protected $model = IctAsset::class;

    public function definition(): array
    {
        return [
            'asset_name' => $this->faker->randomElement(['Laptop', 'Desktop', 'Printer']).' – '.$this->faker->randomElement(['Accounts', 'Front Desk', 'Secretariat']),
            'serial_number' => strtoupper($this->faker->unique()->bothify('??########')),
            'asset_type' => $this->faker->randomElement(array_keys(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_ASSET])),
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'ict_asset_model_id' => fn (array $attributes) => IctAssetModel::factory()->create(['category' => $attributes['asset_type']])->id,
            'status' => IctAsset::STATUS_ACTIVE,
            'assigned_to_employee_id' => null,
            'department_id' => null,
            'region_id' => null,
            'district_id' => null,
            'purchased_at' => $this->faker->dateTimeBetween('-6 years', '-1 month')->format('Y-m-d'),
        ];
    }

    public function assignedTo(Employee $employee): static
    {
        return $this->state(fn () => [
            'assigned_to_employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'region_id' => $employee->region_id,
            'district_id' => $employee->district_id,
        ]);
    }

    public function phone(): static
    {
        return $this->state(fn () => [
            'device_category' => IctAsset::DEVICE_CATEGORY_PHONE,
            'asset_type' => $this->faker->randomElement(array_keys(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_PHONE])),
            'imei' => $this->faker->numerify('35#############'),
            'device_phone_number' => '024'.$this->faker->numerify('#######'),
        ]);
    }

    public function network(): static
    {
        return $this->state(fn () => [
            'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK,
            'asset_type' => $this->faker->randomElement(array_keys(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_NETWORK])),
            'device_ip' => $this->faker->localIpv4(),
            'actual_location' => 'Server room',
        ]);
    }
}
