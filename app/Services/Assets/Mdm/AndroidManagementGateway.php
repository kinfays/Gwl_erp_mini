<?php

namespace App\Services\Assets\Mdm;

/**
 * Everything the ERP asks of Google — the Android Management API and the Pub/Sub pull endpoints — behind one
 * interface, so tests bind a fake and no test ever touches the network. Every method takes and returns plain
 * arrays in the shape of Google's JSON (camelCase), and throws MdmGatewayException on failure.
 */
interface AndroidManagementGateway
{
    /** @return array{name: string, url: string} */
    public function createSignupUrl(string $projectId, string $callbackUrl): array;

    /** @return array<string, mixed> the created Enterprise resource */
    public function createEnterprise(string $projectId, string $signupUrlName, string $enterpriseToken, array $enterprise): array;

    /** @return array<string, mixed> */
    public function patchEnterprise(string $enterpriseName, array $enterprise, string $updateMask): array;

    /** @return array<string, mixed> the stored Policy resource */
    public function patchPolicy(string $policyName, array $policy, string $updateMask): array;

    /** @return array<string, mixed> the EnrollmentToken resource, including `qrCode` and `expirationTimestamp` */
    public function createEnrollmentToken(string $enterpriseName, array $token): array;

    /** @return array<string, mixed> */
    public function getDevice(string $deviceName): array;

    /** @return list<array<string, mixed>> every device of the enterprise (all pages) */
    public function listDevices(string $enterpriseName): array;

    /** @return array<string, mixed> */
    public function patchDevice(string $deviceName, array $device, string $updateMask): array;

    /** @return array<string, mixed> the Operation created for the command */
    public function issueCommand(string $deviceName, array $command): array;

    /** @return array<string, mixed> the Operation resource */
    public function getOperation(string $operationName): array;

    /**
     * devices.delete: removes the device from the enterprise and attempts to factory-reset it.
     *
     * @param  list<string>  $wipeDataFlags  PRESERVE_RESET_PROTECTION_DATA | WIPE_EXTERNAL_STORAGE | WIPE_ESIMS
     */
    public function deleteDevice(string $deviceName, array $wipeDataFlags = []): void;

    /**
     * Pub/Sub pull. Each message is ['ackId', 'messageId', 'data' (base64), 'attributes', 'publishTime'].
     *
     * @return list<array<string, mixed>>
     */
    public function pullMessages(string $subscription, int $maxMessages): array;

    /** @param  list<string>  $ackIds */
    public function acknowledgeMessages(string $subscription, array $ackIds): void;
}
