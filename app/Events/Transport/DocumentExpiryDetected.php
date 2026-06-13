<?php

namespace App\Events\Transport;

use App\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentExpiryDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Vehicle $vehicle,
        public string $documentType,
        public int $daysUntilExpiry
    ) {}
}
