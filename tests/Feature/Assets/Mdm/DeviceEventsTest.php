<?php

namespace Tests\Feature\Assets\Mdm;

use App\Jobs\Assets\Mdm\ProcessAndroidNotification;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Models\MdmEnrollmentToken;
use App\Models\MdmEvent;
use App\Models\MdmPolicy;
use App\Services\Assets\Mdm\CommandService;
use App\Services\Assets\Mdm\DeviceService;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class DeviceEventsTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    private const DEVICE = 'enterprises/LC0test/devices/dev-abc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
    }

    public function test_an_enrollment_event_links_the_device_to_the_asset_named_in_the_token_data(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-DEVICE-1', 'imei' => '356938035643809']);
        $decoy = $this->phone();
        $policy = MdmPolicy::factory()->published()->create();
        $token = MdmEnrollmentToken::factory()->create(['ict_asset_id' => $asset->id, 'mdm_policy_id' => $policy->id]);

        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, [
            'enrollmentTokenData' => json_encode(['asset_id' => $asset->id, 'requested_by' => 1]),
            'enrollmentTokenName' => $token->google_token_name,
            'appliedPolicyName' => $policy->google_policy_name,
        ]));

        $device = MdmDevice::query()->where('google_device_name', self::DEVICE)->firstOrFail();

        $this->assertSame($asset->id, $device->ict_asset_id);
        $this->assertNotSame($decoy->id, $device->ict_asset_id);
        $this->assertFalse($device->needs_review);
        $this->assertNull($device->review_reason);
        $this->assertSame($policy->id, $device->mdm_policy_id);
        $this->assertSame('DEVICE_OWNER', $device->management_mode);
        $this->assertSame('14', $device->android_version);
        $this->assertSame('SN-DEVICE-1', $device->hardware_info['serialNumber']);
        $this->assertSame('356938035643809', $device->hardware_info['imei']);
        $this->assertNotNull($token->fresh()->used_at, 'the QR that was scanned is marked used');
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdm_device_enrolled', 'module' => 'assets', 'target_id' => $device->id]);
    }

    public function test_a_serial_number_mismatch_flags_the_device_for_review_instead_of_trusting_it(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-EXPECTED', 'imei' => '356938035643809']);

        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, [
            'enrollmentTokenData' => json_encode(['asset_id' => $asset->id]),
            'hardwareInfo' => ['serialNumber' => 'SN-SOMEONE-ELSES'],
        ]));

        $device = MdmDevice::query()->firstOrFail();

        $this->assertSame($asset->id, $device->ict_asset_id, 'still recorded against the asset the token named');
        $this->assertTrue($device->needs_review);
        $this->assertStringContainsString('Serial number mismatch', $device->review_reason);
        $this->assertStringContainsString('SN-EXPECTED', $device->review_reason);
        $this->assertStringContainsString('SN-SOMEONE-ELSES', $device->review_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdm_device_enrolled_for_review']);
    }

    public function test_an_imei_mismatch_flags_the_device_for_review(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-DEVICE-1', 'imei' => '111111111111119']);

        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, [
            'enrollmentTokenData' => json_encode(['asset_id' => $asset->id]),
        ]));

        $device = MdmDevice::query()->firstOrFail();

        $this->assertTrue($device->needs_review);
        $this->assertStringContainsString('IMEI mismatch', $device->review_reason);
        $this->assertStringNotContainsString('Serial number mismatch', $device->review_reason);
    }

    public function test_the_14_and_15_digit_forms_of_one_imei_are_the_same_identity(): void
    {
        $asset = $this->phone(null, ['serial_number' => null, 'imei' => '35693803564380']);

        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, [
            'enrollmentTokenData' => json_encode(['asset_id' => $asset->id]),
            'hardwareInfo' => ['serialNumber' => null],
        ]));

        $this->assertFalse(MdmDevice::query()->firstOrFail()->needs_review);
    }

    public function test_a_device_that_reports_no_serial_or_imei_cannot_be_verified_and_is_flagged(): void
    {
        $asset = $this->phone();

        $this->process('ENROLLMENT', [
            'name' => self::DEVICE,
            'enrollmentTokenData' => json_encode(['asset_id' => $asset->id]),
        ]);

        $device = MdmDevice::query()->firstOrFail();

        $this->assertTrue($device->needs_review);
        $this->assertStringContainsString('could not be verified', $device->review_reason);
        $this->assertTrue($this->gateway->called('getDevice'), 'it asks Google once for the full device before giving up');
    }

    public function test_identity_missing_from_the_notification_is_fetched_with_devices_get(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-DEVICE-1', 'imei' => '356938035643809']);
        $this->gateway->devices[self::DEVICE] = $this->deviceResource(self::DEVICE);

        $this->process('ENROLLMENT', [
            'name' => self::DEVICE,
            'enrollmentTokenData' => json_encode(['asset_id' => $asset->id]),
        ]);

        $device = MdmDevice::query()->firstOrFail();

        $this->assertFalse($device->needs_review);
        $this->assertSame('SN-DEVICE-1', $device->hardware_info['serialNumber']);
    }

    public function test_a_device_enrolled_without_an_erp_token_is_recorded_unlinked_and_flagged(): void
    {
        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE));

        $device = MdmDevice::query()->firstOrFail();

        $this->assertNull($device->ict_asset_id);
        $this->assertTrue($device->needs_review);
        $this->assertStringContainsString('did not enroll with an enrollment token', $device->review_reason);
    }

    public function test_a_token_naming_a_missing_or_non_phone_asset_is_flagged_and_left_unlinked(): void
    {
        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, ['enrollmentTokenData' => json_encode(['asset_id' => 999999])]));

        $device = MdmDevice::query()->firstOrFail();

        $this->assertNull($device->ict_asset_id);
        $this->assertTrue($device->needs_review);
        $this->assertStringContainsString('#999999', $device->review_reason);
    }

    public function test_an_asset_that_already_has_a_live_device_is_never_linked_a_second_time(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-DEVICE-1', 'imei' => '356938035643809']);
        $first = MdmDevice::factory()->forAsset($asset)->create();

        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, ['enrollmentTokenData' => json_encode(['asset_id' => $asset->id])]));

        $second = MdmDevice::query()->where('google_device_name', self::DEVICE)->firstOrFail();

        $this->assertSame($asset->id, $first->fresh()->ict_asset_id);
        $this->assertNull($second->ict_asset_id);
        $this->assertTrue($second->needs_review);
        $this->assertStringContainsString('already linked', $second->review_reason);
    }

    public function test_re_enrolling_a_decommissioned_phone_reuses_its_row(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-DEVICE-1', 'imei' => '356938035643809']);
        $old = MdmDevice::factory()->forAsset($asset)->create(['state' => 'DELETED', 'is_lost' => false]);

        $this->process('ENROLLMENT', $this->deviceResource(self::DEVICE, ['enrollmentTokenData' => json_encode(['asset_id' => $asset->id])]));

        $this->assertSame(1, MdmDevice::query()->count());
        $device = $old->fresh();
        $this->assertSame(self::DEVICE, $device->google_device_name);
        $this->assertSame('ACTIVE', $device->state);
        $this->assertFalse($device->needs_review);
    }

    public function test_a_status_report_updates_compliance_and_the_app_report(): void
    {
        $device = MdmDevice::factory()->create(['google_device_name' => self::DEVICE, 'policy_compliant' => true, 'non_compliance' => []]);

        $this->process('STATUS_REPORT', $this->deviceResource(self::DEVICE, [
            'policyCompliant' => false,
            'nonComplianceDetails' => [['settingName' => 'applications', 'nonComplianceReason' => 'APP_NOT_INSTALLED', 'packageName' => 'com.whatsapp.w4b']],
            'softwareInfo' => ['androidVersion' => '15', 'securityPatchLevel' => '2026-09-05'],
            'applicationReports' => [
                ['packageName' => 'com.whatsapp.w4b', 'displayName' => 'WhatsApp Business', 'versionName' => '2.24', 'state' => 'INSTALLED', 'events' => ['x' => 1]],
                ['packageName' => 'com.android.chrome', 'displayName' => 'Chrome', 'versionName' => '128', 'state' => 'INSTALLED'],
            ],
        ]));

        $device->refresh();

        $this->assertFalse($device->policy_compliant);
        $this->assertSame('APP_NOT_INSTALLED', $device->non_compliance[0]['nonComplianceReason']);
        $this->assertSame('15', $device->android_version);
        $this->assertSame('2026-09-05', $device->security_patch_level);
        $this->assertCount(2, $device->application_reports);
        $this->assertSame('Chrome', $device->application_reports[0]['displayName'], 'sorted by display name');
        $this->assertArrayNotHasKey('events', $device->application_reports[1], 'only the fields the screen needs are kept');
        $this->assertNotNull($device->last_status_report_at);
    }

    public function test_a_later_compliant_report_clears_the_reasons(): void
    {
        $device = MdmDevice::factory()->nonCompliant()->create(['google_device_name' => self::DEVICE]);

        $this->process('STATUS_REPORT', $this->deviceResource(self::DEVICE, ['policyCompliant' => true]));

        $this->assertTrue($device->fresh()->policy_compliant);
        $this->assertSame([], $device->fresh()->non_compliance);
    }

    public function test_a_status_report_reporting_the_lost_state_marks_the_device_lost(): void
    {
        $device = MdmDevice::factory()->create(['google_device_name' => self::DEVICE]);

        $this->process('STATUS_REPORT', $this->deviceResource(self::DEVICE, ['state' => 'LOST']));

        $this->assertTrue($device->fresh()->is_lost);
    }

    public function test_an_ordinary_status_report_does_not_end_lost_mode(): void
    {
        $device = MdmDevice::factory()->lost()->create(['google_device_name' => self::DEVICE]);

        $this->process('STATUS_REPORT', $this->deviceResource(self::DEVICE, ['state' => 'ACTIVE']));

        $this->assertTrue($device->fresh()->is_lost, 'only an acknowledged STOP_LOST_MODE ends it');
    }

    public function test_a_status_report_for_an_unknown_device_is_treated_as_an_enrollment(): void
    {
        $asset = $this->phone(null, ['serial_number' => 'SN-DEVICE-1', 'imei' => '356938035643809']);

        $this->process('STATUS_REPORT', $this->deviceResource(self::DEVICE, ['enrollmentTokenData' => json_encode(['asset_id' => $asset->id])]));

        $this->assertSame($asset->id, MdmDevice::query()->where('google_device_name', self::DEVICE)->firstOrFail()->ict_asset_id);
    }

    public function test_a_command_event_acknowledges_the_matching_command_and_applies_lost_mode(): void
    {
        $device = MdmDevice::factory()->create(['google_device_name' => self::DEVICE]);
        $command = MdmDeviceCommand::factory()->sent(self::DEVICE.'/operations/77')->create(['mdm_device_id' => $device->id, 'type' => 'START_LOST_MODE']);

        $this->process('COMMAND', ['name' => self::DEVICE.'/operations/77', 'done' => true]);

        $this->assertSame(MdmDeviceCommand::STATUS_ACKNOWLEDGED, $command->fresh()->status);
        $this->assertNotNull($command->fresh()->acknowledged_at);
        $this->assertTrue($device->fresh()->is_lost);
    }

    public function test_a_command_event_with_an_error_fails_the_command(): void
    {
        $device = MdmDevice::factory()->create(['google_device_name' => self::DEVICE]);
        $command = MdmDeviceCommand::factory()->sent(self::DEVICE.'/operations/78')->create(['mdm_device_id' => $device->id, 'type' => 'LOCK']);

        $this->process('COMMAND', ['name' => self::DEVICE.'/operations/78', 'done' => true, 'error' => ['message' => 'Device unreachable']]);

        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $command->fresh()->status);
        $this->assertSame('Device unreachable', $command->fresh()->error);
    }

    public function test_a_command_event_for_an_operation_the_erp_never_issued_is_ignored(): void
    {
        $event = $this->process('COMMAND', ['name' => self::DEVICE.'/operations/999', 'done' => true]);

        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->error);
    }

    public function test_a_stop_lost_mode_acknowledgement_clears_the_lost_flag(): void
    {
        $device = MdmDevice::factory()->lost()->create(['google_device_name' => self::DEVICE]);
        MdmDeviceCommand::factory()->sent(self::DEVICE.'/operations/79')->create(['mdm_device_id' => $device->id, 'type' => 'STOP_LOST_MODE']);

        $this->process('COMMAND', ['name' => self::DEVICE.'/operations/79', 'done' => true]);

        $this->assertFalse($device->fresh()->is_lost);
        $this->assertNull($device->fresh()->lost_at);
    }

    public function test_usage_logs_are_stored_and_marked_processed_without_touching_devices(): void
    {
        $event = $this->process('USAGE_LOGS', ['name' => self::DEVICE, 'usageLogs' => []]);

        $this->assertNotNull($event->processed_at);
        $this->assertSame(0, MdmDevice::query()->count());
    }

    public function test_processing_the_same_event_twice_only_acts_once(): void
    {
        $device = MdmDevice::factory()->create(['google_device_name' => self::DEVICE, 'android_version' => '13']);
        $event = $this->store('STATUS_REPORT', $this->deviceResource(self::DEVICE, ['softwareInfo' => ['androidVersion' => '14']]));

        (new ProcessAndroidNotification($event->id))->handle(app(DeviceService::class), app(CommandService::class));
        $device->update(['android_version' => 'changed-by-hand']);
        (new ProcessAndroidNotification($event->id))->handle(app(DeviceService::class), app(CommandService::class));

        $this->assertSame('changed-by-hand', $device->fresh()->android_version, 'the second run must be a no-op');
    }

    public function test_a_failing_event_records_its_error_and_stays_unprocessed(): void
    {
        $event = $this->store('STATUS_REPORT', ['no' => 'device name here']);

        try {
            (new ProcessAndroidNotification($event->id))->handle(app(DeviceService::class), app(CommandService::class));
            $this->fail('Expected the job to throw.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no device name', $e->getMessage());
        }

        $event->refresh();
        $this->assertNull($event->processed_at);
        $this->assertStringContainsString('no device name', $event->error);
    }

    public function test_the_nightly_resync_creates_missing_devices_refreshes_known_ones_and_marks_vanished_ones_deleted(): void
    {
        $known = MdmDevice::factory()->create(['google_device_name' => 'enterprises/LC0test/devices/known', 'android_version' => '13']);
        $vanished = MdmDevice::factory()->create(['google_device_name' => 'enterprises/LC0test/devices/vanished']);
        $this->gateway->devices = [
            'enterprises/LC0test/devices/known' => $this->deviceResource('enterprises/LC0test/devices/known', ['softwareInfo' => ['androidVersion' => '15']]),
            'enterprises/LC0test/devices/new' => $this->deviceResource('enterprises/LC0test/devices/new'),
        ];

        $this->artisan('mdm:sync-devices')->expectsOutputToContain('2 device(s) seen, 1 new, 1 marked deleted')->assertExitCode(0);

        $this->assertSame('15', $known->fresh()->android_version);
        $this->assertSame('DELETED', $vanished->fresh()->state);
        $this->assertNotNull(MdmDevice::query()->where('google_device_name', 'enterprises/LC0test/devices/new')->first());
    }

    public function test_an_empty_google_list_never_mass_deletes_local_devices(): void
    {
        $device = MdmDevice::factory()->create();
        $this->gateway->devices = [];

        $this->artisan('mdm:sync-devices')->assertExitCode(0);

        $this->assertNotSame('DELETED', $device->fresh()->state);
    }

    public function test_sync_from_google_marks_a_device_deleted_on_a_404(): void
    {
        $device = MdmDevice::factory()->create();
        $this->gateway->failures['getDevice'] = new MdmGatewayException('not found', 404);

        app(DeviceService::class)->syncFromGoogle($device);

        $this->assertSame('DELETED', $device->fresh()->state);
    }

    public function test_the_resync_re_checks_commands_that_were_sent_but_never_reported_back(): void
    {
        $device = MdmDevice::factory()->create();
        $command = MdmDeviceCommand::factory()->sent(self::DEVICE.'/operations/5')->create(['mdm_device_id' => $device->id, 'type' => 'LOCK', 'sent_at' => now()->subHour()]);
        $this->gateway->operations[self::DEVICE.'/operations/5'] = ['name' => self::DEVICE.'/operations/5', 'done' => true];
        $this->gateway->devices = [$device->google_device_name => $this->deviceResource($device->google_device_name)];

        $this->artisan('mdm:sync-devices')->assertExitCode(0);

        $this->assertSame(MdmDeviceCommand::STATUS_ACKNOWLEDGED, $command->fresh()->status);
    }

    /** Store an event and run the job for it, exactly as the queue worker would. */
    private function process(string $type, array $payload): MdmEvent
    {
        $event = $this->store($type, $payload);

        (new ProcessAndroidNotification($event->id))->handle(app(DeviceService::class), app(CommandService::class));

        return $event->fresh();
    }

    private function store(string $type, array $payload): MdmEvent
    {
        return MdmEvent::factory()->create([
            'notification_type' => $type,
            'google_device_name' => $payload['name'] ?? null,
            'payload' => $payload,
        ]);
    }
}
