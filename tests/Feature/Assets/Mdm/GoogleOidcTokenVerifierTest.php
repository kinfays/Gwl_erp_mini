<?php

namespace Tests\Feature\Assets\Mdm;

use App\Services\Assets\Mdm\Exceptions\PushAuthenticationException;
use App\Services\Assets\Mdm\GoogleOidcTokenVerifier;
use App\Services\Assets\Mdm\PubSubPushVerifier;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real signature check, against a locally generated RSA key served through a faked JWKS endpoint — so it proves
 * real crypto without ever touching Google. (The webhook tests use a stub for this layer and cover the claim rules.)
 */
class GoogleOidcTokenVerifierTest extends TestCase
{
    private const AUDIENCE = 'https://erp.example.test/webhooks/android-management';

    private const SERVICE_ACCOUNT = 'pubsub-push@gwl-test-project.iam.gserviceaccount.com';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'gwl.mdm_pubsub_push_audience' => self::AUDIENCE,
            'gwl.mdm_pubsub_push_service_account' => self::SERVICE_ACCOUNT,
            'gwl.mdm_pubsub_push_token' => 'secret',
        ]);
    }

    public function test_a_token_signed_by_a_published_key_is_accepted_end_to_end(): void
    {
        [$private, $jwks] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response($jwks)]);

        $this->push()->verify($this->request($this->token($private, 'kid-1')));

        $this->addToAssertionCount(1);
        Http::assertSentCount(1);
    }

    public function test_the_key_set_is_cached_so_a_second_request_makes_no_outbound_call(): void
    {
        [$private, $jwks] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response($jwks)]);

        $this->push()->verify($this->request($this->token($private, 'kid-1')));
        $this->push()->verify($this->request($this->token($private, 'kid-1')));

        Http::assertSentCount(1);
    }

    public function test_a_token_signed_by_someone_elses_key_is_rejected(): void
    {
        [, $jwks] = $this->keyPair('kid-1');
        [$attackerPrivate] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response($jwks)]);

        $this->assertRejected('bad_signature', fn () => $this->push()->verify($this->request($this->token($attackerPrivate, 'kid-1'))));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [$private, $jwks] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response($jwks)]);

        $this->assertRejected('expired', fn () => $this->push()->verify($this->request($this->token($private, 'kid-1', ['exp' => time() - 600]))));
    }

    public function test_a_token_for_another_audience_or_service_account_is_rejected_after_the_signature_passes(): void
    {
        [$private, $jwks] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response($jwks)]);

        $this->assertRejected('bad_audience', fn () => $this->push()->verify($this->request($this->token($private, 'kid-1', ['aud' => 'https://elsewhere.example']))));
        $this->assertRejected('bad_email', fn () => $this->push()->verify($this->request($this->token($private, 'kid-1', ['email' => 'other@x.iam.gserviceaccount.com']))));
        $this->assertRejected('bad_issuer', fn () => $this->push()->verify($this->request($this->token($private, 'kid-1', ['iss' => 'https://evil.example']))));
    }

    public function test_garbage_and_alg_none_tokens_are_rejected(): void
    {
        [, $jwks] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response($jwks)]);

        $this->assertRejected('invalid_token', fn () => $this->push()->verify($this->request('not.a.jwt')));

        $none = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'kid-1'])), '+/', '-_'), '=')
            .'.'.rtrim(strtr(base64_encode(json_encode(['iss' => 'https://accounts.google.com', 'aud' => self::AUDIENCE, 'email' => self::SERVICE_ACCOUNT, 'email_verified' => true, 'exp' => time() + 600])), '+/', '-_'), '=')
            .'.';

        $this->assertRejected('invalid_token', fn () => $this->push()->verify($this->request($none)));
    }

    public function test_a_rotated_key_triggers_one_refetch_of_the_key_set(): void
    {
        [$oldPrivate, $oldJwks] = $this->keyPair('kid-old');
        [$newPrivate, $newJwks] = $this->keyPair('kid-new');

        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::sequence()->push($oldJwks)->push($newJwks)]);

        $this->push()->verify($this->request($this->token($oldPrivate, 'kid-old')));
        $this->push()->verify($this->request($this->token($newPrivate, 'kid-new')));

        Http::assertSentCount(2);
    }

    public function test_an_unreachable_key_endpoint_rejects_rather_than_accepting_unverified_tokens(): void
    {
        [$private] = $this->keyPair('kid-1');
        Http::fake([GoogleOidcTokenVerifier::JWKS_URL => Http::response('nope', 503)]);

        $this->assertRejected('keys_unavailable', fn () => $this->push()->verify($this->request($this->token($private, 'kid-1'))));
    }

    private function push(): PubSubPushVerifier
    {
        return new PubSubPushVerifier(new GoogleOidcTokenVerifier);
    }

    private function request(string $jwt): Request
    {
        return Request::create('/webhooks/android-management?token=secret', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt]);
    }

    /** @param  array<string, mixed>  $claims */
    private function token(\OpenSSLAsymmetricKey $private, string $kid, array $claims = []): string
    {
        $this->exportPem($private, $pem);

        return JWT::encode(array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::AUDIENCE,
            'email' => self::SERVICE_ACCOUNT,
            'email_verified' => true,
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ], $claims), $pem, 'RS256', $kid);
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: array{keys: list<array<string, string>>}} */
    private function keyPair(string $kid): array
    {
        $key = $this->newRsaKey();
        $details = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return [$key, ['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid,
            'n' => $b64($details['n']), 'e' => $b64($details['e']),
        ]]]];
    }

    /**
     * Windows builds of PHP often have no openssl.cnf on their search path, which makes openssl_pkey_new() return
     * false; fall back to the copy that ships next to php.exe, and skip (rather than fail) if there is none.
     */
    private function newRsaKey(): \OpenSSLAsymmetricKey
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $key = openssl_pkey_new($options);

        if ($key === false) {
            $config = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

            if (is_file($config)) {
                $key = openssl_pkey_new($options + ['config' => $config]);
            }
        }

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot generate an RSA key here (no openssl.cnf).');
        }

        return $key;
    }

    private function exportPem(\OpenSSLAsymmetricKey $key, ?string &$pem): void
    {
        if (! openssl_pkey_export($key, $pem)) {
            $config = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
            openssl_pkey_export($key, $pem, null, ['config' => $config]);
        }
    }

    private function assertRejected(string $reason, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected a rejection with reason {$reason}.");
        } catch (PushAuthenticationException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
