<?php

namespace Tests\Support\Commercial;

use App\Services\Commercial\Sms\SmsBalance;
use App\Services\Commercial\Sms\SmsDeliveryReport;
use App\Services\Commercial\Sms\SmsDeliveryStatus;
use App\Services\Commercial\Sms\SmsGateway;
use App\Services\Commercial\Sms\SmsSendResult;
use Carbon\CarbonInterface;
use Closure;

/**
 * In-memory stand-in for Arkesel. Records every call (so a test can assert what was and was not sent: one call per unique
 * number, never twice) and returns canned answers. Nothing in the suite may reach the network.
 */
class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{sender: string, message: string, numbers: list<string>}> */
    public array $sent = [];

    public SmsBalance $balance;

    public int $balanceCalls = 0;

    public bool $isConfigured = true;

    /** @var array<string, SmsDeliveryStatus> message id => what status() answers */
    public array $statuses = [];

    public int $statusCalls = 0;

    /** What the next send() answers instead of "all accepted": a result, or a closure (sender, message, numbers) => result|throws. */
    public SmsSendResult|Closure|null $nextSend = null;

    /** Runs at the moment of a send (after it is recorded), so a test can look at the database or kill the "worker" there. */
    public ?Closure $onSend = null;

    private int $sequence = 0;

    public function __construct()
    {
        $this->balance = SmsBalance::of(100000);
    }

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function send(string $senderId, string $message, array $numbers, ?CarbonInterface $scheduleAt = null): SmsSendResult
    {
        $this->sent[] = ['sender' => $senderId, 'message' => $message, 'numbers' => array_values($numbers)];

        if ($this->onSend) {
            ($this->onSend)($senderId, $message, $numbers);
        }

        $next = $this->nextSend;
        $this->nextSend = null;

        if ($next instanceof Closure) {
            return $next($senderId, $message, $numbers);
        }

        if ($next) {
            return $next;
        }

        $accepted = [];

        foreach ($numbers as $number) {
            $accepted[$number] = 'fake-'.++$this->sequence;
        }

        return SmsSendResult::ok($accepted);
    }

    public function balance(): SmsBalance
    {
        $this->balanceCalls++;

        return $this->balance;
    }

    public function status(string $messageId): SmsDeliveryStatus
    {
        $this->statusCalls++;

        return $this->statuses[$messageId] ?? new SmsDeliveryStatus('pending');
    }

    public function deliveryReport(array $payload): ?SmsDeliveryReport
    {
        // The fake's own tiny format: {"id": "...", "state": "delivered|failed|pending", "code": "..."}.
        if (! isset($payload['id'], $payload['state']) || ! is_scalar($payload['id'])) {
            return null;
        }

        return new SmsDeliveryReport((string) $payload['id'], (string) $payload['state'], isset($payload['code']) ? (string) $payload['code'] : null);
    }

    /** Every number sent, in order, across all calls. @return list<string> */
    public function allNumbers(): array
    {
        return array_merge(...array_map(fn (array $call) => $call['numbers'], $this->sent) ?: [[]]);
    }
}
