<?php

namespace App\Console\Commands\Mdm;

use App\Console\Commands\Mdm\Concerns\RequiresMdm;
use App\Models\MdmEvent;
use App\Services\Assets\Mdm\AndroidManagementGateway;
use App\Services\Assets\Mdm\EventIntakeService;
use App\Services\Assets\Mdm\Exceptions\MalformedNotificationException;
use App\Services\Assets\Mdm\MdmSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pub/Sub pull delivery: used while the ERP is not publicly reachable (local Laragon) and kept as the fallback if
 * the webhook is ever down. Scheduled every minute; a no-op in push mode unless --force.
 *
 * A message is acknowledged only AFTER its mdm_events row is stored and its job dispatched. Anything that fails
 * before that is left unacknowledged and Pub/Sub redelivers it; anything already received (by push, or an earlier
 * poll) is recognised as a duplicate, acknowledged, and not processed again.
 */
class PollEventsCommand extends Command
{
    use RequiresMdm;

    protected $signature = 'mdm:poll-events
        {--force : Poll even when GWL_MDM_PUBSUB_MODE is push}
        {--max-batches=10 : Upper bound on pull requests per run}';

    protected $description = 'Pull Android Management notifications from the Pub/Sub subscription';

    public function handle(AndroidManagementGateway $gateway, EventIntakeService $intake, MdmSettings $settings): int
    {
        return $this->whenMdmEnabled(function () use ($gateway, $intake, $settings) {
            if ($settings->isPushMode() && ! $this->option('force')) {
                $this->line('GWL_MDM_PUBSUB_MODE is push; skipping (use --force to poll anyway).');

                return Command::SUCCESS;
            }

            $subscription = $settings->pubsubSubscription();
            $batchSize = max(1, (int) config('gwl.mdm_pubsub_pull_batch', 50));
            $stored = $duplicates = $skipped = 0;

            for ($batch = 0; $batch < max(1, (int) $this->option('max-batches')); $batch++) {
                $messages = $gateway->pullMessages($subscription, $batchSize);

                if ($messages === []) {
                    break;
                }

                $ackIds = [];

                foreach ($messages as $message) {
                    try {
                        $result = $intake->ingest(
                            (string) ($message['messageId'] ?? ''),
                            MdmEvent::MODE_PULL,
                            (string) ($message['data'] ?? ''),
                            (array) ($message['attributes'] ?? []),
                            $message['publishTime'] ?? null,
                        );

                        $result['duplicate'] ? $duplicates++ : $stored++;
                        $ackIds[] = $message['ackId'];
                    } catch (MalformedNotificationException $e) {
                        // Can never become valid, so redelivering it forever helps nobody.
                        Log::warning('MDM pull: dropped an undecodable Pub/Sub message.', ['message_id' => $message['messageId'] ?? null]);
                        $ackIds[] = $message['ackId'];
                        $skipped++;
                    } catch (Throwable $e) {
                        // Not stored / not queued: leave it unacknowledged so Pub/Sub redelivers it.
                        Log::error('MDM pull: could not store a Pub/Sub message; leaving it for redelivery.', [
                            'message_id' => $message['messageId'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                        $skipped++;
                    }
                }

                $gateway->acknowledgeMessages($subscription, array_values(array_filter($ackIds)));

                if (count($messages) < $batchSize) {
                    break;
                }
            }

            $this->info("MDM poll: {$stored} new, {$duplicates} already received, {$skipped} skipped.");

            return Command::SUCCESS;
        });
    }
}
