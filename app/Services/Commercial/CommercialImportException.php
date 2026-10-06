<?php

namespace App\Services\Commercial;

use RuntimeException;

/** A report import that must not go ahead (blocked preview, duplicate file); the message is safe to show the officer. */
class CommercialImportException extends RuntimeException
{
}
