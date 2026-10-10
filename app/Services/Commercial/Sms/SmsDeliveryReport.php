<?php

namespace App\Services\Commercial\Sms;

/** One parsed delivery report: the provider's message id and what became of that message. No number, no text. */
final class SmsDeliveryReport
{
    public function __construct(public readonly string $messageId, public readonly string $state, public readonly ?string $code = null)
    {
    }
}
