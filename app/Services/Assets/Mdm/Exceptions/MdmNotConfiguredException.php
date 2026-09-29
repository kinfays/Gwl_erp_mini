<?php

namespace App\Services\Assets\Mdm\Exceptions;

/** A required GWL_MDM_* / GOOGLE_* setting is missing. The message names the setting, never a secret. */
class MdmNotConfiguredException extends MdmException {}
