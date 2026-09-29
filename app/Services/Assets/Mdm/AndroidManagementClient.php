<?php

namespace App\Services\Assets\Mdm;

use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use App\Services\Assets\Mdm\Exceptions\MdmNotConfiguredException;
use Google\Client as GoogleClient;
use Google\Service\AndroidManagement;
use Google\Service\AndroidManagement\Command;
use Google\Service\AndroidManagement\Device;
use Google\Service\AndroidManagement\EnrollmentToken;
use Google\Service\AndroidManagement\Enterprise;
use Google\Service\AndroidManagement\Policy;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Pubsub;
use Google\Service\Pubsub\AcknowledgeRequest;
use Google\Service\Pubsub\PullRequest;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * The only class that talks to Google. It builds one authenticated client from the service-account JSON on
 * first use (so anything that never reaches Google — including every test, which binds a fake gateway — never
 * needs credentials) and converts Google's model objects to plain arrays.
 *
 * The credentials file's contents are never logged or included in an exception message.
 */
class AndroidManagementClient implements AndroidManagementGateway
{
    public const SCOPE_ANDROID_MANAGEMENT = 'https://www.googleapis.com/auth/androidmanagement';

    public const SCOPE_PUBSUB = 'https://www.googleapis.com/auth/pubsub';

    private ?GoogleClient $client = null;

    private ?AndroidManagement $amapi = null;

    private ?Pubsub $pubsub = null;

    public function createSignupUrl(string $projectId, string $callbackUrl): array
    {
        return $this->call(fn () => $this->amapi()->signupUrls->create([
            'projectId' => $projectId,
            'callbackUrl' => $callbackUrl,
        ]));
    }

    public function createEnterprise(string $projectId, string $signupUrlName, string $enterpriseToken, array $enterprise): array
    {
        return $this->call(fn () => $this->amapi()->enterprises->create(new Enterprise($enterprise), [
            'projectId' => $projectId,
            'signupUrlName' => $signupUrlName,
            'enterpriseToken' => $enterpriseToken,
        ]));
    }

    public function patchEnterprise(string $enterpriseName, array $enterprise, string $updateMask): array
    {
        return $this->call(fn () => $this->amapi()->enterprises->patch($enterpriseName, new Enterprise($enterprise), [
            'updateMask' => $updateMask,
        ]));
    }

    public function patchPolicy(string $policyName, array $policy, string $updateMask): array
    {
        return $this->call(fn () => $this->amapi()->enterprises_policies->patch($policyName, new Policy($policy), [
            'updateMask' => $updateMask,
        ]));
    }

    public function createEnrollmentToken(string $enterpriseName, array $token): array
    {
        return $this->call(fn () => $this->amapi()->enterprises_enrollmentTokens->create($enterpriseName, new EnrollmentToken($token)));
    }

    public function getDevice(string $deviceName): array
    {
        return $this->call(fn () => $this->amapi()->enterprises_devices->get($deviceName));
    }

    public function listDevices(string $enterpriseName): array
    {
        $devices = [];
        $pageToken = null;

        do {
            $page = $this->call(fn () => $this->amapi()->enterprises_devices->listEnterprisesDevices($enterpriseName, array_filter([
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ])));

            foreach ($page['devices'] ?? [] as $device) {
                $devices[] = $device;
            }

            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);

        return $devices;
    }

    public function patchDevice(string $deviceName, array $device, string $updateMask): array
    {
        return $this->call(fn () => $this->amapi()->enterprises_devices->patch($deviceName, new Device($device), [
            'updateMask' => $updateMask,
        ]));
    }

    public function issueCommand(string $deviceName, array $command): array
    {
        return $this->call(fn () => $this->amapi()->enterprises_devices->issueCommand($deviceName, new Command($command)));
    }

    public function getOperation(string $operationName): array
    {
        return $this->call(fn () => $this->amapi()->enterprises_devices_operations->get($operationName));
    }

    public function deleteDevice(string $deviceName, array $wipeDataFlags = []): void
    {
        $this->call(fn () => $this->amapi()->enterprises_devices->delete($deviceName, array_filter([
            'wipeDataFlags' => $wipeDataFlags ?: null,
        ])));
    }

    public function pullMessages(string $subscription, int $maxMessages): array
    {
        // returnImmediately is deprecated by Google but still honoured; without it an empty subscription holds the
        // request open, which would make the every-minute poll overrun into its own withoutOverlapping lock.
        $response = $this->call(fn () => $this->pubsub()->projects_subscriptions->pull($subscription, new PullRequest([
            'maxMessages' => max(1, $maxMessages),
            'returnImmediately' => true,
        ])));

        return array_map(fn (array $received) => [
            'ackId' => $received['ackId'] ?? null,
            'messageId' => $received['message']['messageId'] ?? null,
            'data' => $received['message']['data'] ?? '',
            'attributes' => $received['message']['attributes'] ?? [],
            'publishTime' => $received['message']['publishTime'] ?? null,
        ], $response['receivedMessages'] ?? []);
    }

    public function acknowledgeMessages(string $subscription, array $ackIds): void
    {
        if ($ackIds === []) {
            return;
        }

        $this->call(fn () => $this->pubsub()->projects_subscriptions->acknowledge($subscription, new AcknowledgeRequest([
            'ackIds' => array_values($ackIds),
        ])));
    }

    /**
     * Run a Google call and hand back a plain array; every failure becomes an MdmGatewayException.
     *
     * @return array<string, mixed>
     */
    private function call(callable $request): array
    {
        try {
            $result = $request();
        } catch (GoogleServiceException $e) {
            throw new MdmGatewayException($this->describe($e), (int) $e->getCode(), $e);
        } catch (MdmNotConfiguredException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Network / TLS / auth failures: no HTTP status, so callers treat them as transient.
            throw new MdmGatewayException('Could not reach Google: '.$e->getMessage(), 0, $e);
        }

        if ($result === null) {
            return [];
        }

        if (is_object($result) && method_exists($result, 'toSimpleObject')) {
            return json_decode(json_encode($result->toSimpleObject()), true) ?: [];
        }

        return (array) $result;
    }

    private function describe(GoogleServiceException $e): string
    {
        $decoded = json_decode($e->getMessage(), true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return sprintf('Google API error %d: %s', $e->getCode(), $message ?: mb_strimwidth($e->getMessage(), 0, 300, '…'));
    }

    private function amapi(): AndroidManagement
    {
        return $this->amapi ??= new AndroidManagement($this->client());
    }

    private function pubsub(): Pubsub
    {
        return $this->pubsub ??= new Pubsub($this->client());
    }

    private function client(): GoogleClient
    {
        if ($this->client) {
            return $this->client;
        }

        $path = config('gwl.mdm_credentials_path');

        if (! is_string($path) || $path === '') {
            throw new MdmNotConfiguredException('GOOGLE_APPLICATION_CREDENTIALS is not set. Point it at the service-account JSON (outside the web root).');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new MdmNotConfiguredException('GOOGLE_APPLICATION_CREDENTIALS does not point at a readable file.');
        }

        $client = new GoogleClient;
        $client->setApplicationName('GWL ERP MDM');
        $client->setAuthConfig($path);
        $client->setScopes([self::SCOPE_ANDROID_MANAGEMENT, self::SCOPE_PUBSUB]);
        $client->setHttpClient($this->httpClient());

        if ($cache = $this->tokenCache()) {
            $client->setCache($cache);
        }

        return $this->client = $client;
    }

    /** The HTTP transport. Overridable so the wire format can be tested offline against a mock handler. */
    protected function httpClient(): GuzzleClient
    {
        return new GuzzleClient(['timeout' => 30, 'connect_timeout' => 10]);
    }

    /** null keeps Google's default (a file cache, so access tokens are reused across requests and queue workers). */
    protected function tokenCache(): ?CacheItemPoolInterface
    {
        return null;
    }
}
