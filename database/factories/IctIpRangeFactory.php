<?php

namespace Database\Factories;

use App\Models\IctIpRange;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IctIpRange>
 */
class IctIpRangeFactory extends Factory
{
    protected $model = IctIpRange::class;

    public function definition(): array
    {
        $subnet = '10.'.$this->faker->numberBetween(0, 255).'.'.$this->faker->numberBetween(0, 255);

        return [
            'label' => $this->faker->randomElement(['Office LAN', 'Staff Wi-Fi', 'Server VLAN']),
            'region_id' => Region::factory(),
            'district_id' => null,
            'start_ip' => "{$subnet}.1",
            'end_ip' => "{$subnet}.254",
            'cidr' => "{$subnet}.0/24",
            'notes' => null,
            'is_active' => true,
        ];
    }
}
