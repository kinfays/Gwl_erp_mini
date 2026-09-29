<?php

namespace Tests\Feature\Assets\Mdm;

use App\Jobs\Assets\Mdm\SendMdmCommand;
use App\Jobs\Assets\Mdm\SyncMdmDevice;
use App\Livewire\Assets\Mdm\DeviceDetail;
use App\Livewire\Assets\Mdm\DevicesDashboard;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Models\MdmEvent;
use App\Models\MdmPolicy;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class DeviceScreensTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        Queue::fake();
    }

    // ------------------------------------------------------------------ dashboard

    public function test_the_dashboard_totals_cover_only_the_viewers_region(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $viewer = $this->ictIn($accra);

        MdmDevice::factory()->forAsset($this->phone($accra))->create();                          // compliant
        MdmDevice::factory()->forAsset($this->phone($accra))->nonCompliant()->create();          // non-compliant
        MdmDevice::factory()->forAsset($this->phone($accra))->lost()->create();                  // lost (and compliant)
        MdmDevice::factory()->forAsset($this->phone($accra))->needsReview()->create();           // needs review
        MdmDevice::factory()->forAsset($this->phone($accra))->stale()->create();                 // no report in 24h
        MdmDevice::factory()->forAsset($this->phone($accra))->create(['state' => 'DELETED']);    // removed: not counted
        MdmDevice::factory()->forAsset($this->phone($kumasi))->nonCompliant()->lost()->create(); // other region: invisible

        $totals = Livewire::actingAs($viewer)->test(DevicesDashboard::class)->viewData('summary')['totals'];

        $this->assertSame(5, $totals['total']);
        $this->assertSame(4, $totals['compliant']);
        $this->assertSame(1, $totals['non_compliant']);
        $this->assertSame(1, $totals['lost']);
        $this->assertSame(1, $totals['needs_review']);
        $this->assertSame(1, $totals['not_reported']);
    }

    public function test_admins_see_every_region_and_unlinked_devices(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        MdmDevice::factory()->forAsset($this->phone($accra))->create();
        MdmDevice::factory()->forAsset($this->phone($kumasi))->create();
        MdmDevice::factory()->unlinked()->create();

        $this->assertSame(3, Livewire::actingAs($this->admin())->test(DevicesDashboard::class)->viewData('summary')['totals']['total']);
        $this->assertSame(1, Livewire::actingAs($this->ictIn($accra, 'ICT002'))->test(DevicesDashboard::class)->viewData('summary')['totals']['total']);
    }

    public function test_the_device_list_only_lists_devices_in_scope_and_can_be_searched_and_filtered(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $viewer = $this->ictIn($accra);
        $mine = MdmDevice::factory()->forAsset($this->phone($accra, ['asset_name' => 'Accra Alpha']))->create();
        MdmDevice::factory()->forAsset($this->phone($accra, ['asset_name' => 'Accra Bravo']))->nonCompliant()->create();
        MdmDevice::factory()->forAsset($this->phone($kumasi, ['asset_name' => 'Kumasi Charlie']))->create();

        $names = fn ($component) => $component->viewData('devices')->map(fn ($device) => $device->asset->asset_name)->sort()->values()->all();

        $component = Livewire::actingAs($viewer)->test(DevicesDashboard::class);
        $this->assertSame(['Accra Alpha', 'Accra Bravo'], $names($component));

        $component->set('search', 'Alpha');
        $this->assertSame(['Accra Alpha'], $names($component));

        $component->set('search', '')->set('filter', 'non_compliant');
        $this->assertSame(['Accra Bravo'], $names($component));

        $component->set('filter', '')->set('search', $mine->asset->serial_number);
        $this->assertSame(['Accra Alpha'], $names($component));
    }

    public function test_searching_for_another_regions_serial_number_finds_nothing(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi, ['serial_number' => 'FOREIGN-SERIAL-1']))->create();

        Livewire::actingAs($this->ictIn($accra))->test(DevicesDashboard::class)
            ->set('search', 'FOREIGN-SERIAL-1')
            ->assertDontSee('FOREIGN-SERIAL-1')
            ->assertSee('No managed phones found');
    }

    public function test_the_intake_health_tile_shows_mode_last_event_and_failed_or_unprocessed_counts_to_unscoped_users_only(): void
    {
        MdmEvent::factory()->processed()->create(['received_at' => now()->subMinutes(3), 'notification_type' => 'STATUS_REPORT']);
        MdmEvent::factory()->failed('Boom')->create();
        MdmEvent::factory()->create(); // unprocessed, no error
        config(['gwl.mdm_pubsub_mode' => 'push']);

        $intake = Livewire::actingAs($this->admin())->test(DevicesDashboard::class)->viewData('summary')['intake'];

        $this->assertSame('push', $intake['mode']);
        $this->assertSame(1, $intake['failed']);
        $this->assertSame(1, $intake['unprocessed']);
        $this->assertNotNull($intake['last_received_at']);

        Livewire::actingAs($this->admin())->test(DevicesDashboard::class)->assertSee('Event intake')->assertSee('PUSH');

        $scoped = Livewire::actingAs($this->ictIn($this->district()))->test(DevicesDashboard::class);
        $this->assertNull($scoped->viewData('summary')['intake']);
        $scoped->assertDontSee('Event intake');
    }

    public function test_recent_commands_and_enrollments_are_scoped_too(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $mine = MdmDevice::factory()->forAsset($this->phone($accra))->create();
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();
        $myCommand = MdmDeviceCommand::factory()->create(['mdm_device_id' => $mine->id, 'type' => 'LOCK']);
        MdmDeviceCommand::factory()->create(['mdm_device_id' => $foreign->id, 'type' => 'REBOOT']);

        $summary = Livewire::actingAs($this->ictIn($accra))->test(DevicesDashboard::class)->viewData('summary');

        $this->assertSame([$myCommand->id], $summary['recent_commands']->pluck('id')->all());
        $this->assertSame([$mine->id], $summary['recent_enrollments']->pluck('id')->all());
    }

    // ------------------------------------------------------------------ device detail: scope

    public function test_a_region_scoped_user_cannot_open_a_device_in_another_region(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();

        $this->withoutExceptionHandling();
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->ictIn($accra))->test(DeviceDetail::class, ['deviceId' => $foreign->id]);
    }

    public function test_the_device_page_is_a_404_over_http_for_another_regions_device_and_for_a_missing_one(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();
        $mine = MdmDevice::factory()->forAsset($this->phone($accra))->create();
        $viewer = $this->ictIn($accra);

        $this->actingAs($viewer)->get(route('assets.mdm.devices.show', $foreign->id))->assertNotFound();
        $this->actingAs($viewer)->get(route('assets.mdm.devices.show', 999999))->assertNotFound();
        $this->actingAs($viewer)->get(route('assets.mdm.devices.show', $mine->id))->assertOk()->assertSee($mine->asset->asset_name);
    }

    public function test_the_device_id_cannot_be_rewritten_from_the_client_to_reach_another_regions_phone(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $mine = MdmDevice::factory()->forAsset($this->phone($accra))->create();
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->ictIn($accra))->test(DeviceDetail::class, ['deviceId' => $mine->id])->set('deviceId', $foreign->id);
    }

    public function test_the_open_modal_name_cannot_be_forced_from_the_client(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])->set('modal', 'wipe');
    }

    // ------------------------------------------------------------------ device detail: content and actions

    public function test_the_detail_shows_identity_policy_compliance_apps_check_in_and_history(): void
    {
        $district = $this->district();
        $employee = $this->employeeIn($district, '700001', 'Ama Mensah');
        $asset = $this->phone($district, ['asset_name' => 'Field Phone 77', 'serial_number' => 'SN-777', 'imei' => '356938035643809', 'assigned_to_employee_id' => $employee->id]);
        $policy = MdmPolicy::factory()->published()->create(['name' => 'Branch Office Phone']);
        $device = MdmDevice::factory()->forAsset($asset)->nonCompliant()->create([
            'mdm_policy_id' => $policy->id,
            'application_reports' => [['packageName' => 'com.whatsapp.w4b', 'displayName' => 'WhatsApp Business', 'versionName' => '2.24', 'state' => 'INSTALLED']],
        ]);
        MdmDeviceCommand::factory()->acknowledged()->create(['mdm_device_id' => $device->id, 'type' => 'LOCK', 'requested_by' => $this->admin()->id]);

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->assertSee('Field Phone 77')->assertSee('SN-777')->assertSee('356938035643809')->assertSee('Ama Mensah')
            ->assertSee('Branch Office Phone')
            ->assertSee('Non-compliant')->assertSee('App Not Installed')->assertSee('com.whatsapp.w4b')
            ->assertSee('WhatsApp Business')
            ->assertSee('Last check-in')->assertSee('1 hour ago')
            ->assertSee('Lock')->assertSee('Acknowledged');
    }

    public function test_locking_a_phone_queues_a_command_and_does_not_call_google(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();
        $actor = $this->ictIn($district);

        Livewire::actingAs($actor)->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->call('openModal', 'lock')->assertSet('modal', 'lock')
            ->call('lock')
            ->assertHasNoErrors()
            ->assertSet('modal', null)
            ->assertDispatched('toast');

        $command = MdmDeviceCommand::query()->firstOrFail();
        $this->assertSame('LOCK', $command->type);
        $this->assertSame($actor->id, $command->requested_by);
        Queue::assertPushed(SendMdmCommand::class);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_the_lost_mode_modal_shows_tag_serial_imei_and_assignee_and_prefills_the_defaults(): void
    {
        $district = $this->district();
        $employee = $this->employeeIn($district, '700002', 'Kofi Boateng');
        $asset = $this->phone($district, ['asset_name' => 'Tag-0042', 'serial_number' => 'SN-0042', 'imei' => '490154203237518', 'assigned_to_employee_id' => $employee->id]);
        $device = MdmDevice::factory()->forAsset($asset)->create();

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->call('openModal', 'lost-start')
            ->assertSee('Start Lost Mode?')
            ->assertSee('Tag-0042')->assertSee('SN-0042')->assertSee('490154203237518')->assertSee('Kofi Boateng')
            ->assertSet('lostMessage', 'Property of GWL. Please call the number shown.')
            ->assertSet('lostPhone', '0302000000');
    }

    public function test_starting_and_stopping_lost_mode_go_through_the_confirmation_and_the_queue(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();
        $actor = $this->ictIn($district);

        $component = Livewire::actingAs($actor)->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->call('openModal', 'lost-start')
            ->set('lostMessage', 'Return to ICT, please')
            ->call('startLostMode')->assertHasNoErrors()->assertSet('modal', null);

        $start = MdmDeviceCommand::query()->firstOrFail();
        $this->assertSame('START_LOST_MODE', $start->type);
        $this->assertSame('Return to ICT, please', $start->payload['message']);

        $device->update(['is_lost' => true, 'lost_at' => now()]);

        $component->call('openModal', 'lost-stop')->assertSee('Stop Lost Mode?')->call('stopLostMode')->assertHasNoErrors();
        $this->assertSame(['START_LOST_MODE', 'STOP_LOST_MODE'], MdmDeviceCommand::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_a_lost_device_shows_stop_instead_of_start(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->lost()->create();

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->assertSee('Lost Mode is on')->assertSee('Stop Lost Mode')->assertDontSee('Start Lost Mode');
    }

    public function test_the_wipe_modal_shows_the_identity_and_needs_the_password_and_the_acknowledgement(): void
    {
        $asset = $this->phone(null, ['asset_name' => 'Tag-0099', 'serial_number' => 'SN-0099', 'imei' => '490154203237518']);
        $device = MdmDevice::factory()->forAsset($asset)->create();
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->call('openModal', 'wipe')
            ->assertSee('Wipe this phone?')->assertSee('Tag-0099')->assertSee('SN-0099')->assertSee('490154203237518')
            ->assertSee('Keep factory reset protection')
            ->call('wipe')
            ->assertHasErrors(['wipeAcknowledge', 'wipePassword'])
            ->set('wipeAcknowledge', true)
            ->set('wipePassword', 'not-my-password')
            ->call('wipe')
            ->assertHasErrors(['password']);

        $this->assertSame(0, MdmDeviceCommand::query()->count());

        $component->set('wipePassword', 'password')->call('wipe')->assertHasNoErrors()->assertSet('modal', null)->assertSet('wipePassword', '');

        $command = MdmDeviceCommand::query()->firstOrFail();
        $this->assertSame('WIPE', $command->type);
        $this->assertSame(['PRESERVE_RESET_PROTECTION_DATA'], $command->payload['wipe_data_flags']);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_ict_team_cannot_open_or_submit_the_wipe_modal_even_by_calling_the_action_directly(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        $this->withoutExceptionHandling();
        $component = Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id]);

        $component->assertDontSee('Wipe phone');

        $this->expectException(HttpException::class);

        $component->call('openModal', 'wipe');
    }

    public function test_calling_the_wipe_action_directly_without_the_permission_queues_nothing(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        try {
            Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])
                ->set('wipeAcknowledge', true)->set('wipePassword', 'password')->call('wipe');
        } catch (\Throwable) {
            // 403 from the service layer
        }

        $this->assertSame(0, MdmDeviceCommand::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_modal_name_is_rejected(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])->call('openModal', 'format-the-disk');
    }

    public function test_commands_are_rate_limited_from_the_screen_too(): void
    {
        config(['gwl.mdm_command_rate_per_minute' => 1]);
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->call('lock')->assertHasNoErrors()
            ->call('reboot')->assertHasErrors(['command']);

        $this->assertSame(1, MdmDeviceCommand::query()->count());
    }

    public function test_a_device_awaiting_review_shows_the_reason_and_only_admins_can_clear_it(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->needsReview('IMEI mismatch: asset says 1, phone says 2.')->create();

        $ict = Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->assertSee('Identity needs review')->assertSee('IMEI mismatch: asset says 1, phone says 2.')
            ->assertDontSee('Confirm identity');

        try {
            $ict->call('confirmIdentity');
        } catch (\Throwable) {
        }
        $this->assertTrue($device->fresh()->needs_review);

        Livewire::actingAs($this->admin())->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->assertSee('Confirm identity')->call('confirmIdentity')->assertHasNoErrors();

        $this->assertFalse($device->fresh()->needs_review);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdm_device_review_cleared', 'target_id' => $device->id]);
    }

    public function test_sync_now_queues_a_job_rather_than_calling_google(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();

        Livewire::actingAs($this->ictIn($district))->test(DeviceDetail::class, ['deviceId' => $device->id])->call('syncNow');

        Queue::assertPushed(SyncMdmDevice::class, fn ($job) => $job->deviceId === $device->id);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_a_user_with_view_only_sees_no_action_buttons(): void
    {
        $district = $this->district();
        $device = MdmDevice::factory()->forAsset($this->phone($district))->create();
        $viewer = $this->ictIn($district);
        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()
            ->detach(Permission::query()->whereIn('name', ['assets.mdm_command', 'assets.mdm_enroll'])->pluck('id'));

        Livewire::actingAs($viewer->fresh())->test(DeviceDetail::class, ['deviceId' => $device->id])
            ->assertDontSee('Reset passcode')->assertDontSee('Start Lost Mode')->assertDontSee('Wipe phone');
    }
}
