<?php

namespace Tests\Feature\Assets\Mdm;

use App\Models\AuditLog;
use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use App\Services\Assets\Mdm\PolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class PolicyServiceTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
    }

    public function test_a_locked_down_policy_translates_to_the_expected_amapi_payload(): void
    {
        $policy = MdmPolicy::factory()->withForcedApp('com.whatsapp.w4b')->create([
            'frp_admin_emails' => ['Security@GWCL.example', ' it@gwcl.example '],
            'camera_access' => 'CAMERA_ACCESS_DISABLED',
            'usb_data_access' => 'DISALLOW_USB_DATA_TRANSFER',
            'screen_capture_disabled' => true,
            'password_min_length' => 8,
            'password_quality' => 'ALPHANUMERIC',
        ]);
        MdmPolicyApp::factory()->for($policy, 'policy')->create(['package_name' => 'com.example.optional', 'install_type' => 'AVAILABLE', 'default_permission_policy' => 'DENY']);
        MdmPolicyApp::factory()->for($policy, 'policy')->disabled()->create(['package_name' => 'com.example.off']);

        $payload = app(PolicyService::class)->toAmapiPayload($policy->fresh());

        $this->assertSame('WHITELIST', $payload['playStoreMode']);
        $this->assertTrue($payload['installAppsDisabled']);
        $this->assertTrue($payload['uninstallAppsDisabled']);
        $this->assertTrue($payload['factoryResetDisabled']);
        $this->assertTrue($payload['addUserDisabled']);
        $this->assertTrue($payload['screenCaptureDisabled']);
        $this->assertSame(['security@gwcl.example', 'it@gwcl.example'], $payload['frpAdminEmails']);
        $this->assertSame('CAMERA_ACCESS_DISABLED', $payload['cameraAccess']);
        $this->assertSame(['usbDataAccess' => 'DISALLOW_USB_DATA_TRANSFER'], $payload['deviceConnectivityManagement']);
        $this->assertSame(
            ['untrustedAppsPolicy' => 'DISALLOW_INSTALL', 'developerSettings' => 'DEVELOPER_SETTINGS_DISABLED'],
            $payload['advancedSecurityOverrides']
        );
        $this->assertSame([['passwordScope' => 'SCOPE_DEVICE', 'passwordQuality' => 'ALPHANUMERIC', 'passwordMinimumLength' => 8]], $payload['passwordPolicies']);
        $this->assertSame(['type' => 'AUTOMATIC'], $payload['systemUpdate']);
        $this->assertSame([
            'applicationReportsEnabled' => true,
            'deviceSettingsEnabled' => true,
            'softwareInfoEnabled' => true,
            'hardwareStatusEnabled' => true,
            'networkInfoEnabled' => true,
        ], $payload['statusReportingSettings']);

        // Only enabled apps, sorted by package, optional keys dropped when empty.
        $this->assertSame([
            ['packageName' => 'com.example.optional', 'installType' => 'AVAILABLE', 'defaultPermissionPolicy' => 'DENY'],
            ['packageName' => 'com.whatsapp.w4b', 'installType' => 'FORCE_INSTALLED'],
        ], $payload['applications']);
    }

    public function test_the_payload_never_sets_the_fields_the_rollout_deliberately_leaves_alone(): void
    {
        $policy = MdmPolicy::factory()->withForcedApp()->create();

        $payload = app(PolicyService::class)->toAmapiPayload($policy->fresh());

        $this->assertArrayNotHasKey('leaveAllSystemAppsEnabled', $payload);
        $this->assertArrayNotHasKey('passwordRequirements', $payload, 'deprecated in favour of passwordPolicies');
        $this->assertArrayNotHasKey('cameraDisabled', $payload, 'deprecated in favour of cameraAccess');
        $this->assertArrayNotHasKey('usbFileTransferDisabled', $payload, 'deprecated in favour of deviceConnectivityManagement.usbDataAccess');
        $this->assertStringNotContainsString('maximumFailedPasswordsForWipe', json_encode($payload), 'the ERP never auto-wipes');
    }

    public function test_a_policy_without_password_settings_omits_password_policies_and_a_window_carries_its_times(): void
    {
        $policy = MdmPolicy::factory()->withForcedApp()->create([
            'password_min_length' => null,
            'password_quality' => null,
            'system_update_type' => 'WINDOWED',
            'system_update_start_minutes' => 120,
            'system_update_end_minutes' => 240,
        ]);

        $payload = app(PolicyService::class)->toAmapiPayload($policy->fresh());

        $this->assertArrayNotHasKey('passwordPolicies', $payload);
        $this->assertSame(['type' => 'WINDOWED', 'startMinutes' => 120, 'endMinutes' => 240], $payload['systemUpdate']);
    }

    public function test_publish_sends_the_payload_with_a_mask_covering_every_managed_field_and_records_the_result(): void
    {
        $actor = $this->admin();
        $policy = MdmPolicy::factory()->withForcedApp()->create();

        $published = app(PolicyService::class)->publish($policy, $actor);

        $call = $this->gateway->calls('patchPolicy');
        $this->assertCount(1, $call);
        [$name, $payload, $mask] = $call[0];

        $this->assertSame('enterprises/LC0test/policies/gwl-'.$policy->id, $name);
        $this->assertSame(implode(',', PolicyService::MANAGED_FIELDS), $mask);
        $this->assertSame('WHITELIST', $payload['playStoreMode']);

        $this->assertSame($name, $published->google_policy_name);
        $this->assertSame(1, $published->version);
        $this->assertSame($actor->id, $published->published_by);
        $this->assertNotNull($published->published_at);
        $this->assertEquals($payload, $published->last_published_payload);
    }

    public function test_publish_refuses_a_policy_with_no_force_installed_app_and_never_calls_google(): void
    {
        $policy = MdmPolicy::factory()->create();
        MdmPolicyApp::factory()->for($policy, 'policy')->available()->create();

        try {
            app(PolicyService::class)->publish($policy, $this->admin());
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('apps', $e->errors());
        }

        $this->assertFalse($this->gateway->called('patchPolicy'));
        $this->assertNull($policy->fresh()->google_policy_name);
    }

    public function test_a_disabled_force_installed_app_does_not_count_towards_the_minimum(): void
    {
        $policy = MdmPolicy::factory()->create();
        MdmPolicyApp::factory()->for($policy, 'policy')->disabled()->create(['install_type' => 'FORCE_INSTALLED']);

        $this->expectException(ValidationException::class);

        app(PolicyService::class)->validateForPublish($policy);
    }

    public function test_publish_rejects_invalid_package_names_frp_emails_and_incomplete_settings(): void
    {
        $service = app(PolicyService::class);

        $badPackage = MdmPolicy::factory()->create();
        MdmPolicyApp::factory()->for($badPackage, 'policy')->create(['package_name' => 'not a package']);
        $this->assertArrayHasKey('apps', $this->errorsFor($service, $badPackage));

        $badEmail = MdmPolicy::factory()->withForcedApp()->create(['frp_admin_emails' => ['not-an-email']]);
        $this->assertArrayHasKey('frp_admin_emails', $this->errorsFor($service, $badEmail));

        $lengthWithoutQuality = MdmPolicy::factory()->withForcedApp()->create(['password_min_length' => 8, 'password_quality' => null]);
        $this->assertArrayHasKey('password_quality', $this->errorsFor($service, $lengthWithoutQuality));

        $windowNoTimes = MdmPolicy::factory()->withForcedApp()->create(['system_update_type' => 'WINDOWED']);
        $this->assertArrayHasKey('system_update_start_minutes', $this->errorsFor($service, $windowNoTimes));
    }

    public function test_publishing_writes_an_audit_log_with_the_payload_and_changed_paths(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor);
        $policy = MdmPolicy::factory()->withForcedApp('com.whatsapp.w4b')->create();

        app(PolicyService::class)->publish($policy, $actor);

        $log = AuditLog::query()->where('action', 'mdm_policy_published')->firstOrFail();

        $this->assertSame('assets', $log->module);
        $this->assertSame('mdm_policies', $log->target_type);
        $this->assertSame($policy->id, $log->target_id);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('WHITELIST', $log->new_values['playStoreMode']);
        $this->assertSame(1, $log->metadata['version']);
        $this->assertContains('playStoreMode', $log->metadata['changed_paths']);
    }

    public function test_the_diff_reports_added_changed_and_removed_settings_against_the_last_published_payload(): void
    {
        $service = app(PolicyService::class);
        $actor = $this->admin();
        $policy = MdmPolicy::factory()->withForcedApp('com.example.keep')->create(['frp_admin_emails' => ['a@gwcl.example']]);
        MdmPolicyApp::factory()->for($policy, 'policy')->create(['package_name' => 'com.example.gone']);
        $service->publish($policy, $actor);

        $this->assertSame([], $service->diff($policy->fresh()), 'nothing changed since publish');
        $this->assertFalse($service->hasUnpublishedChanges($policy->fresh()));

        $policy->update(['camera_access' => 'CAMERA_ACCESS_DISABLED', 'frp_admin_emails' => ['a@gwcl.example', 'b@gwcl.example']]);
        $policy->apps()->where('package_name', 'com.example.gone')->delete();
        MdmPolicyApp::factory()->for($policy, 'policy')->create(['package_name' => 'com.example.new']);

        $changes = collect($service->diff($policy->fresh()))->keyBy('path');

        $this->assertSame('changed', $changes['cameraAccess']['type']);
        $this->assertSame('CAMERA_ACCESS_USER_CHOICE', $changes['cameraAccess']['before']);
        $this->assertSame('CAMERA_ACCESS_DISABLED', $changes['cameraAccess']['after']);
        $this->assertSame('changed', $changes['frpAdminEmails']['type']);
        $this->assertSame('a@gwcl.example, b@gwcl.example', $changes['frpAdminEmails']['after']);
        $this->assertSame('added', $changes['applications[com.example.new]']['type']);
        $this->assertSame('removed', $changes['applications[com.example.gone]']['type']);
        $this->assertTrue($service->hasUnpublishedChanges($policy->fresh()));
    }

    public function test_warnings_flag_a_missing_factory_reset_protection_account(): void
    {
        $service = app(PolicyService::class);

        $without = MdmPolicy::factory()->create(['frp_admin_emails' => []]);
        $this->assertTrue(collect($service->warnings($without))->contains(fn ($w) => str_contains($w, 'factory reset protection')));

        $with = MdmPolicy::factory()->create(['frp_admin_emails' => ['it@gwcl.example']]);
        $this->assertSame([], $service->warnings($with));
    }

    /** @return array<string, list<string>> */
    private function errorsFor(PolicyService $service, MdmPolicy $policy): array
    {
        try {
            $service->validateForPublish($policy->fresh());
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
