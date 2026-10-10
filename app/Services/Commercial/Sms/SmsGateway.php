<?php

namespace App\Services\Commercial\Sms;

use Carbon\CarbonInterface;

/**
 * The only door to an SMS provider. ArkeselGateway is the real one; tests bind Tests\Support\Commercial\FakeSmsGateway and no
 * test may reach the network. Numbers cross this interface IN MEMORY ONLY (local form "0XXXXXXXXX"); nothing implementing it
 * may log, store or put one in an exception, and none may expose the API key.
 */
interface SmsGateway
{
    /**
     * Send one message to up to a chunk of numbers.
     *
     * @param  list<string>  $numbers  local form 0XXXXXXXXX, already de-duplicated
     */
    public function send(string $senderId, string $message, array $numbers, ?CarbonInterface $scheduleAt = null): SmsSendResult;

    /** Credits left. Never throws: a provider that cannot be reached is SmsBalance::unavailable(), not a zero balance. */
    public function balance(): SmsBalance;

    /** Delivery state of one submitted message. Never throws. */
    public function status(string $messageId): SmsDeliveryStatus;

    /**
     * Reads a delivery report the provider POSTed to our webhook into (message id, state, code); null when it holds none.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliveryReport(array $payload): ?SmsDeliveryReport;

    /** Whether an API key is configured at all. */
    public function configured(): bool;
}
