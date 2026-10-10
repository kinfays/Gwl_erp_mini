<?php

namespace App\Services\Commercial\Sms;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Arkesel SMS API (v2): the ONLY class that talks to Arkesel and the ONLY place its response shapes are read. Endpoint paths come
 * from config (gwl.arkesel.endpoints). If Arkesel changes an answer, this file is the whole fix.
 *
 * What is confirmed and what is not (docs/16 section 3): the paths, the `api-key` header and the request fields
 * (sender, recipients[], message, scheduled_date, sandbox) were read from a third-party SDK's source, not from Arkesel's own
 * documentation (which could not be read). The answers below are therefore parsed LEniently: several shapes are accepted, and
 * anything unrecognised is "unavailable" / "unknown", never a guess.
 *
 * Rules kept here: the key goes in a header only (never a URL), nothing is logged, no exception message or error code that
 * leaves this class can hold a number or the key (codes are short slugs built here), numbers stay in memory.
 */
class ArkeselGateway implements SmsGateway
{
    public function configured(): bool
    {
        return (string) config('gwl.arkesel.api_key') !== '';
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('gwl.arkesel.base_url'), '/'))
            ->withHeaders(['api-key' => (string) config('gwl.arkesel.api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) config('gwl.arkesel.timeout', 15)))
            ->connectTimeout(min(10, max(1, (int) config('gwl.arkesel.timeout', 15))));
    }

    protected function path(string $name, array $replace = []): string
    {
        $path = (string) config('gwl.arkesel.endpoints.'.$name, '');

        foreach ($replace as $key => $value) {
            $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
        }

        return '/'.ltrim($path, '/');
    }

    // ---------------------------------------------------------------- send

    public function send(string $senderId, string $message, array $numbers, ?CarbonInterface $scheduleAt = null): SmsSendResult
    {
        if (! $this->configured()) {
            return SmsSendResult::failed('not_configured');
        }

        if ($numbers === []) {
            return SmsSendResult::ok([]);
        }

        $body = array_filter([
            'sender' => $senderId,
            'message' => $message,
            'recipients' => array_map([self::class, 'international'], $numbers),
            'scheduled_date' => $scheduleAt?->format('Y-m-d h:i A'),
            'sandbox' => config('gwl.arkesel.sandbox') ? true : null,
        ], fn ($value) => $value !== null);

        try {
            $response = $this->request()->post($this->path('send'), $body);
        } catch (ConnectionException $e) {
            // Could not even connect (DNS, refused): the request never left. Anything else (a timeout while waiting for the
            // answer) may have been taken: unknown. The exception text is never kept: it can hold the URL.
            return $this->neverSent($e) ? SmsSendResult::failed('unreachable') : SmsSendResult::unknown('timeout');
        } catch (Throwable) {
            return SmsSendResult::unknown('error');
        }

        return $this->parseSend($response, $numbers);
    }

    /** @param  list<string>  $numbers */
    protected function parseSend(Response $response, array $numbers): SmsSendResult
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if (! $response->successful()) {
            return SmsSendResult::failed($this->errorCode($response, $json));
        }

        $status = strtolower((string) ($json['status'] ?? ''));
        $codeWord = strtolower((string) ($json['code'] ?? ''));

        if (in_array($status, ['error', 'failed', 'fail'], true) || ($codeWord !== '' && ! in_array($codeWord, ['ok', 'success', '200'], true))) {
            return SmsSendResult::failed($this->errorCode($response, $json));
        }

        // 2xx with no recognisable success marker: we cannot tell whether it was taken.
        if (! in_array($status, ['success', 'ok'], true) && ! in_array($codeWord, ['ok', 'success'], true)) {
            return SmsSendResult::unknown('unreadable');
        }

        $accepted = [];
        $items = $json['data'] ?? [];
        $items = is_array($items) ? (array_is_list($items) ? $items : ($items['recipients'] ?? $items['messages'] ?? [])) : [];

        foreach ((array) $items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $number = self::local((string) ($item['recipient'] ?? $item['to'] ?? $item['phone'] ?? ''));
            $id = $item['id'] ?? $item['message_id'] ?? null;

            if ($number !== null && in_array($number, $numbers, true)) {
                $accepted[$number] = $id === null ? null : (string) $id;
            }
        }

        // The provider took the request but did not say which numbers: all of them were accepted, without message ids.
        if ($accepted === []) {
            $accepted = array_fill_keys($numbers, null);
        }

        $rejected = [];

        foreach ($numbers as $number) {
            if (! array_key_exists($number, $accepted)) {
                $rejected[$number] = 'not_accepted';
            }
        }

        return SmsSendResult::ok($accepted, $rejected);
    }

    // ---------------------------------------------------------------- balance

    public function balance(): SmsBalance
    {
        if (! $this->configured()) {
            return SmsBalance::unavailable('not_configured');
        }

        try {
            $response = $this->request()->get($this->path('balance'));
        } catch (Throwable) {
            return SmsBalance::unavailable('unreachable');
        }

        if (! $response->successful()) {
            return SmsBalance::unavailable('http_'.$response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            return SmsBalance::unavailable('unreadable');
        }

        // Accepted shapes: {"data": {"sms_balance": N}}, {"sms_balance": N}, {"balance": N} (v1).
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $value = $data['sms_balance'] ?? $data['balance'] ?? $json['sms_balance'] ?? $json['balance'] ?? null;

        return is_numeric($value) ? SmsBalance::of((int) floor((float) $value)) : SmsBalance::unavailable('unreadable');
    }

    // ---------------------------------------------------------------- delivery

    public function status(string $messageId): SmsDeliveryStatus
    {
        if (! $this->configured() || $messageId === '') {
            return new SmsDeliveryStatus('unknown');
        }

        try {
            $response = $this->request()->get($this->path('status', ['id' => $messageId]));
        } catch (Throwable) {
            return new SmsDeliveryStatus('unknown', 'unreachable');
        }

        if (! $response->successful()) {
            return new SmsDeliveryStatus('unknown', 'http_'.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? self::stateOf($json) : new SmsDeliveryStatus('unknown', 'unreadable');
    }

    public function deliveryReport(array $payload): ?SmsDeliveryReport
    {
        $source = is_array($payload['data'] ?? null) && ! array_is_list($payload['data']) ? $payload['data'] : $payload;
        $id = $source['id'] ?? $source['message_id'] ?? $source['sms_id'] ?? $payload['id'] ?? $payload['message_id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            return null;
        }

        $status = self::stateOf($source);

        return $status->state === 'unknown' ? null : new SmsDeliveryReport((string) $id, $status->state, $status->code);
    }

    /** The delivery state in an answer or report, in whichever of the usual fields it sits. */
    protected static function stateOf(array $json): SmsDeliveryStatus
    {
        $data = is_array($json['data'] ?? null) && ! array_is_list($json['data']) ? $json['data'] : $json;
        $raw = $data['delivery_status'] ?? $data['status'] ?? $data['state'] ?? $json['delivery_status'] ?? $json['status'] ?? null;

        if (! is_scalar($raw)) {
            return new SmsDeliveryStatus('unknown');
        }

        $word = strtolower(trim((string) $raw));
        $code = isset($data['error_code']) && is_scalar($data['error_code']) ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $data['error_code']) : null;

        return match (true) {
            in_array($word, ['delivered', 'delivrd', 'deliver', 'success_delivered'], true) => new SmsDeliveryStatus('delivered'),
            in_array($word, ['failed', 'undelivered', 'undeliv', 'rejected', 'expired', 'error', 'blocked'], true) => new SmsDeliveryStatus('failed', $code ?: $word),
            in_array($word, ['sent', 'pending', 'queued', 'submitted', 'accepted', 'scheduled', 'enroute', 'processing'], true) => new SmsDeliveryStatus('pending'),
            default => new SmsDeliveryStatus('unknown'),
        };
    }

    // ---------------------------------------------------------------- helpers

    /** A short, safe slug for a refusal: never the provider's message (it may echo a number), never the key. */
    protected function errorCode(Response $response, array $json): string
    {
        $code = $json['code'] ?? null;

        if (is_scalar($code) && preg_match('/^[A-Za-z0-9_.-]{1,20}$/', (string) $code)) {
            return 'arkesel_'.strtolower((string) $code);
        }

        return 'http_'.$response->status();
    }

    protected function neverSent(ConnectionException $e): bool
    {
        return (bool) preg_match('/cURL error (6|7)\b|Could not resolve host|Connection refused|Failed to connect/i', $e->getMessage());
    }

    /** 0241234567 -> 233241234567 */
    public static function international(string $local): string
    {
        return '233'.substr($local, 1);
    }

    /** 233241234567 / +233... / 0241234567 -> 0241234567; null if it is not one number. */
    public static function local(string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return '0'.substr($digits, 3);
        }

        return preg_match('/^0\d{9}$/', $digits) ? $digits : null;
    }
}
