<?php

namespace App\Services\Commercial\Sms;

use RuntimeException;

/** An audience the user asked for cannot be built (bad input, too many, nothing to message). The message is shown to the user and never holds a number. */
class SmsAudienceException extends RuntimeException
{
}
