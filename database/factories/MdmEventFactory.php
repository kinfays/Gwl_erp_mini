<?php

namespace Database\Factories;

use App\Models\MdmEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdmEvent>
 */
class MdmEventFactory extends Factory
{
    protected $model = MdmEvent::class;

    public function definition(): array
    {
        return [
            'pubsub_message_id' => (string) $this->faker->unique()->numerify('###############'),
            'delivery_mode' => MdmEvent::MODE_PULL,
            'notification_type' => 'STATUS_REPORT',
            'google_device_name' => 'enterprises/LC0test/devices/'.$this->faker->bothify('dev-########'),
            'payload' => [],
            'received_at' => now(),
            'processed_at' => null,
            'error' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['processed_at' => now()]);
    }

    public function failed(string $error = 'Boom'): static
    {
        return $this->state(fn () => ['error' => $error]);
    }
}
