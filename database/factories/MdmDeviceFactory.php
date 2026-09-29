<?php

namespace Database\Factories;

use App\Models\IctAsset;
use App\Models\MdmDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdmDevice>
 */
class MdmDeviceFactory extends Factory
{
    protected $model = MdmDevice::class;

    public function definition(): array
    {
        return [
            'ict_asset_id' => fn () => IctAsset::factory()->phone()->create(['asset_type' => 'Ph'])->id,
            'mdm_policy_id' => null,
            'google_device_name' => 'enterprises/LC0test/devices/'.$this->faker->unique()->bothify('dev-########'),
            'management_mode' => 'DEVICE_OWNER',
            'state' => 'ACTIVE',
            'applied_state' => 'ACTIVE',
            'policy_compliant' => true,
            'non_compliance' => [],
            'android_version' => '14',
            'security_patch_level' => '2026-08-05',
            'hardware_info' => ['serialNumber' => strtoupper($this->faker->bothify('SN########')), 'manufacturer' => 'Samsung', 'model' => 'SM-A155F'],
            'application_reports' => [],
            'last_status_report_at' => now()->subHour(),
            'last_synced_at' => now()->subHour(),
            'enrolled_at' => now()->subDays(3),
            'is_lost' => false,
            'needs_review' => false,
        ];
    }

    /** For an asset that already exists (so the test controls region / serial / IMEI). */
    public function forAsset(IctAsset $asset): static
    {
        return $this->state(fn () => ['ict_asset_id' => $asset->id]);
    }

    public function unlinked(): static
    {
        return $this->state(fn () => ['ict_asset_id' => null, 'needs_review' => true, 'review_reason' => 'Enrolled without a token from this ERP.']);
    }

    public function needsReview(string $reason = 'Serial number does not match the asset record.'): static
    {
        return $this->state(fn () => ['needs_review' => true, 'review_reason' => $reason]);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['is_lost' => true, 'lost_at' => now()->subHour()]);
    }

    public function nonCompliant(): static
    {
        return $this->state(fn () => [
            'policy_compliant' => false,
            'non_compliance' => [['settingName' => 'applications', 'nonComplianceReason' => 'APP_NOT_INSTALLED', 'packageName' => 'com.whatsapp.w4b']],
        ]);
    }

    public function stale(): static
    {
        return $this->state(fn () => ['last_status_report_at' => now()->subDays(3)]);
    }
}
