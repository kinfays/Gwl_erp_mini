<?php

namespace App\Services\Assets\Mdm;

use App\Services\Assets\Mdm\Exceptions\PushAuthenticationException;

/**
 * Checks a Google-signed OIDC JWT's signature and time validity and returns its claims. Deciding whether the
 * claims are acceptable (issuer, audience, service account) is PubSubPushVerifier's job, so a test can bind a fake
 * that returns any claims it likes.
 */
interface IdTokenVerifier
{
    /**
     * @return array<string, mixed> the verified claims
     *
     * @throws PushAuthenticationException when the signature is wrong, the token is expired or malformed
     */
    public function verify(string $jwt): array;
}
