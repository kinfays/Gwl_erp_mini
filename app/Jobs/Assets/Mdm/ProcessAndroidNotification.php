<?php

namespace App\Jobs\Assets\Mdm;

use App\Models\MdmEvent;
use App\Services\Assets\Mdm\CommandService;
use App\Services\Assets\Mdm\DeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one place a stored Pub/Sub message is acted on, whether it arrived by push or by pull:
 *
 *   ENROLLMENT, STATUS_REPORT  a Device resource  → DeviceService (link / refresh the phone)
 *   COMMAND                    an Operation       → CommandService (command status)
 *   USAGE_LOGS                 recorded only
 *
 * Safe to run twice: an event that already has processed_at is skipped, and a cache lock stops two workers picking
 * up the same event at once.
 */
class ProcessAndroidNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DeviceService $devices, CommandService $commands): void
    {
        $lock = Cache::lock('mdm-event:'.$this->eventId, 120);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $event = MdmEvent::query()->find($this->eventId);

            if (! $event || $event->processed_at !== null) {
                return;
            }

            $payload = $event->payload ?? [];

            match ($event->notification_type) {
                'ENROLLMENT' => $devices->handleEnrollment($payload),
                'STATUS_REPORT' => $devices->ingest($payload),
                'COMMAND' => $commands->applyOperation($payload),
                default => null, // USAGE_LOGS and anything Google adds later: stored, nothing to apply.
            };

            $event->forceFill(['processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $this->recordError($e);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->recordError($exception);
    }

    private function recordError(Throwable $e): void
    {
        MdmEvent::query()->whereKey($this->eventId)->update(['error' => mb_strimwidth($e->getMessage(), 0, 1000, '…')]);
    }
}
