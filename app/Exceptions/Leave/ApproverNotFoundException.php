<?php

namespace App\Exceptions\Leave;

use RuntimeException;

/**
 * The approval chain for an applicant can't be completed: nobody active holds a role it needs.
 * LeaveWorkflowService turns this into a ValidationException for the applicant (and audits it);
 * the queue and authorization checks treat it as "nobody can act".
 */
class ApproverNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $role,
        string $message,
    ) {
        parent::__construct($message);
    }
}
