<?php

namespace App\Observers;

use App\Models\MileageLog;

class MileageLogObserver
{
    public function saved(MileageLog $mileageLog): void
    {
        $vehicle = $mileageLog->vehicle;

        if (! $vehicle) {
            return;
        }

        if ((int) $mileageLog->mileage_after > (int) $vehicle->current_mileage) {
            $vehicle->forceFill([
                'current_mileage' => (int) $mileageLog->mileage_after,
            ])->saveQuietly();
        }
    }
}
