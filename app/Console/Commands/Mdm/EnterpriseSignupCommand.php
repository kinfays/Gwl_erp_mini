<?php

namespace App\Console\Commands\Mdm;

use App\Console\Commands\Mdm\Concerns\RequiresMdm;
use App\Services\Assets\Mdm\EnterpriseService;
use Illuminate\Console\Command;

class EnterpriseSignupCommand extends Command
{
    use RequiresMdm;

    protected $signature = 'mdm:enterprise-signup';

    protected $description = 'Step 1 of the one-time Android Enterprise bootstrap: print the Google signup URL';

    public function handle(EnterpriseService $enterprise): int
    {
        return $this->whenMdmEnabled(function () use ($enterprise) {
            // Built from APP_URL, so it works on https://erp_project.test now and on the public domain later.
            // Google redirects the ADMIN'S BROWSER here, so it does not need to be reachable from the internet.
            $signup = $enterprise->startSignup(route('assets.mdm.enterprise.callback'));

            $this->info('Open this URL in a browser, signed in as the Google account that will own the enterprise:');
            $this->line('');
            $this->line($signup['url']);
            $this->line('');
            $this->line('After you finish, Google redirects to '.$signup['callback_url'].' which shows the enterprise name.');
            $this->line('If you would rather stay in the terminal, copy the enterpriseToken from that redirect and run:');
            $this->line('  php artisan mdm:enterprise-create <enterpriseToken>');
            $this->line('The signup is remembered for 24 hours.');

            return Command::SUCCESS;
        });
    }
}
