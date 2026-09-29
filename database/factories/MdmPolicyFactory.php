<?php

namespace Database\Factories;

use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdmPolicy>
 */
class MdmPolicyFactory extends Factory
{
    protected $model = MdmPolicy::class;

    public function definition(): array
    {
        return [
            'name' => 'Policy '.$this->faker->unique()->bothify('??-####'),
            'description' => $this->faker->sentence(),
            'play_store_mode' => 'WHITELIST',
            'install_apps_disabled' => true,
            'uninstall_apps_disabled' => true,
            'factory_reset_disabled' => true,
            'add_user_disabled' => true,
            'screen_capture_disabled' => false,
            'camera_access' => 'CAMERA_ACCESS_USER_CHOICE',
            'usb_data_access' => 'DISALLOW_USB_FILE_TRANSFER',
            'untrusted_apps_policy' => 'DISALLOW_INSTALL',
            'developer_settings' => 'DEVELOPER_SETTINGS_DISABLED',
            'password_min_length' => 6,
            'password_quality' => 'NUMERIC',
            'system_update_type' => 'AUTOMATIC',
            'frp_admin_emails' => ['it.admin@gwcl.example'],
        ];
    }

    /** A policy that has been accepted by Google, so tokens can be minted against it. */
    public function published(): static
    {
        return $this->afterCreating(function (MdmPolicy $policy) {
            $policy->update([
                'google_policy_name' => 'enterprises/LC0test/policies/gwl-'.$policy->id,
                'published_at' => now(),
                'version' => 1,
            ]);
        });
    }

    /** One force-installed app, the minimum a fully managed policy needs. */
    public function withForcedApp(string $package = 'com.example.forced'): static
    {
        return $this->afterCreating(function (MdmPolicy $policy) use ($package) {
            MdmPolicyApp::factory()->for($policy, 'policy')->create([
                'package_name' => $package,
                'install_type' => MdmPolicyApp::INSTALL_TYPE_FORCE_INSTALLED,
            ]);
        });
    }
}
