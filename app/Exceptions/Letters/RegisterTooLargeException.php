<?php

namespace App\Exceptions\Letters;

use RuntimeException;

/** The register for the chosen range has more rows than letters_register_max_rows allows: ask the user to narrow it. */
class RegisterTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $rows, public readonly int $limit)
    {
        parent::__construct(
            "This register has {$rows} rows, more than the limit of {$limit}. Narrow the date range or pick a scope (Still with me, Dispatched or Closed) and try again."
        );
    }
}
