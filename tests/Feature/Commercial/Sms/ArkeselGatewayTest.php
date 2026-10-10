<?php

namespace Tests\Feature\Commercial\Sms;

use App\Services\Commercial\Sms\ArkeselGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The real gateway driven through Http::fake (it never reaches Arkesel): what it sends, what it makes of the answers, and
 * that it never lets the key or a number out. No database is needed.
 */
class ArkeselGatewayTest extends TestCase
{
    private const KEY = 'test-key-never-real';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.arkesel.api_key' => self::KEY, 'gwl.arkesel.base_url' => 'https://sms.arkesel.test', 'gwl.arkesel.sandbox' => false]);
        Http::preventStrayRequests();
    }

    /** Http::fake() keeps its first matching stub, so each new answer starts from a fresh client. */
    protected function fake(mixed $answer): void
    {
        app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake(['*' => $answer]);
        Http::preventStrayRequests();
    }

    protected function gateway(): ArkeselGateway
    {
        return new ArkeselGateway;
    }

    // ---------------------------------------------------------------- balance

    public function test_the_balance_is_read_in_each_accepted_shape(): void
    {
        foreach ([
            ['data' => ['sms_balance' => 250, 'main_balance' => 10.5]],
            ['sms_balance' => '250'],
            ['balance' => 250.0],
            ['status' => 'success', 'data' => ['sms_balance' => 250.9]],
        ] as $body) {
            $this->fake(Http::response($body));
            $balance = $this->gateway()->balance();

            $this->assertTrue($balance->available, json_encode($body));
            $this->assertSame(250, $balance->credits);
        }
    }

    public function test_an_unreadable_error_or_unreachable_answer_is_unavailable_never_zero(): void
    {
        foreach ([
            Http::response(['message' => 'nope'], 200),
            Http::response('<html>', 200),
            Http::response(['error' => 'x'], 500),
            Http::response([], 401),
            Http::response(['data' => ['sms_balance' => 'plenty']], 200),
        ] as $answer) {
            $this->fake($answer);
            $balance = $this->gateway()->balance();

            $this->assertFalse($balance->available);
            $this->assertNull($balance->credits, 'unavailable is not a zero balance');
        }

        $this->fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->assertFalse($this->gateway()->balance()->available);

        config(['gwl.arkesel.api_key' => '']);
        $this->assertFalse($this->gateway()->balance()->available);
        $this->assertFalse($this->gateway()->configured());
    }

    public function test_the_key_goes_in_a_header_and_never_in_the_url_and_the_paths_come_from_config(): void
    {
        $this->fake(Http::response(['sms_balance' => 1]));
        $this->gateway()->balance();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://sms.arkesel.test/api/v2/clients/balance-details'
                && $request->header('api-key') === [self::KEY]
                && ! str_contains($request->url(), self::KEY);
        });

        config(['gwl.arkesel.endpoints.balance' => '/v9/credits']);
        $this->fake(Http::response(['sms_balance' => 1]));
        $this->gateway()->balance();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://sms.arkesel.test/v9/credits');
    }

    // ---------------------------------------------------------------- send

    public function test_a_send_posts_the_documented_fields_with_international_numbers_and_maps_the_ids_back(): void
    {
        $this->fake(Http::response(['status' => 'success', 'data' => [
            ['recipient' => '233241234567', 'id' => 'm-1'],
            ['recipient' => '233207654321', 'id' => 'm-2'],
        ]]));

        $result = $this->gateway()->send('GWCL', 'Hello there', ['0241234567', '0207654321']);

        $this->assertSame('ok', $result->outcome);
        $this->assertSame(['0241234567' => 'm-1', '0207654321' => 'm-2'], $result->accepted);
        $this->assertSame([], $result->rejected);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://sms.arkesel.test/api/v2/sms/send'
                && $request->header('api-key') === [self::KEY]
                && $request['sender'] === 'GWCL'
                && $request['message'] === 'Hello there'
                && $request['recipients'] === ['233241234567', '233207654321']
                && ! isset($request['sandbox']);
        });
    }

    public function test_sandbox_mode_is_sent_only_when_switched_on(): void
    {
        config(['gwl.arkesel.sandbox' => true]);
        $this->fake(Http::response(['status' => 'success', 'data' => []]));

        $this->gateway()->send('GWCL', 'x', ['0241234567']);

        Http::assertSent(fn (Request $request) => $request['sandbox'] === true);
    }

    public function test_a_success_without_per_number_detail_accepts_every_number_without_ids(): void
    {
        $this->fake(Http::response(['status' => 'success']));

        $result = $this->gateway()->send('GWCL', 'x', ['0241234567', '0207654321']);

        $this->assertSame('ok', $result->outcome);
        $this->assertSame(['0241234567' => null, '0207654321' => null], $result->accepted);
    }

    public function test_numbers_the_provider_leaves_out_are_rejected_individually(): void
    {
        $this->fake(Http::response(['status' => 'success', 'data' => [['recipient' => '233241234567', 'id' => 'm-1']]]));

        $result = $this->gateway()->send('GWCL', 'x', ['0241234567', '0207654321']);

        $this->assertSame(['0241234567' => 'm-1'], $result->accepted);
        $this->assertSame(['0207654321' => 'not_accepted'], $result->rejected);
    }

    public function test_a_refusal_is_failed_with_a_short_code_and_no_provider_text(): void
    {
        $this->fake(Http::response(['status' => 'error', 'code' => '102', 'message' => 'Invalid number 233241234567 for key test-key-never-real'], 200));
        $result = $this->gateway()->send('GWCL', 'x', ['0241234567']);

        $this->assertSame('failed', $result->outcome);
        $this->assertSame('arkesel_102', $result->code);
        $this->assertStringNotContainsString('2412', (string) $result->code);

        $this->fake(Http::response(['message' => 'x'], 500));
        $this->assertSame('http_500', $this->gateway()->send('GWCL', 'x', ['0241234567'])->code);

        $this->fake(Http::response(['message' => 'Unauthorised'], 401));
        $this->assertSame(['failed', 'http_401'], [$this->gateway()->send('GWCL', 'x', ['0241234567'])->outcome, 'http_401']);
    }

    public function test_a_timeout_after_the_request_is_unknown_but_a_connect_failure_is_a_failure(): void
    {
        $this->fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15000 ms'));
        $result = $this->gateway()->send('GWCL', 'x', ['0241234567']);
        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('timeout', $result->code);

        $this->fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: sms.arkesel.test'));
        $result = $this->gateway()->send('GWCL', 'x', ['0241234567']);
        $this->assertSame('failed', $result->outcome);
        $this->assertSame('unreachable', $result->code);
    }

    public function test_an_unrecognisable_success_is_unknown_not_assumed_sent_or_failed(): void
    {
        $this->fake(Http::response(['hello' => 'world'], 200));

        $this->assertSame('unknown', $this->gateway()->send('GWCL', 'x', ['0241234567'])->outcome);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['gwl.arkesel.api_key' => '']);
        Http::fake();

        $result = $this->gateway()->send('GWCL', 'x', ['0241234567']);

        $this->assertSame(['failed', 'not_configured'], [$result->outcome, $result->code]);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- delivery

    public function test_a_status_lookup_and_a_webhook_report_are_read_leniently(): void
    {
        foreach ([
            [['data' => ['status' => 'DELIVERED']], 'delivered'],
            [['status' => 'Delivrd'], 'delivered'],
            [['delivery_status' => 'failed', 'error_code' => 'E-12'], 'failed'],
            [['data' => ['status' => 'sent']], 'pending'],
            [['status' => 'queued'], 'pending'],
            [['status' => 'something new'], 'unknown'],
            [['nothing' => 'here'], 'unknown'],
        ] as [$body, $state]) {
            $this->fake(Http::response($body));
            $this->assertSame($state, $this->gateway()->status('m-9')->state, json_encode($body));
        }

        Http::assertSent(fn (Request $request) => $request->url() === 'https://sms.arkesel.test/api/v2/sms/m-9');

        $this->fake(Http::response([], 404));
        $this->assertSame('unknown', $this->gateway()->status('m-9')->state);

        $report = $this->gateway()->deliveryReport(['id' => 'm-3', 'status' => 'DELIVERED', 'recipient' => '233241234567']);
        $this->assertSame(['m-3', 'delivered'], [$report->messageId, $report->state]);
        $failed = $this->gateway()->deliveryReport(['data' => ['id' => 'm-4', 'status' => 'failed', 'error_code' => 'X1']]);
        $this->assertSame(['m-4', 'failed', 'X1'], [$failed->messageId, $failed->state, $failed->code]);
        $this->assertNull($this->gateway()->deliveryReport(['status' => 'delivered']), 'no message id: nothing to apply');
        $this->assertNull($this->gateway()->deliveryReport(['id' => 'm-5', 'status' => '???']));
    }

    public function test_nothing_is_logged_by_the_gateway(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->fake(Http::response(['status' => 'error', 'code' => '102', 'message' => 'bad 233241234567'], 200));
        $this->gateway()->send('GWCL', 'secret text 0241234567', ['0241234567']);
        $this->gateway()->balance();
        $this->fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->gateway()->send('GWCL', 'x', ['0241234567']);
        $this->gateway()->status('m-1');

        $this->assertSame([], $logged, 'the gateway must not log anything');
    }
}
