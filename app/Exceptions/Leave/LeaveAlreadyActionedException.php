<?php

namespace App\Exceptions\Leave;

use RuntimeException;

/**
 * Someone else acted on the request first (or it was never waiting for this step). Raised from inside the
 * locked re-read in LeaveWorkflowService, so a double click or two approvers acting at once can't both win.
 */
class LeaveAlreadyActionedException extends RuntimeException
{
    public function __construct(string $message = 'This leave request has already been actioned.')
    {
        parent::__construct($message);
    }
}
