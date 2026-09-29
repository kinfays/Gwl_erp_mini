<?php

namespace Tests\Support\Mdm;

use App\Services\Assets\Mdm\AndroidManagementGateway;
use Closure;
use Throwable;

/**
 * In-memory stand-in for Google. Records every call (so tests can assert what was and was not sent) and returns
 * canned responses. Nothing in the suite may reach the network: the container binds this in place of the real client.
 */
class FakeAndroidManagementGateway implements AndroidManagementGateway
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> device resources by Google device name (getDevice / listDevices) */
    public array $devices = [];

    /** @var list<list<array<string, mixed>>> batches handed out by successive pullMessages() calls */
    public array $pullBatches = [];

    /** @var list<string> */
    public array $acknowledged = [];

    /** Runs at the moment messages are acknowledged, so a test can inspect what had been stored by then. */
    public ?Closure $onAcknowledge = null;

    /** @var array<string, Throwable> exception to throw from a named method */
    public array $failures = [];

    /** @var array<string, array<string, mixed>> operations by name, for getOperation() */
    public array $operations = [];

    private int $sequence = 0;

    public function createSignupUrl(string $projectId, string $callbackUrl): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return ['name' => 'signupUrls/fake-'.++$this->sequence, 'url' => 'https://enterprise.google.example/signup?token=fake'];
    }

    public function createEnterprise(string $projectId, string $signupUrlName, string $enterpriseToken, array $enterprise): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $enterprise + ['name' => 'enterprises/LC0fake'.++$this->sequence];
    }

    public function patchEnterprise(string $enterpriseName, array $enterprise, string $updateMask): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $enterprise + ['name' => $enterpriseName];
    }

    public function patchPolicy(string $policyName, array $policy, string $updateMask): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $policy + ['name' => $policyName];
    }

    public function createEnrollmentToken(string $enterpriseName, array $token): array
    {
        $this->record(__FUNCTION__, func_get_args());

        $seconds = (int) rtrim($token['duration'] ?? '3600s', 's');
        $id = 'tok-'.++$this->sequence;

        return $token + [
            'name' => $enterpriseName.'/enrollmentTokens/'.$id,
            'value' => 'SECRETVALUE'.$id,
            'expirationTimestamp' => now()->addSeconds($seconds)->utc()->format('Y-m-d\TH:i:s\Z'),
            'qrCode' => json_encode(['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => 'com.google.android.apps.work.clouddpc/.receivers.CloudDeviceAdminReceiver', 'token' => $id]),
        ];
    }

    public function getDevice(string $deviceName): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->devices[$deviceName] ?? ['name' => $deviceName];
    }

    public function listDevices(string $enterpriseName): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return array_values($this->devices);
    }

    public function patchDevice(string $deviceName, array $device, string $updateMask): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $device + ['name' => $deviceName];
    }

    public function issueCommand(string $deviceName, array $command): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return ['name' => $deviceName.'/operations/'.++$this->sequence, 'done' => false];
    }

    public function getOperation(string $operationName): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->operations[$operationName] ?? ['name' => $operationName, 'done' => false];
    }

    public function deleteDevice(string $deviceName, array $wipeDataFlags = []): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function pullMessages(string $subscription, int $maxMessages): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return array_shift($this->pullBatches) ?? [];
    }

    public function acknowledgeMessages(string $subscription, array $ackIds): void
    {
        $this->record(__FUNCTION__, func_get_args());

        if ($this->onAcknowledge) {
            ($this->onAcknowledge)($ackIds);
        }

        array_push($this->acknowledged, ...$ackIds);
    }

    /** @return list<array<int, mixed>> the argument lists of every call to $method */
    public function calls(string $method): array
    {
        return array_values(array_map(fn ($call) => $call['args'], array_filter($this->calls, fn ($call) => $call['method'] === $method)));
    }

    public function called(string $method): bool
    {
        return $this->calls($method) !== [];
    }

    /** Build one Pub/Sub message in the shape the gateway returns from pullMessages(). */
    public static function message(string $messageId, string $type, array $payload, ?string $ackId = null): array
    {
        return [
            'ackId' => $ackId ?? 'ack-'.$messageId,
            'messageId' => $messageId,
            'data' => base64_encode(json_encode($payload)),
            'attributes' => ['notificationType' => $type],
            'publishTime' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @param  array<int, mixed>  $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }
}
