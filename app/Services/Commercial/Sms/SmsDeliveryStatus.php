<?php

namespace App\Services\Commercial\Sms;

/** state: delivered | failed | pending (accepted, not delivered yet) | unknown (could not be read). */
final class SmsDeliveryStatus
{
    public function __construct(public readonly string $state, public readonly ?string $code = null)
    {
    }
}
