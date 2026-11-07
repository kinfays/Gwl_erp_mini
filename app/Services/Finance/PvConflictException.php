<?php

namespace App\Services\Finance;

use RuntimeException;

/**
 * The voucher was changed by someone else after the form was opened (its lock_version moved on). The screen warns and offers
 * to reload; nothing is ever overwritten silently.
 */
class PvConflictException extends RuntimeException
{
    public function __construct(string $message = 'This voucher was changed by someone else while you had it open. Reload it to see their changes, then make yours again.')
    {
        parent::__construct($message);
    }
}
