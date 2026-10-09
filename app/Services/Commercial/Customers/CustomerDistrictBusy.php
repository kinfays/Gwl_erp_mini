<?php

namespace App\Services\Commercial\Customers;

use RuntimeException;

/** Another batch of the same district is being processed: the job is put back on the queue and tried again shortly. */
class CustomerDistrictBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another upload for this district is being processed.');
    }
}
