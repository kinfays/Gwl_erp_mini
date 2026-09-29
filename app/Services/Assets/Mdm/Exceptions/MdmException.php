<?php

namespace App\Services\Assets\Mdm\Exceptions;

use RuntimeException;

/** Base type for MDM failures that are not plain validation problems. */
class MdmException extends RuntimeException {}
