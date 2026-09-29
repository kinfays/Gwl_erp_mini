<?php

namespace Database\Factories;

use App\Models\IctAsset;
use App\Models\MdmEnrollmentToken;
use App\Models\MdmPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdmEnrollmentToken>
 */
class MdmEnrollmentTokenFactory extends Factory
{
    protected $model = MdmEnrollmentToken::class;

    public function definition(): array
    {
        return [
            'ict_asset_id' => fn () => IctAsset::factory()->phone()->create(['asset_type' => 'Ph'])->id,
            'mdm_policy_id' => MdmPolicy::factory(),
            'google_token_name' => 'enterprises/LC0test/enrollmentTokens/'.$this->faker->unique()->bothify('tok-########'),
            'expires_at' => now()->addHour(),
            'used_at' => null,
            'created_by' => null,
        ];
    }

    public function used(): static
    {
        return $this->state(fn () => ['used_at' => now()->subMinutes(10)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinutes(5)]);
    }
}
