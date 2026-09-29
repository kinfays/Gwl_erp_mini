<?php

namespace Database\Seeders;

use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use Illuminate\Database\Seeder;

/**
 * The starter "GWL Standard Phone" policy: the locked-down, fully managed baseline (allow-list Play Store, users cannot
 * install or uninstall apps, factory reset and extra users off, untrusted apps and developer settings blocked) with
 * PLACEHOLDER force-installed apps to be edited before the first publish.
 *
 * Idempotent: it only creates the policy (and each app) when missing and never overwrites an edited one, so it is
 * safe to run from DatabaseSeeder, from the migration that ships it, and by hand:
 *   php artisan db:seed --class=MdmStarterPolicySeeder
 *
 * It is not published; factory reset protection accounts are left empty because only you know them (the publish
 * screen warns until they are set).
 */
class MdmStarterPolicySeeder extends Seeder
{
    public const NAME = 'GWL Standard Phone';

    public function run(): void
    {
        $policy = MdmPolicy::query()->firstOrCreate(['name' => self::NAME], [
            'description' => 'Starter policy for company-owned, fully managed phones. Edit the app list, add factory reset protection accounts, then publish.',
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
            'frp_admin_emails' => [],
        ]);

        if (! $policy->wasRecentlyCreated) {
            return;
        }

        $apps = [
            // The example from the brief; replace or extend with the real company app set.
            ['com.whatsapp.w4b', 'WhatsApp Business'],
            ['com.android.chrome', 'Google Chrome (placeholder)'],
        ];

        foreach ($apps as [$package, $label]) {
            MdmPolicyApp::query()->firstOrCreate(
                ['mdm_policy_id' => $policy->id, 'package_name' => $package],
                ['app_name' => $label, 'install_type' => MdmPolicyApp::INSTALL_TYPE_FORCE_INSTALLED, 'is_enabled' => true],
            );
        }
    }
}
