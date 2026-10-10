<?php

namespace App\Services\Commercial\Sms;

/**
 * What a send call came to. outcome:
 *   ok       the provider took the request: `accepted` maps number => provider message id (null when it gave none), `rejected`
 *            maps number => short error code for the numbers it refused;
 *   failed   the provider certainly did NOT take it (refused, bad key, no credit, not reachable): nothing was sent;
 *   unknown  we cannot tell whether it was taken (a timeout after the request was sent, an unreadable answer).
 * `code` is a short slug, never a provider message (which could echo a number) and never the key.
 */
final class SmsSendResult
{
    /**
     * @param  array<string, string|null>  $accepted
     * @param  array<string, string>  $rejected
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $code = null,
        public readonly array $accepted = [],
        public readonly array $rejected = [],
    ) {
    }

    public static function ok(array $accepted, array $rejected = []): self
    {
        return new self('ok', null, $accepted, $rejected);
    }

    public static function failed(string $code): self
    {
        return new self('failed', $code);
    }

    public static function unknown(string $code): self
    {
        return new self('unknown', $code);
    }
}
