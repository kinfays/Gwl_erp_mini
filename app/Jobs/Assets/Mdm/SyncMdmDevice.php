<?php

namespace App\Jobs\Assets\Mdm;

use App\Models\MdmDevice;
use App\Services\Assets\Mdm\DeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Sync now" on the device screen: devices.get for one phone, off the request. */
class SyncMdmDevice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $deviceId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(DeviceService $devices): void
    {
        $device = MdmDevice::query()->find($this->deviceId);

        if ($device && ! $device->isDeleted()) {
            $devices->syncFromGoogle($device);
        }
    }
}
