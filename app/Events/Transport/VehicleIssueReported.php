<?php

namespace App\Events\Transport;

use App\Models\VehicleIssue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VehicleIssueReported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public VehicleIssue $issue) {}
}
