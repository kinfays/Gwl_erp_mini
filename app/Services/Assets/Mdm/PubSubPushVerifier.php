<?php

namespace App\Services\Assets\Mdm;

use App\Services\Assets\Mdm\Exceptions\PushAuthenticationException;
use Illuminate\Http\Request;

/**
 * Authenticates a Pub/Sub push request. Two independent factors, both required:
 *
 *   1. "Authorization: Bearer <jwt>" — an OIDC token Google signs for the push subscription's dedicated service
 *      account. Signature and expiry (IdTokenVerifier), then issuer, audience, the service account's email and
 *      email_verified.
 *   2. The secret ?token= query parameter, compared with hash_equals.
 *
 * Fails closed: any unset setting rejects every request. A rejection carries only a short reason slug — the token,
 * the JWT and the request body are never logged.
 */
class PubSubPushVerifier
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly IdTokenVerifier $tokens) {}

    /** @throws PushAuthenticationException */
    public function verify(Request $request): void
    {
        $audience = (string) config('gwl.mdm_pubsub_push_audience');
        $serviceAccount = strtolower(trim((string) config('gwl.mdm_pubsub_push_service_account')));
        $secret = (string) config('gwl.mdm_pubsub_push_token');

        if ($audience === '' || $serviceAccount === '' || $secret === '') {
            throw new PushAuthenticationException('not_configured');
        }

        $supplied = $request->query('token');

        if (! is_string($supplied) || ! hash_equals($secret, $supplied)) {
            throw new PushAuthenticationException('bad_query_token');
        }

        $jwt = $request->bearerToken();

        if (! is_string($jwt) || trim($jwt) === '') {
            throw new PushAuthenticationException('missing_bearer');
        }

        $claims = $this->tokens->verify(trim($jwt));

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new PushAuthenticationException('bad_issuer');
        }

        $tokenAudience = $claims['aud'] ?? null;

        if (! (is_string($tokenAudience) ? $tokenAudience === $audience : (is_array($tokenAudience) && in_array($audience, $tokenAudience, true)))) {
            throw new PushAuthenticationException('bad_audience');
        }

        // The signature check already enforces exp; this keeps the rule explicit (and honoured by any IdTokenVerifier).
        if (! isset($claims['exp']) || ! is_numeric($claims['exp']) || (int) $claims['exp'] <= time()) {
            throw new PushAuthenticationException('expired');
        }

        if (strtolower(trim((string) ($claims['email'] ?? ''))) !== $serviceAccount) {
            throw new PushAuthenticationException('bad_email');
        }

        if (! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new PushAuthenticationException('email_not_verified');
        }
    }
}
