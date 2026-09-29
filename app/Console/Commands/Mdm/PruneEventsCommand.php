<?php

namespace App\Console\Commands\Mdm;

use App\Models\MdmEvent;
use Illuminate\Console\Command;

/**
 * mdm_events payloads carry IMEIs, serial numbers and installed-app lists, so they are not kept forever. Only
 * events that were processed successfully are pruned; failed or still-unprocessed ones stay until someone looks.
 */
class PruneEventsCommand extends Command
{
    protected $signature = 'mdm:prune-events {--days= : Override GWL_MDM_EVENT_RETENTION_DAYS}';

    protected $description = 'Delete processed MDM notification records older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('gwl.mdm_event_retention_days', 90)));

        $deleted = MdmEvent::query()
            ->whereNotNull('processed_at')
            ->whereNull('error')
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} processed MDM event(s) older than {$days} days.");

        return Command::SUCCESS;
    }
}
