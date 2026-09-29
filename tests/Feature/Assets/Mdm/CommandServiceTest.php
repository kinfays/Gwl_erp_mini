<?php

namespace Tests\Feature\Assets\Mdm;

use App\Jobs\Assets\Mdm\SendMdmCommand;
use App\Models\AuditLog;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\Mdm\CommandService;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use App\Services\Assets\Mdm\MdmAccessGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class CommandServiceTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        Queue::fake();
    }

    // ------------------------------------------------------------------ requesting

    public function test_a_command_is_a_local_record_and_a_queued_job_never_a_call_to_google(): void
    {
        [$actor, $device] = $this->actorAndDevice();

        $command = app(CommandService::class)->request($actor, $device, 'LOCK');

        $this->assertSame(MdmDeviceCommand::STATUS_REQUESTED, $command->status);
        $this->assertSame($actor->id, $command->requested_by);
        $this->assertNotNull($command->requested_at);
        Queue::assertPushed(SendMdmCommand::class, fn ($job) => $job->commandId === $command->id);
        $this->assertSame([], $this->gateway->calls, 'requesting must not reach Google');
    }

    public function test_delivery_issues_the_command_and_records_the_operation(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $command = app(CommandService::class)->request($actor, $device, 'REBOOT');

        $this->deliver($command);

        [$name, $body] = $this->gateway->calls('issueCommand')[0];
        $this->assertSame($device->google_device_name, $name);
        $this->assertSame('REBOOT', $body['type']);
        $this->assertSame('3600s', $body['duration']);

        $command->refresh();
        $this->assertSame(MdmDeviceCommand::STATUS_SENT, $command->status);
        $this->assertStringStartsWith($device->google_device_name.'/operations/', $command->google_operation_name);
        $this->assertNotNull($command->sent_at);
        $this->assertNull($command->acknowledged_at);
    }

    public function test_delivery_is_idempotent_so_a_retried_job_cannot_send_twice(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $command = app(CommandService::class)->request($actor, $device, 'LOCK');

        $this->deliver($command);
        $this->deliver($command);

        $this->assertCount(1, $this->gateway->calls('issueCommand'));
    }

    public function test_start_lost_mode_uses_the_configured_defaults_or_what_the_user_typed(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $service = app(CommandService::class);

        $defaults = $service->request($actor, $device, 'START_LOST_MODE');
        $custom = $service->request($actor, $device, 'START_LOST_MODE', ['message' => 'Call the ICT desk', 'phone' => '0244000000', 'address' => 'Head Office, Accra']);

        $this->deliver($defaults);
        $this->deliver($custom);

        $first = $this->gateway->calls('issueCommand')[0][1]['startLostModeParams'];
        $this->assertSame(['defaultMessage' => 'Property of GWL. Please call the number shown.'], $first['lostMessage']);
        $this->assertSame(['defaultMessage' => '0302000000'], $first['lostPhoneNumber']);
        $this->assertArrayNotHasKey('lostStreetAddress', $first);

        $second = $this->gateway->calls('issueCommand')[1][1]['startLostModeParams'];
        $this->assertSame(['defaultMessage' => 'Call the ICT desk'], $second['lostMessage']);
        $this->assertSame(['defaultMessage' => '0244000000'], $second['lostPhoneNumber']);
        $this->assertSame(['defaultMessage' => 'Head Office, Accra'], $second['lostStreetAddress']);
    }

    public function test_start_lost_mode_needs_something_to_show_on_the_phone(): void
    {
        config(['gwl.mdm_lost_mode_message' => null, 'gwl.mdm_lost_mode_phone' => null, 'gwl.mdm_lost_mode_address' => null]);
        [$actor, $device] = $this->actorAndDevice();

        try {
            app(CommandService::class)->request($actor, $device, 'START_LOST_MODE');
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message', $e->errors());
        }

        $this->assertSame(0, MdmDeviceCommand::query()->count());
    }

    public function test_the_passcode_is_encrypted_at_rest_blanked_after_delivery_and_never_audited(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $this->actingAs($actor);

        $command = app(CommandService::class)->request($actor, $device, 'RESET_PASSWORD', ['new_password' => 'Sup3rSecret!']);

        $this->assertStringNotContainsString('Sup3rSecret', (string) DB::table('mdm_device_commands')->value('payload'), 'encrypted at rest');
        $this->assertStringNotContainsString('Sup3rSecret', json_encode(AuditLog::query()->get()->toArray()));

        $this->deliver($command);

        $this->assertSame('Sup3rSecret!', $this->gateway->calls('issueCommand')[0][1]['newPassword'], 'it still reaches Google');
        $this->assertNull(DB::table('mdm_device_commands')->value('payload'), 'blanked once delivered');
        $this->assertStringNotContainsString('Sup3rSecret', json_encode(AuditLog::query()->get()->toArray()));
        $this->assertArrayNotHasKey('payload', $command->fresh()->toArray(), 'hidden from serialisation');
    }

    public function test_a_short_passcode_is_rejected(): void
    {
        [$actor, $device] = $this->actorAndDevice();

        $this->expectException(ValidationException::class);

        app(CommandService::class)->request($actor, $device, 'RESET_PASSWORD', ['new_password' => '123']);
    }

    public function test_an_unknown_command_type_and_the_wipe_type_are_refused_by_request(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $service = app(CommandService::class);

        foreach (['SELF_DESTRUCT', 'WIPE', 'RELINQUISH_OWNERSHIP'] as $type) {
            try {
                $service->request($actor, $device, $type);
                $this->fail("{$type} must not be accepted by request()");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, MdmDeviceCommand::query()->count());
    }

    // ------------------------------------------------------------------ authorisation & scope

    public function test_a_region_scoped_ict_user_cannot_command_a_phone_in_another_region(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $actor = $this->ictIn($accra);
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();

        try {
            app(CommandService::class)->request($actor, $foreign, 'LOCK');
            $this->fail('Expected the device to be out of scope.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, MdmDeviceCommand::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_an_unlinked_device_is_invisible_to_a_region_scoped_user_but_not_to_admins(): void
    {
        $actor = $this->ictIn($this->district());
        $unlinked = MdmDevice::factory()->unlinked()->create();
        $service = app(CommandService::class);

        $this->expectException(ModelNotFoundException::class);

        try {
            $service->request($actor, $unlinked, 'LOCK');
        } finally {
            // (unlinked devices are also needs_review, so an admin is blocked for a different reason — see below)
            $this->assertTrue(app(MdmAccessGuard::class)->canAccessDevice($this->admin(), $unlinked));
        }
    }

    public function test_admin_and_super_admin_can_command_a_phone_in_any_region(): void
    {
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $device = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();
        $service = app(CommandService::class);

        $service->request($this->admin(), $device, 'LOCK');
        $service->request($this->superAdmin(), $device, 'REBOOT');

        $this->assertSame(2, MdmDeviceCommand::query()->count());
    }

    public function test_a_user_without_the_command_permission_is_refused(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $this->revoke('ict_team', 'assets.mdm_command');

        $this->expectException(AuthorizationException::class);

        app(CommandService::class)->request($actor->fresh(), $device, 'LOCK');
    }

    public function test_a_user_with_no_mdm_role_is_refused_even_with_the_permission(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();
        $intruder = $this->userWithRole('hr_headoffice', 'HR001', $district);
        $intruder->roles->first()->permissions()->attach(Permission::query()->where('name', 'assets.mdm_command')->value('id'));

        $this->expectException(ModelNotFoundException::class);

        app(CommandService::class)->request($intruder->fresh(), $device, 'LOCK');
    }

    public function test_commands_are_refused_while_a_devices_identity_is_unconfirmed(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->needsReview()->create();

        try {
            app(CommandService::class)->request($this->superAdmin(), $device, 'LOCK');
            $this->fail('Expected the command to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('identity has not been confirmed', $e->errors()['command'][0]);
        }

        $this->assertSame(0, MdmDeviceCommand::query()->count());
    }

    public function test_commands_are_refused_for_a_removed_device(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $device->update(['state' => 'DELETED']);

        $this->expectException(ValidationException::class);

        app(CommandService::class)->request($actor, $device, 'LOCK');
    }

    public function test_command_requests_are_rate_limited_per_user(): void
    {
        config(['gwl.mdm_command_rate_per_minute' => 2]);
        [$actor, $device] = $this->actorAndDevice();
        $other = $this->admin();
        $service = app(CommandService::class);

        $service->request($actor, $device, 'LOCK');
        $service->request($actor, $device, 'LOCK');

        try {
            $service->request($actor, $device, 'LOCK');
            $this->fail('The third command within a minute must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('too quickly', $e->errors()['command'][0]);
        }

        $this->assertSame(2, MdmDeviceCommand::query()->count());

        $service->request($other, $device, 'LOCK');
        $this->assertSame(3, MdmDeviceCommand::query()->count(), 'the limit is per user, not global');

        RateLimiter::clear('mdm-command:'.$actor->id);
    }

    // ------------------------------------------------------------------ delivery checks

    public function test_delivery_re_checks_the_requesters_permission_and_never_calls_google_if_it_was_revoked(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $command = app(CommandService::class)->request($actor, $device, 'LOCK');

        $this->revoke('ict_team', 'assets.mdm_command');
        $this->deliver($command);

        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $command->fresh()->status);
        $this->assertStringContainsString('no longer allowed', $command->fresh()->error);
        $this->assertFalse($this->gateway->called('issueCommand'));
    }

    public function test_delivery_re_checks_region_scope_and_account_status(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $actor = $this->ictIn($accra);
        $device = MdmDevice::factory()->forAsset($this->phone($accra))->create();
        $service = app(CommandService::class);

        $moved = $service->request($actor, $device, 'LOCK');
        $device->asset->update(['region_id' => $kumasi->region_id]); // the phone changes region while queued
        $this->deliver($moved);
        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $moved->fresh()->status);

        $device->asset->update(['region_id' => $accra->region_id]);
        $inactive = $service->request($actor, $device, 'LOCK');
        $actor->forceFill(['is_active' => false])->save();
        $this->deliver($inactive);
        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $inactive->fresh()->status);

        $this->assertFalse($this->gateway->called('issueCommand'));
    }

    public function test_a_permanent_google_error_fails_the_command_with_the_message(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $command = app(CommandService::class)->request($actor, $device, 'LOCK');
        $this->gateway->failures['issueCommand'] = new MdmGatewayException('Google API error 400: bad command', 400);

        $this->deliver($command);

        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $command->fresh()->status);
        $this->assertStringContainsString('bad command', $command->fresh()->error);
    }

    public function test_a_transient_google_error_leaves_the_command_for_the_job_to_retry(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $command = app(CommandService::class)->request($actor, $device, 'LOCK');
        $this->gateway->failures['issueCommand'] = new MdmGatewayException('Google API error 503', 503);

        try {
            $this->deliver($command);
            $this->fail('A transient failure must propagate so the queue retries.');
        } catch (MdmGatewayException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(MdmDeviceCommand::STATUS_REQUESTED, $command->fresh()->status);

        // …and once retries are exhausted the job's failed() hook closes it out.
        (new SendMdmCommand($command->id))->failed(new MdmGatewayException('Google API error 503', 503));
        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $command->fresh()->status);
    }

    // ------------------------------------------------------------------ wipe

    public function test_wipe_needs_the_wipe_permission_which_ict_team_does_not_hold_by_default(): void
    {
        [$actor, $device] = $this->actorAndDevice();

        $this->expectException(AuthorizationException::class);

        app(CommandService::class)->requestWipe($actor, $device, 'password');
    }

    public function test_wipe_needs_the_actors_own_password(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $admin = $this->admin();
        $service = app(CommandService::class);

        foreach (['', 'wrong-password'] as $attempt) {
            try {
                $service->requestWipe($admin, $device, $attempt);
                $this->fail('A wipe without the correct password must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }

        $this->assertSame(0, MdmDeviceCommand::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_repeated_wrong_passwords_lock_the_wipe_confirmation_out(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $admin = $this->admin();
        $service = app(CommandService::class);

        foreach (range(1, 5) as $ignored) {
            try {
                $service->requestWipe($admin, $device, 'nope');
            } catch (ValidationException) {
            }
        }

        try {
            $service->requestWipe($admin, $device, 'password'); // even the right one now
            $this->fail('The confirmation should be locked out.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many incorrect attempts', $e->errors()['password'][0]);
        }
    }

    public function test_wipe_calls_devices_delete_with_the_flags_and_never_issue_command(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->lost()->create();
        $admin = $this->admin();
        $this->actingAs($admin);

        $command = app(CommandService::class)->requestWipe($admin, $device, 'password', preserveResetProtection: true, wipeExternalStorage: true);

        $this->assertSame(MdmDeviceCommand::TYPE_WIPE, $command->type);
        $this->assertSame([], $this->gateway->calls, 'requesting a wipe does not touch Google either');
        Queue::assertPushed(SendMdmCommand::class);

        $this->deliver($command);

        $this->assertFalse($this->gateway->called('issueCommand'), 'wipe is devices.delete, not an issueCommand');
        $this->assertSame([[$device->google_device_name, ['PRESERVE_RESET_PROTECTION_DATA', 'WIPE_EXTERNAL_STORAGE']]], $this->gateway->calls('deleteDevice'));

        $this->assertSame(MdmDeviceCommand::STATUS_ACKNOWLEDGED, $command->fresh()->status);
        $this->assertSame('DELETED', $device->fresh()->state);
        $this->assertFalse($device->fresh()->is_lost);
    }

    public function test_factory_reset_protection_is_preserved_by_default(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $admin = $this->admin();

        $command = app(CommandService::class)->requestWipe($admin, $device, 'password');
        $this->deliver($command);

        $this->assertSame(['PRESERVE_RESET_PROTECTION_DATA'], $this->gateway->calls('deleteDevice')[0][1]);
    }

    public function test_a_device_google_no_longer_knows_still_counts_as_wiped(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $command = app(CommandService::class)->requestWipe($this->admin(), $device, 'password');
        $this->gateway->failures['deleteDevice'] = new MdmGatewayException('not found', 404);

        $this->deliver($command);

        $this->assertSame(MdmDeviceCommand::STATUS_ACKNOWLEDGED, $command->fresh()->status);
        $this->assertSame('DELETED', $device->fresh()->state);
    }

    public function test_a_failed_wipe_is_recorded_as_failed_and_the_device_stays_managed(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $command = app(CommandService::class)->requestWipe($this->admin(), $device, 'password');
        $this->gateway->failures['deleteDevice'] = new MdmGatewayException('Google API error 403: forbidden', 403);

        $this->deliver($command);

        $this->assertSame(MdmDeviceCommand::STATUS_FAILED, $command->fresh()->status);
        $this->assertSame('ACTIVE', $device->fresh()->state);
    }

    public function test_wipe_is_still_possible_for_an_unverified_device_because_it_targets_the_physical_phone(): void
    {
        $device = MdmDevice::factory()->unlinked()->create();

        $command = app(CommandService::class)->requestWipe($this->admin(), $device, 'password');

        $this->assertSame(MdmDeviceCommand::TYPE_WIPE, $command->type);
    }

    public function test_a_wiped_device_cannot_be_wiped_again(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create(['state' => 'DELETED']);

        $this->expectException(ValidationException::class);

        app(CommandService::class)->requestWipe($this->admin(), $device, 'password');
    }

    // ------------------------------------------------------------------ audit

    public function test_every_command_writes_audit_entries_for_the_request_and_the_delivery(): void
    {
        [$actor, $device] = $this->actorAndDevice();
        $this->actingAs($actor);

        $command = app(CommandService::class)->request($actor, $device, 'LOCK');
        $this->deliver($command);

        $requested = AuditLog::query()->where('action', 'mdm_command_requested')->firstOrFail();
        $sent = AuditLog::query()->where('action', 'mdm_command_sent')->firstOrFail();

        $this->assertSame('assets', $requested->module);
        $this->assertSame('mdm_devices', $requested->target_type);
        $this->assertSame($device->id, $requested->target_id);
        $this->assertSame($actor->id, $requested->user_id);
        $this->assertSame('LOCK', $requested->metadata['type']);
        $this->assertSame($command->id, $requested->metadata['command_id']);
        $this->assertSame($actor->id, $sent->metadata['actor_id'], 'the job has no Auth::user(), so the requester is in the metadata');
    }

    public function test_wipe_and_failures_are_audited_too(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $admin = $this->admin();
        $this->actingAs($admin);

        $wipe = app(CommandService::class)->requestWipe($admin, $device, 'password');
        $this->deliver($wipe);

        $this->assertTrue(AuditLog::query()->where('action', 'mdm_wipe_requested')->where('user_id', $admin->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'mdm_wipe_sent')->exists());

        $lock = app(CommandService::class)->request($admin, MdmDevice::factory()->forAsset($this->phone())->create(), 'LOCK');
        $this->gateway->failures['issueCommand'] = new MdmGatewayException('bad', 400);
        $this->deliver($lock);

        $this->assertTrue(AuditLog::query()->where('action', 'mdm_command_failed')->exists());
    }

    public function test_the_wipe_password_is_never_written_anywhere(): void
    {
        $device = MdmDevice::factory()->forAsset($this->phone())->create();
        $admin = $this->admin();
        $this->actingAs($admin);

        $command = app(CommandService::class)->requestWipe($admin, $device, 'password');
        $this->deliver($command);

        $this->assertStringNotContainsString('"password"', json_encode(AuditLog::query()->get()->toArray()));
        $this->assertNull($command->fresh()->error);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: User, 1: MdmDevice} a region-scoped ICT user and a phone in their region */
    private function actorAndDevice(): array
    {
        $district = $this->district();
        $actor = $this->ictIn($district);
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        return [$actor, $device];
    }

    private function deliver(MdmDeviceCommand $command): void
    {
        (new SendMdmCommand($command->id))->handle(app(CommandService::class));
    }

    private function revoke(string $role, string $permission): void
    {
        Role::query()->where('name', $role)->firstOrFail()->permissions()->detach(
            Permission::query()->where('name', $permission)->value('id')
        );
    }
}
