<?php

namespace App\Services\Commercial\Sms;

use RuntimeException;

/** A campaign cannot be created, changed or run as asked. The message is shown to the user and never holds a number or a message text. */
class SmsCampaignException extends RuntimeException
{
}
