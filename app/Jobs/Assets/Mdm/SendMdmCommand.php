<?php

namespace App\Jobs\Assets\Mdm;

use App\Services\Assets\Mdm\CommandService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one mdm_device_commands row to Google. Livewire never calls Google for a command: it writes the row and
 * dispatches this job. A transient Google failure is retried; when retries run out the command is marked failed.
 */
class SendMdmCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $commandId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CommandService $commands): void
    {
        $commands->deliver($this->commandId);
    }

    public function failed(Throwable $exception): void
    {
        app(CommandService::class)->markFailed($this->commandId, $exception->getMessage());
    }
}
