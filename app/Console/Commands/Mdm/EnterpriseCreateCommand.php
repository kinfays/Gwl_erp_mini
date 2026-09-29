<?php

namespace App\Console\Commands\Mdm;

use App\Console\Commands\Mdm\Concerns\RequiresMdm;
use App\Services\Assets\Mdm\EnterpriseService;
use Illuminate\Console\Command;

class EnterpriseCreateCommand extends Command
{
    use RequiresMdm;

    protected $signature = 'mdm:enterprise-create
        {token : The enterpriseToken query parameter Google added to the callback URL}
        {--signup-url-name= : Only needed if the 24h signup memory has expired}
        {--display-name=GWL : Enterprise display name}';

    protected $description = 'Step 2 of the Android Enterprise bootstrap: create the enterprise and print its name for .env';

    public function handle(EnterpriseService $enterprise): int
    {
        return $this->whenMdmEnabled(function () use ($enterprise) {
            $name = $enterprise->completeSignup(
                (string) $this->argument('token'),
                $this->option('signup-url-name') ?: null,
                (string) $this->option('display-name'),
            );

            $this->info('Enterprise created. Add this to .env:');
            $this->line('');
            $this->line('ANDROID_MANAGEMENT_ENTERPRISE_ID='.$name);
            $this->line('');

            if (blank(config('gwl.mdm_pubsub_topic'))) {
                $this->warn('GWL_MDM_PUBSUB_TOPIC is not set, so no notifications are enabled yet. After creating the topic run: php artisan mdm:enterprise-notifications');
            } else {
                $this->line('The Pub/Sub topic and notification types were configured at creation.');
            }

            return Command::SUCCESS;
        });
    }
}
