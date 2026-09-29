<?php

namespace Database\Factories;

use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdmPolicyApp>
 */
class MdmPolicyAppFactory extends Factory
{
    protected $model = MdmPolicyApp::class;

    public function definition(): array
    {
        return [
            'mdm_policy_id' => MdmPolicy::factory(),
            'package_name' => 'com.example.'.$this->faker->unique()->lexify('app????'),
            'app_name' => $this->faker->words(2, true),
            'install_type' => MdmPolicyApp::INSTALL_TYPE_FORCE_INSTALLED,
            'default_permission_policy' => null,
            'is_enabled' => true,
        ];
    }

    public function available(): static
    {
        return $this->state(fn () => ['install_type' => MdmPolicyApp::INSTALL_TYPE_AVAILABLE]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
