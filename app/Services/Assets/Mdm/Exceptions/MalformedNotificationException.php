<?php

namespace App\Services\Assets\Mdm\Exceptions;

/** A Pub/Sub message whose body cannot be decoded. The webhook answers 400 for this and only this. */
class MalformedNotificationException extends MdmException {}
