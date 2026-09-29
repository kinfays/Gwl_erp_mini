<?php

namespace App\Livewire\Assets\Mdm;

use App\Jobs\Assets\Mdm\SyncMdmDevice;
use App\Livewire\Assets\Mdm\Concerns\AuthorizesMdm;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Services\Assets\Mdm\CommandService;
use App\Services\Assets\Mdm\DeviceService;
use App\Services\Assets\Mdm\MdmSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One phone: identity, compliance, installed apps, command history and the actions.
 *
 * The device id is #[Locked] and, like every id that reaches an action, resolved again through MdmAccessGuard, so
 * neither a tampered property nor a hand-built request can reach a phone outside the viewer's region. Lost Mode and
 * Wipe go through a confirmation modal showing the phone's identity; Wipe also needs the admin's own password.
 * Commands are queued, never sent from this request.
 */
class DeviceDetail extends Component
{
    use AuthorizesMdm;

    public const MODALS = ['lock', 'reboot', 'reset', 'lost-start', 'lost-stop', 'wipe'];

    #[Locked]
    public int $deviceId;

    #[Locked]
    public ?string $modal = null;

    public string $lostMessage = '';

    public string $lostPhone = '';

    public string $lostAddress = '';

    public string $resetPasscode = '';

    public string $wipePassword = '';

    public bool $wipeAcknowledge = false;

    public bool $wipePreserveFrp = true;

    public bool $wipeExternalStorage = false;

    public function mount(int $deviceId): void
    {
        $this->bootMdmScreen('assets.mdm_view');

        $this->deviceId = $deviceId;

        // 404 for a device that does not exist OR is outside this viewer's region.
        $this->device();

        $this->prefillLostMode();
    }

    /** Open a confirmation modal for an action the viewer is allowed to take on this phone. */
    public function openModal(string $name): void
    {
        abort_unless(in_array($name, self::MODALS, true), 422);

        $this->authorizeMdm($name === 'wipe' ? 'assets.mdm_wipe' : 'assets.mdm_command');
        $this->device();

        $this->resetSensitiveFields();
        $this->resetErrorBag();
        $this->modal = $name;
    }

    public function closeModal(): void
    {
        $this->modal = null;
        $this->resetSensitiveFields();
        $this->resetErrorBag();
    }

    public function lock(): void
    {
        $this->send(MdmDeviceCommand::TYPE_LOCK, [], 'Lock command queued.');
    }

    public function reboot(): void
    {
        $this->send(MdmDeviceCommand::TYPE_REBOOT, [], 'Reboot command queued.');
    }

    public function resetPasscodeNow(): void
    {
        $this->send(MdmDeviceCommand::TYPE_RESET_PASSWORD, ['new_password' => $this->resetPasscode], 'Passcode reset queued.');
    }

    public function startLostMode(): void
    {
        $this->send(MdmDeviceCommand::TYPE_START_LOST_MODE, [
            'message' => $this->lostMessage,
            'phone' => $this->lostPhone,
            'address' => $this->lostAddress,
        ], 'Lost Mode queued.');
    }

    public function stopLostMode(): void
    {
        $this->send(MdmDeviceCommand::TYPE_STOP_LOST_MODE, [], 'Stop Lost Mode queued.');
    }

    public function wipe(): void
    {
        $this->validate(['wipeAcknowledge' => ['accepted'], 'wipePassword' => ['required', 'string']], [
            'wipeAcknowledge.accepted' => 'Tick the box to confirm you understand the phone will be erased.',
            'wipePassword.required' => 'Enter your password to confirm.',
        ]);

        try {
            app(CommandService::class)->requestWipe(
                $this->mdmUser(),
                $this->device(),
                $this->wipePassword,
                $this->wipePreserveFrp,
                $this->wipeExternalStorage,
            );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->closeModal();
        $this->dispatch('toast', type: 'warning', message: 'Wipe queued. The phone will be erased as soon as it next checks in.');
    }

    /** devices.get for this phone, queued. */
    public function syncNow(): void
    {
        $this->authorizeMdm('assets.mdm_view');

        SyncMdmDevice::dispatch($this->device()->id);

        $this->dispatch('toast', type: 'success', message: 'Sync queued.');
    }

    /** An administrator confirms an unverified enrollment really is this asset. */
    public function confirmIdentity(): void
    {
        try {
            app(DeviceService::class)->confirmIdentity($this->device(), $this->mdmUser());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->dispatch('toast', type: 'success', message: 'Identity confirmed.');
    }

    public function render()
    {
        $device = $this->device();
        $device->load(['asset.assignedTo', 'asset.district', 'asset.region', 'asset.assetModel', 'policy']);

        return view('livewire.assets.mdm.device-detail', [
            'device' => $device,
            'commands' => $device->commands()->with('requester')->limit(15)->get(),
            'canCommand' => $this->canMdm('assets.mdm_command') && ! $device->isDeleted(),
            'canWipe' => $this->canMdm('assets.mdm_wipe') && ! $device->isDeleted(),
            'canReview' => $this->mdmGuard()->canReview($this->mdmUser()),
            'staleBefore' => now()->subHours(max(1, (int) config('gwl.mdm_stale_report_hours', 24))),
        ]);
    }

    /**
     * Always resolved through the guard — never with MdmDevice::find() on the property — so scope is re-applied on
     * every request, action and render.
     */
    private function device(): MdmDevice
    {
        return $this->mdmGuard()->deviceOrFail($this->mdmUser(), $this->deviceId);
    }

    /** @param  array<string, mixed>  $params */
    private function send(string $type, array $params, string $successMessage): void
    {
        try {
            app(CommandService::class)->request($this->mdmUser(), $this->device(), $type, $params);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->closeModal();
        $this->dispatch('toast', type: 'success', message: $successMessage);
    }

    private function prefillLostMode(): void
    {
        $defaults = app(MdmSettings::class)->lostModeDefaults();

        $this->lostMessage = (string) $defaults['message'];
        $this->lostPhone = (string) $defaults['phone'];
        $this->lostAddress = (string) $defaults['address'];
    }

    private function resetSensitiveFields(): void
    {
        $this->resetPasscode = '';
        $this->wipePassword = '';
        $this->wipeAcknowledge = false;
        $this->wipePreserveFrp = true;
        $this->wipeExternalStorage = false;
        $this->prefillLostMode();
    }
}
