<?php

namespace App\Events\HealthSafety;

use App\Models\HsIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public HsIncident $incident,
        public ?string $from,
        public string $to,
    ) {}
}
