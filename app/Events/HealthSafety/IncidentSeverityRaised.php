<?php

namespace App\Events\HealthSafety;

use App\Models\HsIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Severity was set to High or Critical (from nothing, or from a lower level). */
class IncidentSeverityRaised
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public HsIncident $incident) {}
}
