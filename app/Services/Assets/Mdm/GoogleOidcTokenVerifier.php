<?php

namespace App\Services\Assets\Mdm;

use App\Services\Assets\Mdm\Exceptions\PushAuthenticationException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Verifies a Pub/Sub push OIDC token against Google's published signing keys (a JWKS document).
 *
 * The key set is cached in the Laravel cache, so a normal webhook request makes no outbound call. It is fetched
 * again only when the cache is cold or the token names a key id we don't have (Google rotated its keys).
 */
class GoogleOidcTokenVerifier implements IdTokenVerifier
{
    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const CACHE_KEY = 'mdm.google.oidc_jwks';

    private const CACHE_HOURS = 6;

    /** Seconds of clock drift tolerated on exp / nbf. */
    private const LEEWAY = 30;

    public function verify(string $jwt): array
    {
        try {
            return $this->decode($jwt, $this->keys(refresh: false));
        } catch (UnexpectedValueException $e) {
            // An unknown "kid" is how a key rotation looks; try once with a fresh key set.
            if (! str_contains($e->getMessage(), 'kid')) {
                throw $this->reject($e);
            }
        } catch (Throwable $e) {
            throw $this->reject($e);
        }

        try {
            return $this->decode($jwt, $this->keys(refresh: true));
        } catch (Throwable $e) {
            throw $this->reject($e);
        }
    }

    /**
     * @param  array<string, Key>  $keys
     * @return array<string, mixed>
     */
    private function decode(string $jwt, array $keys): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;

        try {
            return (array) json_decode(json_encode(JWT::decode($jwt, $keys)), true);
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /** @return array<string, Key> */
    private function keys(bool $refresh): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $jwks = Cache::remember(self::CACHE_KEY, now()->addHours(self::CACHE_HOURS), function () {
            $response = Http::timeout(5)->acceptJson()->get(self::JWKS_URL);

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new UnexpectedValueException('keys_unavailable');
            }

            return $response->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function reject(Throwable $e): PushAuthenticationException
    {
        return new PushAuthenticationException(match (true) {
            $e instanceof ExpiredException => 'expired',
            $e instanceof SignatureInvalidException => 'bad_signature',
            $e instanceof UnexpectedValueException && $e->getMessage() === 'keys_unavailable' => 'keys_unavailable',
            default => 'invalid_token',
        });
    }
}
