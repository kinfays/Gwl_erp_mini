<?php

namespace App\Services\Assets\Mdm;

use App\Jobs\Assets\Mdm\ProcessAndroidNotification;
use App\Models\MdmEvent;
use App\Services\Assets\Mdm\Exceptions\MalformedNotificationException;
use Illuminate\Support\Facades\DB;

/**
 * The single front door for Pub/Sub messages, shared by the push webhook and the pull command so both behave
 * identically: decode, store an mdm_events row, and hand it to ProcessAndroidNotification.
 *
 * Dedupe is by Pub/Sub messageId (unique column). Whichever path delivers a message first wins; a redelivery, a
 * message that arrives by both push and pull during a cutover, or a replay is recognised, reported as a duplicate,
 * and never dispatched again — so nothing is processed twice.
 *
 * The row and the job are created in one transaction: with the database queue driver that makes "stored" and
 * "queued" atomic, so a crash between them cannot lose an event.
 *
 * It never calls Google and does no device work; that is the job's business.
 */
class EventIntakeService
{
    /**
     * @param  string  $data  the message body exactly as Pub/Sub delivers it (base64 of a JSON document)
     * @param  array<string, string>  $attributes  Pub/Sub message attributes (notificationType lives here)
     * @return array{event: MdmEvent, duplicate: bool}
     *
     * @throws MalformedNotificationException when the message id or body cannot be decoded
     */
    public function ingest(string $messageId, string $deliveryMode, string $data, array $attributes = [], ?string $publishTime = null): array
    {
        $messageId = trim($messageId);

        if ($messageId === '') {
            throw new MalformedNotificationException('The message has no id.');
        }

        $payload = $this->decode($data);

        $row = [
            'pubsub_message_id' => $messageId,
            'delivery_mode' => $deliveryMode === MdmEvent::MODE_PUSH ? MdmEvent::MODE_PUSH : MdmEvent::MODE_PULL,
            'notification_type' => $this->notificationType($attributes),
            'google_device_name' => $this->deviceName($payload),
            'payload' => json_encode($payload),
            'received_at' => now(),
        ];

        return DB::transaction(function () use ($row, $messageId) {
            $inserted = MdmEvent::query()->insertOrIgnore($row);
            $event = MdmEvent::query()->where('pubsub_message_id', $messageId)->firstOrFail();

            if ($inserted === 0) {
                return ['event' => $event, 'duplicate' => true];
            }

            ProcessAndroidNotification::dispatch($event->id);

            return ['event' => $event, 'duplicate' => false];
        });
    }

    /** @return array<string, mixed> */
    private function decode(string $data): array
    {
        $json = base64_decode($data, true);

        if ($json === false || $json === '') {
            throw new MalformedNotificationException('The message body is not valid base64.');
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw new MalformedNotificationException('The message body is not a JSON object.');
        }

        return $payload;
    }

    private function notificationType(array $attributes): ?string
    {
        $type = $attributes['notificationType'] ?? null;

        return is_string($type) && $type !== '' ? substr($type, 0, 64) : null;
    }

    /** A Device notification carries `name`; a COMMAND Operation's name is "<device>/operations/<id>". */
    private function deviceName(array $payload): ?string
    {
        $name = $payload['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return preg_replace('#/operations/[^/]+$#', '', $name);
    }
}
