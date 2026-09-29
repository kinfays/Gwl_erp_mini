<?php

namespace Tests\Feature\Assets\Mdm;

use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Jobs\Assets\Mdm\ProcessAndroidNotification;
use App\Models\MdmDevice;
use App\Models\MdmEvent;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class PushWebhookTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    private const ENDPOINT = '/webhooks/android-management';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        $this->tokens->claims = $this->validClaims();
    }

    public function test_a_valid_token_is_accepted_stored_and_the_job_dispatched_with_a_204(): void
    {
        Queue::fake();

        $this->deliver($this->message('m-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/d1']))->assertNoContent();

        $event = MdmEvent::query()->where('pubsub_message_id', 'm-1')->firstOrFail();

        $this->assertSame('push', $event->delivery_mode);
        $this->assertSame('STATUS_REPORT', $event->notification_type);
        $this->assertSame('enterprises/LC0test/devices/d1', $event->google_device_name);
        $this->assertSame(['name' => 'enterprises/LC0test/devices/d1'], $event->payload);
        $this->assertNotNull($event->received_at);
        $this->assertNull($event->processed_at);

        Queue::assertPushed(ProcessAndroidNotification::class, fn ($job) => $job->eventId === $event->id);
        Queue::assertPushed(ProcessAndroidNotification::class, 1);
        $this->assertSame(['jwt-from-google'], $this->tokens->seen);
    }

    public function test_the_request_never_calls_google_or_touches_devices(): void
    {
        Queue::fake();

        $this->deliver($this->message('m-2', 'ENROLLMENT', $this->deviceResource('enterprises/LC0test/devices/d2')))->assertNoContent();

        $this->assertSame([], $this->gateway->calls);
        $this->assertSame(0, MdmDevice::query()->count(), 'device work belongs to the queued job');
    }

    public function test_a_command_notification_records_the_device_the_operation_belongs_to(): void
    {
        Queue::fake();

        $this->deliver($this->message('m-3', 'COMMAND', ['name' => 'enterprises/LC0test/devices/d3/operations/42', 'done' => true]))->assertNoContent();

        $this->assertSame('enterprises/LC0test/devices/d3', MdmEvent::query()->firstOrFail()->google_device_name);
    }

    #[DataProvider('rejectedRequests')]
    public function test_a_request_that_fails_authentication_gets_401_and_nothing_is_stored(string $case): void
    {
        Queue::fake();
        $token = 'test-push-secret';
        $bearer = 'jwt-from-google';

        switch ($case) {
            case 'missing bearer': $bearer = null;
                break;
            case 'expired': $this->tokens->claims['exp'] = time() - 10;
                break;
            case 'expired per signature check': $this->tokens->failWith = 'expired';
                break;
            case 'bad signature': $this->tokens->failWith = 'bad_signature';
                break;
            case 'wrong audience': $this->tokens->claims['aud'] = 'https://someone-else.example/hook';
                break;
            case 'wrong email': $this->tokens->claims['email'] = 'attacker@evil.iam.gserviceaccount.com';
                break;
            case 'unverified email': $this->tokens->claims['email_verified'] = false;
                break;
            case 'wrong issuer': $this->tokens->claims['iss'] = 'https://evil.example';
                break;
            case 'wrong query token': $token = 'not-the-secret';
                break;
            case 'missing query token': $token = null;
                break;
        }

        $this->deliver($this->message('m-x', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/d1']), $token, $bearer)
            ->assertUnauthorized();

        $this->assertSame(0, MdmEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public static function rejectedRequests(): array
    {
        return array_map(fn ($case) => [$case], [
            'missing bearer', 'expired', 'expired per signature check', 'bad signature', 'wrong audience',
            'wrong email', 'unverified email', 'wrong issuer', 'wrong query token', 'missing query token',
        ]);
    }

    public function test_an_unconfigured_endpoint_rejects_everything(): void
    {
        Queue::fake();
        config(['gwl.mdm_pubsub_push_token' => '', 'gwl.mdm_pubsub_push_audience' => '']);

        $this->deliver($this->message('m-y', 'STATUS_REPORT', ['name' => 'x']), '')->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_replayed_message_id_answers_204_and_is_processed_once(): void
    {
        Queue::fake();
        $body = $this->message('m-replay', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/d1']);

        $this->deliver($body)->assertNoContent();
        $this->deliver($body)->assertNoContent();
        $this->deliver($body)->assertNoContent();

        $this->assertSame(1, MdmEvent::query()->where('pubsub_message_id', 'm-replay')->count());
        Queue::assertPushed(ProcessAndroidNotification::class, 1);
    }

    public function test_a_replay_is_processed_exactly_once_end_to_end(): void
    {
        // Real (sync) queue this time: the job runs inside the request that stored the event.
        $resource = $this->deviceResource('enterprises/LC0test/devices/d9');
        $body = $this->message('m-e2e', 'STATUS_REPORT', $resource);

        $this->deliver($body)->assertNoContent();
        MdmDevice::query()->update(['android_version' => 'edited-after-first-delivery']);
        $this->deliver($body)->assertNoContent();

        $this->assertSame('edited-after-first-delivery', MdmDevice::query()->firstOrFail()->android_version);
        $this->assertNotNull(MdmEvent::query()->firstOrFail()->processed_at);
    }

    public function test_a_malformed_body_gets_400(): void
    {
        Queue::fake();

        $cases = [
            'no message' => ['subscription' => 'projects/p/subscriptions/s'],
            'message not an object' => ['message' => 'nope'],
            'no message id' => ['message' => ['data' => base64_encode('{"a":1}'), 'attributes' => ['notificationType' => 'STATUS_REPORT']]],
            'no data' => ['message' => ['messageId' => '1', 'attributes' => ['notificationType' => 'STATUS_REPORT']]],
            'data not base64' => ['message' => ['messageId' => '2', 'data' => '***not base64***']],
            'data not json' => ['message' => ['messageId' => '3', 'data' => base64_encode('this is not json')]],
            'data a json scalar' => ['message' => ['messageId' => '4', 'data' => base64_encode('42')]],
        ];

        foreach ($cases as $label => $body) {
            $this->deliver($body)->assertStatus(400);
        }

        $this->assertSame(0, MdmEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_body_that_is_not_json_at_all_gets_400(): void
    {
        $this->call('POST', self::ENDPOINT.'?token=test-push-secret', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer jwt-from-google',
            'CONTENT_TYPE' => 'application/json',
        ], '{this is not json')->assertStatus(400);
    }

    public function test_authentication_is_checked_before_the_body_so_garbage_from_strangers_is_a_401(): void
    {
        $this->deliver(['garbage' => true], 'wrong')->assertUnauthorized();
    }

    public function test_rejections_are_logged_with_a_reason_but_never_the_secret_token_or_payload(): void
    {
        Log::spy();

        $this->deliver($this->message('m-log', 'STATUS_REPORT', ['name' => 'SENSITIVE-DEVICE-NAME']), 'wrong-secret-value')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $context['reason'] === 'bad_query_token'
                && ! str_contains(json_encode($context), 'wrong-secret-value')
                && ! str_contains(json_encode($context), 'SENSITIVE-DEVICE-NAME')
                && ! str_contains(json_encode($context), 'jwt-from-google');
        });
    }

    public function test_the_route_has_no_session_csrf_or_auth_middleware_but_is_rate_limited(): void
    {
        $route = app(Router::class)->getRoutes()->getByName('webhooks.android-management');
        $middleware = app(Router::class)->gatherRouteMiddleware($route);

        foreach ($middleware as $name) {
            $this->assertNotContains($name, [
                StartSession::class,
                PreventRequestForgery::class,
                VerifyCsrfToken::class,
                EncryptCookies::class,
                Authenticate::class,
                CheckModuleAccess::class,
                EnsureUserIsActive::class,
            ]);
        }

        $this->assertTrue(collect($middleware)->contains(fn ($name) => str_starts_with((string) $name, ThrottleRequests::class)));
    }

    public function test_the_webhook_is_not_reachable_with_the_agent_api_token_scheme(): void
    {
        $user = $this->superAdmin();
        $user->forceFill(['api_token' => 'agent-token'])->save();

        $this->call('POST', self::ENDPOINT.'?token=test-push-secret', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer agent-token', 'CONTENT_TYPE' => 'application/json'], '{}');

        // The stub verifier accepts any bearer; what matters is that api_token is never consulted.
        $this->assertSame(['agent-token'], $this->tokens->seen);
        $this->assertSame(0, MdmEvent::query()->count());
    }

    /** @return array<string, mixed> */
    private function validClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'https://erp.example.test/webhooks/android-management',
            'email' => 'pubsub-push@gwl-test-project.iam.gserviceaccount.com',
            'email_verified' => true,
            'exp' => time() + 3600,
        ];
    }

    /** A Pub/Sub push body. */
    private function message(string $messageId, string $type, array $payload): array
    {
        return [
            'message' => [
                'messageId' => $messageId,
                'data' => base64_encode(json_encode($payload)),
                'attributes' => ['notificationType' => $type],
                'publishTime' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'subscription' => 'projects/gwl-test-project/subscriptions/amapi-push',
        ];
    }

    private function deliver(array $body, ?string $token = 'test-push-secret', ?string $bearer = 'jwt-from-google'): TestResponse
    {
        $url = self::ENDPOINT.($token === null ? '' : '?token='.urlencode($token));
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer '.$bearer];

        return $this->postJson($url, $body, $headers);
    }
}
