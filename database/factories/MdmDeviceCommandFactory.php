<?php

namespace Database\Factories;

use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdmDeviceCommand>
 */
class MdmDeviceCommandFactory extends Factory
{
    protected $model = MdmDeviceCommand::class;

    public function definition(): array
    {
        return [
            'mdm_device_id' => MdmDevice::factory(),
            'type' => MdmDeviceCommand::TYPE_LOCK,
            'payload' => null,
            'status' => MdmDeviceCommand::STATUS_REQUESTED,
            'google_operation_name' => null,
            'requested_by' => null,
            'requested_at' => now(),
        ];
    }

    public function sent(?string $operation = null): static
    {
        return $this->state(fn () => [
            'status' => MdmDeviceCommand::STATUS_SENT,
            'google_operation_name' => $operation ?? 'enterprises/LC0test/devices/dev-1/operations/'.$this->faker->unique()->numerify('########'),
            'sent_at' => now(),
        ]);
    }

    public function acknowledged(): static
    {
        return $this->sent()->state(fn () => ['status' => MdmDeviceCommand::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()]);
    }

    public function failed(string $error = 'Google rejected the command.'): static
    {
        return $this->state(fn () => ['status' => MdmDeviceCommand::STATUS_FAILED, 'error' => $error]);
    }
}
