<?php

namespace Tests\Feature\Assets\Mdm;

use App\Services\Assets\Mdm\AndroidManagementClient;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use App\Services\Assets\Mdm\Exceptions\MdmNotConfiguredException;
use Google\Auth\Cache\MemoryCacheItemPool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The real AndroidManagementClient, driven through a Guzzle MockHandler: no network, but the real Google library
 * builds and serialises every request, so this checks the URLs, query parameters, JSON bodies and the conversion of
 * responses and errors — the one layer the fake gateway used everywhere else cannot cover.
 */
class AndroidManagementClientTest extends TestCase
{
    private const TOKEN = ['access_token' => 'fake-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer'];

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private ?string $credentialsFile = null;

    protected function tearDown(): void
    {
        if ($this->credentialsFile && is_file($this->credentialsFile)) {
            unlink($this->credentialsFile);
        }

        parent::tearDown();
    }

    public function test_it_refuses_to_run_without_a_credentials_file_and_says_which_setting_is_missing(): void
    {
        config(['gwl.mdm_credentials_path' => null]);
        $this->expectException(MdmNotConfiguredException::class);
        $this->expectExceptionMessage('GOOGLE_APPLICATION_CREDENTIALS is not set');

        (new AndroidManagementClient)->getDevice('enterprises/E/devices/d');
    }

    public function test_a_credentials_path_that_does_not_exist_is_reported_without_echoing_the_path_contents(): void
    {
        config(['gwl.mdm_credentials_path' => 'C:\\nowhere\\missing.json']);

        try {
            (new AndroidManagementClient)->getDevice('enterprises/E/devices/d');
            $this->fail('Expected a configuration error.');
        } catch (MdmNotConfiguredException $e) {
            $this->assertStringContainsString('readable file', $e->getMessage());
        }
    }

    public function test_policies_are_patched_with_the_update_mask_and_a_json_body(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['name' => 'enterprises/E/policies/p1', 'version' => '4']))]);

        $result = $client->patchPolicy('enterprises/E/policies/p1', [
            'playStoreMode' => 'WHITELIST',
            'installAppsDisabled' => true,
            'factoryResetDisabled' => false,
            'applications' => [['packageName' => 'com.whatsapp.w4b', 'installType' => 'FORCE_INSTALLED']],
            'frpAdminEmails' => ['it@gwcl.example'],
            'deviceConnectivityManagement' => ['usbDataAccess' => 'DISALLOW_USB_FILE_TRANSFER'],
            'statusReportingSettings' => ['networkInfoEnabled' => true],
        ], 'applications,playStoreMode');

        $request = $this->lastApiRequest();
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('/v1/enterprises/E/policies/p1', $request->getUri()->getPath());
        $this->assertSame('androidmanagement.googleapis.com', $request->getUri()->getHost());
        $this->assertStringContainsString('updateMask=applications%2CplayStoreMode', $request->getUri()->getQuery());
        $this->assertSame('Bearer fake-access-token', $request->getHeaderLine('Authorization'));

        $this->assertSame('WHITELIST', $body['playStoreMode']);
        $this->assertTrue($body['installAppsDisabled']);
        $this->assertFalse($body['factoryResetDisabled'], 'false must be sent, not dropped');
        $this->assertEquals([['packageName' => 'com.whatsapp.w4b', 'installType' => 'FORCE_INSTALLED']], $body['applications']);
        $this->assertSame(['it@gwcl.example'], $body['frpAdminEmails']);
        $this->assertSame(['usbDataAccess' => 'DISALLOW_USB_FILE_TRANSFER'], $body['deviceConnectivityManagement']);
        $this->assertSame(['networkInfoEnabled' => true], $body['statusReportingSettings']);

        $this->assertSame('enterprises/E/policies/p1', $result['name'], 'responses come back as plain arrays');
    }

    public function test_an_emptied_frp_list_is_sent_as_an_empty_array_so_it_clears_on_google(): void
    {
        $client = $this->client([new Response(200, [], '{}')]);

        $client->patchPolicy('enterprises/E/policies/p1', ['frpAdminEmails' => []], 'frpAdminEmails');

        $this->assertSame([], json_decode((string) $this->lastApiRequest()->getBody(), true)['frpAdminEmails']);
    }

    public function test_enrollment_tokens_are_created_under_the_enterprise_with_the_expected_fields(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            'name' => 'enterprises/E/enrollmentTokens/t1',
            'value' => 'TOKENVALUE',
            'qrCode' => '{"a":"b"}',
            'expirationTimestamp' => '2026-09-29T10:00:00Z',
        ]))]);

        $token = $client->createEnrollmentToken('enterprises/E', [
            'policyName' => 'enterprises/E/policies/p1',
            'duration' => '3600s',
            'oneTimeOnly' => true,
            'allowPersonalUsage' => 'PERSONAL_USAGE_DISALLOWED',
            'additionalData' => '{"asset_id":7}',
        ]);

        $request = $this->lastApiRequest();
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/enterprises/E/enrollmentTokens', $request->getUri()->getPath());
        $this->assertSame('3600s', $body['duration']);
        $this->assertTrue($body['oneTimeOnly']);
        $this->assertSame('PERSONAL_USAGE_DISALLOWED', $body['allowPersonalUsage']);
        $this->assertSame('{"asset_id":7}', $body['additionalData']);
        $this->assertSame('{"a":"b"}', $token['qrCode']);
        $this->assertSame('2026-09-29T10:00:00Z', $token['expirationTimestamp']);
    }

    public function test_commands_go_to_the_device_issue_command_url_with_nested_lost_mode_params(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['name' => 'enterprises/E/devices/d1/operations/9', 'done' => false]))]);

        $operation = $client->issueCommand('enterprises/E/devices/d1', [
            'type' => 'START_LOST_MODE',
            'duration' => '3600s',
            'startLostModeParams' => ['lostMessage' => ['defaultMessage' => 'Call ICT'], 'lostPhoneNumber' => ['defaultMessage' => '0302000000']],
        ]);

        $request = $this->lastApiRequest();
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/enterprises/E/devices/d1:issueCommand', $request->getUri()->getPath());
        $this->assertSame('START_LOST_MODE', $body['type']);
        $this->assertSame(['defaultMessage' => 'Call ICT'], $body['startLostModeParams']['lostMessage']);
        $this->assertSame('enterprises/E/devices/d1/operations/9', $operation['name']);
    }

    public function test_wipe_is_a_delete_with_repeated_wipe_data_flag_parameters(): void
    {
        $client = $this->client([new Response(200, [], '{}')]);

        $client->deleteDevice('enterprises/E/devices/d1', ['PRESERVE_RESET_PROTECTION_DATA', 'WIPE_EXTERNAL_STORAGE']);

        $request = $this->lastApiRequest();

        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/v1/enterprises/E/devices/d1', $request->getUri()->getPath());
        $this->assertSame(
            ['PRESERVE_RESET_PROTECTION_DATA', 'WIPE_EXTERNAL_STORAGE'],
            $this->queryValues($request, 'wipeDataFlags'),
            'each flag is its own wipeDataFlags parameter'
        );
    }

    public function test_a_delete_without_flags_sends_no_flag_parameter(): void
    {
        $client = $this->client([new Response(200, [], '{}')]);

        $client->deleteDevice('enterprises/E/devices/d1');

        $this->assertSame([], $this->queryValues($this->lastApiRequest(), 'wipeDataFlags'));
    }

    public function test_devices_are_listed_across_every_page(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['devices' => [['name' => 'enterprises/E/devices/a'], ['name' => 'enterprises/E/devices/b']], 'nextPageToken' => 'PAGE2'])),
            new Response(200, [], json_encode(['devices' => [['name' => 'enterprises/E/devices/c']]])),
        ]);

        $devices = $client->listDevices('enterprises/E');

        $this->assertSame(['enterprises/E/devices/a', 'enterprises/E/devices/b', 'enterprises/E/devices/c'], array_column($devices, 'name'));

        $apiRequests = $this->apiRequests();
        $this->assertSame('/v1/enterprises/E/devices', $apiRequests[0]->getUri()->getPath());
        $this->assertStringNotContainsString('pageToken', $apiRequests[0]->getUri()->getQuery());
        $this->assertStringContainsString('pageToken=PAGE2', $apiRequests[1]->getUri()->getQuery());
    }

    public function test_an_empty_device_list_is_an_empty_array(): void
    {
        $this->assertSame([], $this->client([new Response(200, [], '{}')])->listDevices('enterprises/E'));
    }

    public function test_signup_and_enterprise_creation_use_the_project_and_the_signup_url_name(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['name' => 'signupUrls/S1', 'url' => 'https://enterprise.google.com/signup/S1'])),
            new Response(200, [], json_encode(['name' => 'enterprises/LC0new'])),
        ]);

        $signup = $client->createSignupUrl('gwl-project', 'https://erp.example.test/assets/mdm/enterprise/callback');
        $enterprise = $client->createEnterprise('gwl-project', 'signupUrls/S1', 'ENT-TOKEN', [
            'enterpriseDisplayName' => 'GWL',
            'pubsubTopic' => 'projects/gwl-project/topics/amapi',
            'enabledNotificationTypes' => ['ENROLLMENT', 'STATUS_REPORT'],
        ]);

        [$first, $second] = $this->apiRequests();

        $this->assertSame('/v1/signupUrls', $first->getUri()->getPath());
        $this->assertSame('gwl-project', $this->queryValues($first, 'projectId')[0]);
        $this->assertSame('https://erp.example.test/assets/mdm/enterprise/callback', $this->queryValues($first, 'callbackUrl')[0]);
        $this->assertSame('signupUrls/S1', $signup['name']);

        $this->assertSame('/v1/enterprises', $second->getUri()->getPath());
        $this->assertSame(['gwl-project'], $this->queryValues($second, 'projectId'));
        $this->assertSame(['signupUrls/S1'], $this->queryValues($second, 'signupUrlName'));
        $this->assertSame(['ENT-TOKEN'], $this->queryValues($second, 'enterpriseToken'));
        $body = json_decode((string) $second->getBody(), true);
        $this->assertSame('projects/gwl-project/topics/amapi', $body['pubsubTopic']);
        $this->assertSame(['ENROLLMENT', 'STATUS_REPORT'], $body['enabledNotificationTypes']);
        $this->assertSame('enterprises/LC0new', $enterprise['name']);
    }

    public function test_pubsub_pull_returns_flat_messages_and_acknowledge_posts_the_ack_ids(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['receivedMessages' => [[
                'ackId' => 'ACK1',
                'message' => ['data' => base64_encode('{"name":"d"}'), 'attributes' => ['notificationType' => 'STATUS_REPORT'], 'messageId' => '111', 'publishTime' => '2026-09-29T08:00:00Z'],
            ]]])),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]);

        $messages = $client->pullMessages('projects/gwl-project/subscriptions/sub', 25);
        $client->acknowledgeMessages('projects/gwl-project/subscriptions/sub', ['ACK1']);
        $client->acknowledgeMessages('projects/gwl-project/subscriptions/sub', []);

        $this->assertSame([[
            'ackId' => 'ACK1',
            'messageId' => '111',
            'data' => base64_encode('{"name":"d"}'),
            'attributes' => ['notificationType' => 'STATUS_REPORT'],
            'publishTime' => '2026-09-29T08:00:00Z',
        ]], $messages);

        [$pull, $ack] = $this->apiRequests();

        $this->assertSame('pubsub.googleapis.com', $pull->getUri()->getHost());
        $this->assertSame('/v1/projects/gwl-project/subscriptions/sub:pull', $pull->getUri()->getPath());
        $pullBody = json_decode((string) $pull->getBody(), true);
        $this->assertSame(25, $pullBody['maxMessages']);
        $this->assertTrue($pullBody['returnImmediately']);

        $this->assertSame('/v1/projects/gwl-project/subscriptions/sub:acknowledge', $ack->getUri()->getPath());
        $this->assertSame(['ACK1'], json_decode((string) $ack->getBody(), true)['ackIds']);

        $this->assertCount(2, $this->apiRequests(), 'acknowledging nothing must not call Google at all');
    }

    public function test_an_empty_pull_returns_no_messages(): void
    {
        $this->assertSame([], $this->client([new Response(200, [], '{}')])->pullMessages('projects/p/subscriptions/s', 10));
    }

    public function test_the_whole_request_carries_the_androidmanagement_and_pubsub_scopes(): void
    {
        $this->client([new Response(200, [], '{}')])->getDevice('enterprises/E/devices/d');

        $tokenRequest = $this->history[0]['request'];
        parse_str((string) $tokenRequest->getBody(), $form);
        $assertion = explode('.', $form['assertion']);
        $claims = json_decode(base64_decode(strtr($assertion[1], '-_', '+/')), true);

        $this->assertStringContainsString('https://www.googleapis.com/auth/androidmanagement', $claims['scope']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/pubsub', $claims['scope']);
        $this->assertSame('mdm-test@gwl-test.iam.gserviceaccount.com', $claims['iss']);
    }

    public function test_google_errors_become_gateway_exceptions_with_the_status_and_the_google_message(): void
    {
        $error = static fn (int $code, string $message) => new Response($code, [], json_encode(['error' => ['code' => $code, 'message' => $message, 'status' => 'X']]));

        $client = $this->client([$error(404, 'Device not found.'), $error(403, 'The caller does not have permission'), $error(503, 'Backend unavailable')]);

        try {
            $client->getDevice('enterprises/E/devices/gone');
            $this->fail('Expected an exception.');
        } catch (MdmGatewayException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertTrue($e->isNotFound());
            $this->assertFalse($e->isTransient());
            $this->assertStringContainsString('Device not found.', $e->getMessage());
        }

        try {
            $client->getDevice('enterprises/E/devices/d');
            $this->fail('Expected an exception.');
        } catch (MdmGatewayException $e) {
            $this->assertSame(403, $e->httpStatus);
            $this->assertFalse($e->isTransient(), 'a permission problem will not fix itself on retry');
        }

        try {
            $client->getDevice('enterprises/E/devices/d');
            $this->fail('Expected an exception.');
        } catch (MdmGatewayException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_a_network_failure_is_a_transient_gateway_exception_and_never_leaks_credentials(): void
    {
        $client = $this->client([new ConnectException('cURL error 6: Could not resolve host', new Request('GET', 'https://androidmanagement.googleapis.com'))]);

        try {
            $client->getDevice('enterprises/E/devices/d');
            $this->fail('Expected an exception.');
        } catch (MdmGatewayException $e) {
            $this->assertSame(0, $e->httpStatus);
            $this->assertTrue($e->isTransient());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            $this->assertStringNotContainsString('fake-access-token', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @param  list<Response|\Throwable>  $responses  queued after the OAuth token response */
    private function client(array $responses): AndroidManagementClient
    {
        $this->credentialsFile = $this->writeCredentials();
        config(['gwl.mdm_credentials_path' => $this->credentialsFile]);

        $mock = new MockHandler([new Response(200, [], json_encode(self::TOKEN)), ...$responses]);

        // Guzzle runs middleware in the order it was added, so anything added before Google's own auth middleware sees a
        // request *before* the bearer token is attached. Recording in the handler itself — the last stop — captures
        // each request exactly as it would go on the wire.
        $recorder = function (RequestInterface $request, array $options) use ($mock) {
            $this->history[] = ['request' => $request];

            return $mock($request, $options);
        };

        $stack = HandlerStack::create($recorder);

        return new class(new Client(['handler' => $stack])) extends AndroidManagementClient
        {
            public function __construct(private readonly Client $mock) {}

            protected function httpClient(): Client
            {
                return $this->mock;
            }

            protected function tokenCache(): ?CacheItemPoolInterface
            {
                return new MemoryCacheItemPool;
            }
        };
    }

    /** A syntactically valid service-account file with a throwaway key; it only ever signs a request the mock handler answers. */
    private function writeCredentials(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $config = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

        $key = openssl_pkey_new($options) ?: (is_file($config) ? openssl_pkey_new($options + ['config' => $config]) : false);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot generate an RSA key here (no openssl.cnf).');
        }

        if (! openssl_pkey_export($key, $pem) && ! openssl_pkey_export($key, $pem, null, ['config' => $config])) {
            $this->markTestSkipped('OpenSSL cannot export the RSA key here.');
        }

        $file = tempnam(sys_get_temp_dir(), 'gwl-sa-');
        file_put_contents($file, json_encode([
            'type' => 'service_account',
            'project_id' => 'gwl-test',
            'private_key_id' => 'kid1',
            'private_key' => $pem,
            'client_email' => 'mdm-test@gwl-test.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        return $file;
    }

    /** @return list<RequestInterface> every request except the OAuth token exchange */
    private function apiRequests(): array
    {
        return array_values(array_filter(
            array_map(fn ($entry) => $entry['request'], $this->history),
            fn (RequestInterface $request) => $request->getUri()->getHost() !== 'oauth2.googleapis.com'
        ));
    }

    private function lastApiRequest(): RequestInterface
    {
        $requests = $this->apiRequests();

        return $requests[array_key_last($requests)];
    }

    /** @return list<string> every value of a (possibly repeated) query parameter */
    private function queryValues(RequestInterface $request, string $name): array
    {
        $values = [];

        foreach (explode('&', $request->getUri()->getQuery()) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (urldecode($key) === $name) {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }
}
