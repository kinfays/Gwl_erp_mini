<?php

namespace App\Console\Commands\Mdm;

use App\Console\Commands\Mdm\Concerns\RequiresMdm;
use App\Services\Assets\Mdm\EnterpriseService;
use Illuminate\Console\Command;

class EnterpriseNotificationsCommand extends Command
{
    use RequiresMdm;

    protected $signature = 'mdm:enterprise-notifications';

    protected $description = 'Point the enterprise at the Pub/Sub topic and enable ENROLLMENT / STATUS_REPORT / COMMAND / USAGE_LOGS notifications';

    public function handle(EnterpriseService $enterprise): int
    {
        return $this->whenMdmEnabled(function () use ($enterprise) {
            $enterprise->configureNotifications();

            $this->info('Notifications enabled: '.implode(', ', EnterpriseService::NOTIFICATION_TYPES).'.');
            $this->line('Topic: '.config('gwl.mdm_pubsub_topic'));
            $this->line('Reminder: android-cloud-policy@system.gserviceaccount.com needs the Pub/Sub Publisher role on that topic.');

            return Command::SUCCESS;
        });
    }
}
