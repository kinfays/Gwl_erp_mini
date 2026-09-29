<?php

namespace App\Services\Assets\Mdm\Exceptions;

use Throwable;

/** Google (AMAPI or Pub/Sub) rejected or failed a call. Carries the HTTP status so callers can decide to retry. */
class MdmGatewayException extends MdmException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }

    /** Worth retrying: rate limited, server-side, or the network never got an answer. */
    public function isTransient(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
