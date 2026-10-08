<?php

namespace App\Events\HealthSafety;

use App\Models\HsIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentReported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public HsIncident $incident) {}
}
