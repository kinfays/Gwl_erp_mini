<?php

namespace App\Services\Finance;

/** A print would hold more vouchers than gwl.fin_print_max_vouchers allows; nothing is printed and nothing is counted. */
class PrintLimitException extends \RuntimeException
{
    public function __construct(public int $count, public int $cap)
    {
        parent::__construct('This print has '.number_format($count).' vouchers, over the limit of '.number_format($cap).'. Choose fewer and try again.');
    }
}
