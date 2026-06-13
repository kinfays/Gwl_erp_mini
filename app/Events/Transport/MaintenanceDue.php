<?php

namespace App\Events\Transport;

use App\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaintenanceDue
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Vehicle $vehicle) {}
}
