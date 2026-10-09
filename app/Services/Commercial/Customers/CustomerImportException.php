<?php

namespace App\Services\Commercial\Customers;

use RuntimeException;

/** A customer-list import problem whose message is safe to show (it never carries customer data). */
class CustomerImportException extends RuntimeException
{
}
