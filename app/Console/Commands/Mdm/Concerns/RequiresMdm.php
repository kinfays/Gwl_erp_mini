<?php

namespace App\Console\Commands\Mdm\Concerns;

use App\Services\Assets\Mdm\Exceptions\MdmException;
use App\Services\Assets\Mdm\MdmSettings;
use Closure;
use Illuminate\Console\Command;

trait RequiresMdm
{
    /**
     * Run $work only when the MDM feature flag is on, turning MDM failures (missing settings, Google errors) into a
     * one-line error and a failure exit code instead of a stack trace.
     */
    protected function whenMdmEnabled(Closure $work): int
    {
        /** @var Command $this */
        if (! app(MdmSettings::class)->enabled()) {
            $this->warn('MDM is disabled. Set GWL_MDM_ENABLED=true first.');

            return Command::FAILURE;
        }

        try {
            return $work() ?? Command::SUCCESS;
        } catch (MdmException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
