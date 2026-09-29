<?php

namespace App\Services\Assets\Mdm;

use App\Jobs\Assets\Mdm\SendMdmCommand;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Remote actions on a phone.
 *
 * Every action is a local mdm_device_commands row (requested → sent → acknowledged | failed) that is delivered to
 * Google by the SendMdmCommand queued job — never from a Livewire request. Region scope and permission are checked
 * when the command is requested and again when the job delivers it.
 *
 * Wipe is the odd one out on purpose: it is devices.delete (which removes the device from the enterprise and
 * factory-resets it), executed by deliverWipe(), not devices.issueCommand. AMAPI also has a WIPE command type; the
 * ERP does not use it (see docs/assets/mdm.md).
 */
class CommandService
{
    public const COMMAND_TYPES = [
        MdmDeviceCommand::TYPE_LOCK,
        MdmDeviceCommand::TYPE_REBOOT,
        MdmDeviceCommand::TYPE_RESET_PASSWORD,
        MdmDeviceCommand::TYPE_START_LOST_MODE,
        MdmDeviceCommand::TYPE_STOP_LOST_MODE,
    ];

    public function __construct(
        private readonly AndroidManagementGateway $gateway,
        private readonly MdmSettings $settings,
        private readonly MdmAccessGuard $guard,
    ) {}

    /**
     * Queue a LOCK / REBOOT / RESET_PASSWORD / START_LOST_MODE / STOP_LOST_MODE.
     *
     * @param  array<string, mixed>  $params  start lost mode: message, phone, address (each falls back to config); reset password: new_password
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the device is outside the actor's scope
     * @throws ValidationException
     */
    public function request(User $actor, MdmDevice $device, string $type, array $params = []): MdmDeviceCommand
    {
        if (! in_array($type, self::COMMAND_TYPES, true)) {
            throw ValidationException::withMessages(['command' => 'Unknown command.']);
        }

        $this->authorize($actor, $device, 'assets.mdm_command');
        $this->assertCommandable($device);

        $payload = $this->payloadFor($type, $params);

        return $this->queue($actor, $device, $type, $payload, 'mdm_command_requested', $this->auditContext($type, $payload));
    }

    /**
     * Queue a wipe. Needs assets.mdm_wipe and the actor's own password (checked here, rate limited), and never
     * happens automatically.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function requestWipe(User $actor, MdmDevice $device, string $password, bool $preserveResetProtection = true, bool $wipeExternalStorage = false): MdmDeviceCommand
    {
        $this->authorize($actor, $device, 'assets.mdm_wipe');

        if ($device->isDeleted()) {
            throw ValidationException::withMessages(['command' => 'This device has already been removed from management.']);
        }

        $this->confirmPassword($actor, $password);

        $flags = array_values(array_filter([
            $preserveResetProtection ? 'PRESERVE_RESET_PROTECTION_DATA' : null,
            $wipeExternalStorage ? 'WIPE_EXTERNAL_STORAGE' : null,
        ]));

        return $this->queue($actor, $device, MdmDeviceCommand::TYPE_WIPE, ['wipe_data_flags' => $flags], 'mdm_wipe_requested', [
            'wipe_data_flags' => $flags,
        ]);
    }

    /**
     * Called by SendMdmCommand. Idempotent: only a command still in `requested` is sent, so a retried job cannot
     * issue the same command twice once Google has accepted it.
     *
     * @throws MdmGatewayException for transient failures, so the job is retried
     */
    public function deliver(int $commandId): void
    {
        $command = MdmDeviceCommand::query()->with(['device', 'requester'])->find($commandId);

        if (! $command || $command->status !== MdmDeviceCommand::STATUS_REQUESTED) {
            return;
        }

        $device = $command->device;
        $actor = $command->requester;
        $permission = $command->type === MdmDeviceCommand::TYPE_WIPE ? 'assets.mdm_wipe' : 'assets.mdm_command';

        // Re-checked at delivery: the queue can lag, and the requester's role, region or account may have changed.
        if (! $actor || ! $actor->is_active || ! $this->guard->has($actor, $permission) || ! $this->guard->canAccessDevice($actor, $device)) {
            $this->fail($command, 'The requesting user is no longer allowed to send this command to this device.');

            return;
        }

        if ($device->isDeleted()) {
            $this->fail($command, 'The device has already been removed from management.');

            return;
        }

        try {
            $command->type === MdmDeviceCommand::TYPE_WIPE
                ? $this->deliverWipe($command, $device)
                : $this->deliverCommand($command, $device);
        } catch (MdmGatewayException $e) {
            if ($e->isTransient()) {
                throw $e;
            }

            $this->fail($command, $e->getMessage());
        }
    }

    /** Called when a delivery job has exhausted its retries. */
    public function markFailed(int $commandId, string $error): void
    {
        $command = MdmDeviceCommand::query()->find($commandId);

        if ($command && ! $command->isFinished()) {
            $this->fail($command, $error);
        }
    }

    /**
     * A COMMAND notification: an Operation whose `done` flag / `error` says how the command ended on the device.
     * Operations that were not issued through the ERP are ignored.
     */
    public function applyOperation(array $operation): ?MdmDeviceCommand
    {
        $name = $operation['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $command = MdmDeviceCommand::query()->with('device')->where('google_operation_name', $name)->first();

        if (! $command || $command->isFinished()) {
            return $command;
        }

        $errorMessage = $operation['error']['message'] ?? null;
        $errorCode = $operation['metadata']['errorCode'] ?? null;

        if ($errorMessage || ($errorCode && $errorCode !== 'COMMAND_ERROR_CODE_UNSPECIFIED')) {
            $this->fail($command, $errorMessage ?: 'The device reported error '.$errorCode.'.');

            return $command;
        }

        if (($operation['done'] ?? false) === true) {
            $this->acknowledge($command);
        }

        return $command;
    }

    /** Poll Google for an operation that has been sent but not yet reported done (used by the safety-net resync). */
    public function refreshPending(MdmDeviceCommand $command): MdmDeviceCommand
    {
        if ($command->status !== MdmDeviceCommand::STATUS_SENT || ! $command->google_operation_name) {
            return $command;
        }

        $this->applyOperation($this->gateway->getOperation($command->google_operation_name) + ['name' => $command->google_operation_name]);

        return $command->refresh();
    }

    private function deliverCommand(MdmDeviceCommand $command, MdmDevice $device): void
    {
        $operation = $this->gateway->issueCommand($device->google_device_name, $this->commandBody($command));

        $command->forceFill([
            'status' => MdmDeviceCommand::STATUS_SENT,
            'google_operation_name' => $operation['name'] ?? null,
            'sent_at' => now(),
            // The passcode was only needed to build the request.
            'payload' => $this->withoutSecrets($command->payload),
        ])->save();

        Audit::log('mdm_command_sent', Permission::MODULE_ASSETS, 'mdm_devices', $device->id, [
            'command_id' => $command->id,
            'type' => $command->type,
            'operation' => $operation['name'] ?? null,
            'actor_id' => $command->requested_by,
        ]);

        if (($operation['done'] ?? false) === true && empty($operation['error'])) {
            $this->acknowledge($command);
        }
    }

    /** devices.delete — not issueCommand. */
    private function deliverWipe(MdmDeviceCommand $command, MdmDevice $device): void
    {
        $flags = $command->payload['wipe_data_flags'] ?? [];

        try {
            $this->gateway->deleteDevice($device->google_device_name, $flags);
        } catch (MdmGatewayException $e) {
            // Already gone from Google: the goal (an unmanaged, wiped-or-wiping device) is met.
            if (! $e->isNotFound()) {
                throw $e;
            }
        }

        $command->forceFill(['status' => MdmDeviceCommand::STATUS_SENT, 'sent_at' => now()])->save();

        // Google answers devices.delete with an empty body and never reports a separate acknowledgement, so
        // "accepted by Google" is the last thing the ERP can know.
        $this->acknowledge($command);

        Audit::log('mdm_wipe_sent', Permission::MODULE_ASSETS, 'mdm_devices', $device->id, [
            'command_id' => $command->id,
            'wipe_data_flags' => $flags,
            'actor_id' => $command->requested_by,
        ]);
    }

    private function acknowledge(MdmDeviceCommand $command): void
    {
        $command->forceFill(['status' => MdmDeviceCommand::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now(), 'error' => null])->save();

        $device = $command->device;

        match ($command->type) {
            MdmDeviceCommand::TYPE_START_LOST_MODE => $device->forceFill(['is_lost' => true, 'lost_at' => now()])->save(),
            MdmDeviceCommand::TYPE_STOP_LOST_MODE => $device->forceFill(['is_lost' => false, 'lost_at' => null])->save(),
            MdmDeviceCommand::TYPE_WIPE => $device->forceFill(['state' => 'DELETED', 'applied_state' => 'DELETED', 'is_lost' => false])->save(),
            default => null,
        };
    }

    private function fail(MdmDeviceCommand $command, string $error): void
    {
        $command->forceFill([
            'status' => MdmDeviceCommand::STATUS_FAILED,
            'error' => mb_strimwidth($error, 0, 1000, '…'),
            'payload' => $this->withoutSecrets($command->payload),
        ])->save();

        Audit::log('mdm_command_failed', Permission::MODULE_ASSETS, 'mdm_devices', $command->mdm_device_id, [
            'command_id' => $command->id,
            'type' => $command->type,
            'error' => $command->error,
            'actor_id' => $command->requested_by,
        ]);
    }

    /** @return array<string, mixed> */
    private function commandBody(MdmDeviceCommand $command): array
    {
        $payload = $command->payload ?? [];

        $body = [
            'type' => $command->type,
            'duration' => ($this->commandValidMinutes() * 60).'s',
        ];

        if ($command->type === MdmDeviceCommand::TYPE_START_LOST_MODE) {
            $body['startLostModeParams'] = array_filter([
                'lostMessage' => $this->userFacing($payload['message'] ?? null),
                'lostPhoneNumber' => $this->userFacing($payload['phone'] ?? null),
                'lostStreetAddress' => $this->userFacing($payload['address'] ?? null),
            ]);
        }

        if ($command->type === MdmDeviceCommand::TYPE_RESET_PASSWORD) {
            $body['newPassword'] = (string) ($payload['new_password'] ?? '');
            $body['resetPasswordFlags'] = ['REQUIRE_ENTRY'];
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function payloadFor(string $type, array $params): array
    {
        if ($type === MdmDeviceCommand::TYPE_START_LOST_MODE) {
            $defaults = $this->settings->lostModeDefaults();

            $payload = [
                'message' => $this->clean($params['message'] ?? null) ?? $defaults['message'],
                'phone' => $this->clean($params['phone'] ?? null) ?? $defaults['phone'],
                'address' => $this->clean($params['address'] ?? null) ?? $defaults['address'],
            ];

            // Google requires at least one of the lost-mode fields, and a lost phone with nothing on screen is no use.
            if (array_filter($payload) === []) {
                throw ValidationException::withMessages(['message' => 'Enter a message, phone number or address to show on the lost phone.']);
            }

            return $payload;
        }

        if ($type === MdmDeviceCommand::TYPE_RESET_PASSWORD) {
            $password = (string) ($params['new_password'] ?? '');

            if (mb_strlen($password) < 6 || mb_strlen($password) > 64) {
                throw ValidationException::withMessages(['new_password' => 'The new passcode must be between 6 and 64 characters.']);
            }

            return ['new_password' => $password];
        }

        return [];
    }

    /**
     * What may appear in the audit trail: never the passcode.
     *
     * @return array<string, mixed>
     */
    private function auditContext(string $type, array $payload): array
    {
        return match ($type) {
            MdmDeviceCommand::TYPE_START_LOST_MODE => [
                'has_message' => filled($payload['message'] ?? null),
                'has_phone' => filled($payload['phone'] ?? null),
                'has_address' => filled($payload['address'] ?? null),
            ],
            MdmDeviceCommand::TYPE_RESET_PASSWORD => ['passcode' => 'not logged'],
            default => [],
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function queue(User $actor, MdmDevice $device, string $type, array $payload, string $auditAction, array $auditContext): MdmDeviceCommand
    {
        $this->hitRateLimit($actor);

        return DB::transaction(function () use ($actor, $device, $type, $payload, $auditAction, $auditContext) {
            $command = MdmDeviceCommand::query()->create([
                'mdm_device_id' => $device->id,
                'type' => $type,
                'payload' => $payload ?: null,
                'status' => MdmDeviceCommand::STATUS_REQUESTED,
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            Audit::log($auditAction, Permission::MODULE_ASSETS, 'mdm_devices', $device->id, [
                'command_id' => $command->id,
                'type' => $type,
                'asset_id' => $device->ict_asset_id,
                'actor_id' => $actor->id,
                ...$auditContext,
            ]);

            SendMdmCommand::dispatch($command->id);

            return $command;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    private function authorize(User $actor, MdmDevice $device, string $permission): void
    {
        if (! $this->guard->has($actor, $permission)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }

        if (! $this->guard->canAccessDevice($actor, $device)) {
            // Same outcome as an id that does not exist: a tampered id must not reveal another region's phone.
            throw (new ModelNotFoundException)->setModel(MdmDevice::class, [$device->getKey()]);
        }
    }

    /** Lock, reboot, lost mode and the rest are refused for a device whose identity is unconfirmed (wipe is not). */
    private function assertCommandable(MdmDevice $device): void
    {
        if ($device->isDeleted()) {
            throw ValidationException::withMessages(['command' => 'This device has been removed from management.']);
        }

        if ($device->needs_review) {
            throw ValidationException::withMessages(['command' => 'This phone’s identity has not been confirmed yet. An administrator must review it before commands can be sent.']);
        }
    }

    private function hitRateLimit(User $actor): void
    {
        $key = 'mdm-command:'.$actor->id;
        $max = max(1, (int) config('gwl.mdm_command_rate_per_minute', 6));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                'command' => 'You are sending commands too quickly. Wait '.RateLimiter::availableIn($key).' seconds and try again.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    /** The wipe password check, throttled separately so it cannot be used to guess a password. */
    private function confirmPassword(User $actor, string $password): void
    {
        $key = 'mdm-wipe-password:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => 'Too many incorrect attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if ($password === '' || ! Hash::check($password, (string) $actor->getAuthPassword())) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        RateLimiter::clear($key);
    }

    private function commandValidMinutes(): int
    {
        return max(1, (int) config('gwl.mdm_command_valid_minutes', 60));
    }

    /** @return array{defaultMessage: string}|null */
    private function userFacing(?string $text): ?array
    {
        return filled($text) ? ['defaultMessage' => $text] : null;
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** @param  array<string, mixed>|null  $payload */
    private function withoutSecrets(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        unset($payload['new_password']);

        return $payload ?: null;
    }
}
