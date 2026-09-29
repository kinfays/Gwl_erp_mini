<?php

namespace Tests\Feature\Assets\Mdm;

use App\Jobs\Assets\Mdm\SendMdmCommand;
use App\Livewire\Assets\PhonesList;
use App\Models\IctAsset;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class PhonesLostModePromptTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        Queue::fake();
    }

    public function test_marking_an_enrolled_phone_lost_offers_lost_mode_and_nothing_is_sent_until_confirmed(): void
    {
        [$actor, $asset, $device] = $this->enrolledPhone();

        $component = $this->markStatus($actor, $asset, IctAsset::STATUS_LOST)
            ->assertHasNoErrors()
            ->assertSet('mdmPromptDeviceId', $device->id)
            ->assertSet('mdmPromptAction', 'start')
            ->assertSee('Start Lost Mode on this phone?')
            ->assertSee($asset->asset_name)->assertSee($asset->serial_number)->assertSee($asset->imei);

        $this->assertSame(IctAsset::STATUS_LOST, $asset->fresh()->status, 'the asset record is saved either way');
        $this->assertSame(0, MdmDeviceCommand::query()->count());
        $this->assertSame([], $this->gateway->calls);

        $component->set('mdmPromptMessage', 'Lost — call ICT')->call('confirmMdmPrompt')
            ->assertHasNoErrors()->assertSet('mdmPromptDeviceId', null)->assertDispatched('toast');

        $command = MdmDeviceCommand::query()->firstOrFail();
        $this->assertSame('START_LOST_MODE', $command->type);
        $this->assertSame('Lost — call ICT', $command->payload['message']);
        $this->assertSame($actor->id, $command->requested_by);
        Queue::assertPushed(SendMdmCommand::class);
    }

    public function test_declining_the_offer_sends_nothing(): void
    {
        [$actor, $asset] = $this->enrolledPhone();

        $this->markStatus($actor, $asset, IctAsset::STATUS_LOST)->call('dismissMdmPrompt')
            ->assertSet('mdmPromptDeviceId', null)->assertSet('mdmPromptAction', null);

        $this->assertSame(0, MdmDeviceCommand::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_recovering_a_phone_in_lost_mode_offers_to_stop_it(): void
    {
        [$actor, $asset, $device] = $this->enrolledPhone(['status' => IctAsset::STATUS_LOST], lost: true);

        $component = $this->markStatus($actor, $asset, IctAsset::STATUS_ACTIVE)
            ->assertSet('mdmPromptAction', 'stop')
            ->assertSee('Stop Lost Mode on this phone?');

        $component->call('confirmMdmPrompt')->assertHasNoErrors();

        $this->assertSame('STOP_LOST_MODE', MdmDeviceCommand::query()->firstOrFail()->type);
        $this->assertTrue($device->fresh()->is_lost, 'the flag only clears once Google acknowledges the stop');
    }

    public function test_no_offer_for_a_phone_that_is_not_enrolled_or_was_already_in_that_state(): void
    {
        $district = $this->district();
        $actor = $this->ictIn($district);

        $plain = $this->phone($district);
        $this->markStatus($actor, $plain, IctAsset::STATUS_LOST)->assertSet('mdmPromptDeviceId', null);

        [$actor2, $enrolled] = $this->enrolledPhone([], false, 'ICT002');
        $this->markStatus($actor2, $enrolled, IctAsset::STATUS_IN_REPAIR)->assertSet('mdmPromptDeviceId', null);

        [$actor3, $alreadyLost] = $this->enrolledPhone([], true, 'ICT003');
        $this->markStatus($actor3, $alreadyLost, IctAsset::STATUS_LOST)->assertSet('mdmPromptDeviceId', null);

        $this->assertSame(0, MdmDeviceCommand::query()->count());
    }

    public function test_no_offer_when_the_device_is_removed_or_awaiting_identity_review(): void
    {
        [$actor, $asset, $device] = $this->enrolledPhone();
        $device->update(['needs_review' => true]);

        $this->markStatus($actor, $asset, IctAsset::STATUS_LOST)->assertSet('mdmPromptDeviceId', null);

        [$actor2, $asset2, $device2] = $this->enrolledPhone([], false, 'ICT004');
        $device2->update(['state' => 'DELETED']);

        $this->markStatus($actor2, $asset2, IctAsset::STATUS_LOST)->assertSet('mdmPromptDeviceId', null);
    }

    public function test_no_offer_when_the_flag_is_off_or_the_user_may_not_send_commands(): void
    {
        [$actor, $asset] = $this->enrolledPhone();

        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()
            ->detach(Permission::query()->where('name', 'assets.mdm_command')->value('id'));
        $this->markStatus($actor->fresh(), $asset, IctAsset::STATUS_LOST)->assertSet('mdmPromptDeviceId', null);

        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()->attach(Permission::query()->where('name', 'assets.mdm_command')->value('id'));

        [$actor2, $asset2] = $this->enrolledPhone([], false, 'ICT005');
        config(['gwl.mdm_enabled' => false]);
        $this->markStatus($actor2->fresh(), $asset2, IctAsset::STATUS_LOST)->assertSet('mdmPromptDeviceId', null);
    }

    public function test_the_prompt_target_cannot_be_rewritten_from_the_client(): void
    {
        [$actor, $asset] = $this->enrolledPhone();
        $foreign = MdmDevice::factory()->forAsset($this->phone($this->district('Kumasi Central District', 'Ashanti')))->create();

        $component = $this->markStatus($actor, $asset, IctAsset::STATUS_LOST);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $component->set('mdmPromptDeviceId', $foreign->id);
    }

    public function test_confirming_without_a_prompt_does_nothing_and_a_wrong_state_is_a_404(): void
    {
        [$actor] = $this->enrolledPhone();

        $this->withoutExceptionHandling();
        $this->expectException(NotFoundHttpException::class);

        Livewire::actingAs($actor)->test(PhonesList::class)->call('confirmMdmPrompt');
    }

    public function test_a_missing_lost_message_is_asked_for_instead_of_failing_silently(): void
    {
        config(['gwl.mdm_lost_mode_message' => null, 'gwl.mdm_lost_mode_phone' => null]);
        [$actor, $asset] = $this->enrolledPhone();

        $this->markStatus($actor, $asset, IctAsset::STATUS_LOST)
            ->call('confirmMdmPrompt')
            ->assertHasErrors(['message'])
            ->assertSee('Enter a message, phone number or address')
            ->assertSet('mdmPromptAction', 'start');

        $this->assertSame(0, MdmDeviceCommand::query()->count());
    }

    public function test_enrolled_rows_link_to_the_device_page_and_others_do_not(): void
    {
        [$actor, $asset, $device] = $this->enrolledPhone(['asset_name' => 'Linked One']);
        $this->phone($this->district(), ['asset_name' => 'Plain One']);
        $device->update(['is_lost' => true]);

        Livewire::actingAs($actor)->test(PhonesList::class)
            ->assertSee('Linked One')->assertSee('Plain One')
            ->assertSee(route('assets.mdm.devices.show', $device->id), false)
            ->assertSee('MDM · Lost mode');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: User, 1: IctAsset, 2: MdmDevice} */
    private function enrolledPhone(array $assetOverrides = [], bool $lost = false, string $staffId = 'ICT001'): array
    {
        $district = $this->district();
        $actor = User::query()->where('staff_id', $staffId)->first() ?? $this->ictIn($district, $staffId);
        $asset = $this->phone($district, $assetOverrides);
        $device = MdmDevice::factory()->forAsset($asset);
        $device = ($lost ? $device->lost() : $device)->create();

        return [$actor, $asset, $device];
    }

    private function markStatus(User $actor, IctAsset $asset, string $status): Testable
    {
        return Livewire::actingAs($actor)->test(PhonesList::class)
            ->call('openEdit', $asset->id)
            ->set('form.status', $status)
            ->call('save');
    }
}
