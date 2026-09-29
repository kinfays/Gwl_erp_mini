<?php

namespace Tests\Support\Mdm;

use App\Services\Assets\Mdm\Exceptions\PushAuthenticationException;
use App\Services\Assets\Mdm\IdTokenVerifier;

/**
 * Stands in for Google's signature check: returns whatever claims the test set, or throws the given rejection reason.
 * The claim checks themselves (issuer, audience, service account, expiry) are still done by the real PubSubPushVerifier.
 */
class FakeIdTokenVerifier implements IdTokenVerifier
{
    /** @var array<string, mixed> */
    public array $claims = [];

    public ?string $failWith = null;

    /** @var list<string> */
    public array $seen = [];

    public function verify(string $jwt): array
    {
        $this->seen[] = $jwt;

        if ($this->failWith !== null) {
            throw new PushAuthenticationException($this->failWith);
        }

        return $this->claims;
    }
}
