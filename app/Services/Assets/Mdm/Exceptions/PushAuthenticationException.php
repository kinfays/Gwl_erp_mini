<?php

namespace App\Services\Assets\Mdm\Exceptions;

/**
 * A push request that failed authentication. `reason` is a short machine slug that is safe to log; the message
 * and the request payload/token never are.
 */
class PushAuthenticationException extends MdmException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Push notification rejected: '.$reason);
    }
}
