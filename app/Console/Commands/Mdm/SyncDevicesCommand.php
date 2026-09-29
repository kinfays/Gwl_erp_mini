<?php

namespace App\Console\Commands\Mdm;

use App\Console\Commands\Mdm\Concerns\RequiresMdm;
use App\Models\MdmDeviceCommand;
use App\Services\Assets\Mdm\CommandService;
use App\Services\Assets\Mdm\DeviceService;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use Illuminate\Console\Command;

/**
 * The nightly safety net, run in both delivery modes: list every device Google has, reconcile them with mdm_devices,
 * and re-check commands that were sent but never reported back. Catches anything an event missed.
 */
class SyncDevicesCommand extends Command
{
    use RequiresMdm;

    protected $signature = 'mdm:sync-devices';

    protected $description = 'Full resync of every Android device (safety net for missed notifications)';

    public function handle(DeviceService $devices, CommandService $commands): int
    {
        return $this->whenMdmEnabled(function () use ($devices, $commands) {
            $result = $devices->syncAll();

            $stale = MdmDeviceCommand::query()
                ->where('status', MdmDeviceCommand::STATUS_SENT)
                ->whereNotNull('google_operation_name')
                ->where('sent_at', '<', now()->subMinutes(15))
                ->limit(200)
                ->get();

            $refreshed = 0;

            foreach ($stale as $command) {
                try {
                    $commands->refreshPending($command);
                    $refreshed++;
                } catch (MdmGatewayException) {
                    // Operations expire on Google's side; leave it as sent and move on.
                }
            }

            $this->info("MDM resync: {$result['seen']} device(s) seen, {$result['created']} new, {$result['marked_deleted']} marked deleted, {$refreshed} pending command(s) re-checked.");

            return Command::SUCCESS;
        });
    }
}
