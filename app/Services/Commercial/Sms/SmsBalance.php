<?php

namespace App\Services\Commercial\Sms;

/** SMS credits left, or "unavailable" (never a pretend zero). */
final class SmsBalance
{
    public function __construct(public readonly bool $available, public readonly ?int $credits = null, public readonly ?string $reason = null)
    {
    }

    public static function of(int $credits): self
    {
        return new self(true, max(0, $credits));
    }

    public static function unavailable(string $reason = 'unavailable'): self
    {
        return new self(false, null, $reason);
    }
}
