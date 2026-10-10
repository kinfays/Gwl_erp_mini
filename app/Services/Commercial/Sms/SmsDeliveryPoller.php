<?php

namespace App\Services\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Models\CommercialSmsRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Asks the provider what became of the messages it accepted, for recent campaigns (commercial:sms:poll-delivery). The webhook and
 * this poll apply reports the same way, and both are idempotent. No bulk status call is known, so it is one lookup per message,
 * limited per run and paused between lookups to respect the provider's rate limit.
 */
class SmsDeliveryPoller
{
    public function __construct(protected SmsGateway $gateway, protected SmsCampaignService $campaigns)
    {
    }

    /** @return array{asked: int, delivered: int, failed: int, still_pending: int} */
    public function poll(): array
    {
        $summary = ['asked' => 0, 'delivered' => 0, 'failed' => 0, 'still_pending' => 0];

        if (! SmsAccess::enabled() || ! config('gwl.commercial_sms_delivery_reports') || ! $this->gateway->configured()) {
            return $summary;
        }

        $budget = max(1, (int) config('gwl.commercial_sms_poll_batch', 500));
        $pause = max(0, (int) config('gwl.commercial_sms_poll_pause_ms', 100));
        $since = now()->subDays(max(1, (int) config('gwl.commercial_sms_poll_days', 3)));

        $campaigns = CommercialSmsCampaign::query()->whereIn('region_id', SmsAccess::regionIds())->where('started_at', '>=', $since)->orderBy('id')->pluck('id');

        foreach ($campaigns as $campaignId) {
            if ($budget <= 0) {
                break;
            }

            $rows = DB::table('commercial_sms_recipients')->where('campaign_id', $campaignId)->where('status', CommercialSmsRecipient::SUBMITTED)
                ->whereNotNull('provider_message_id')->orderBy('id')->limit($budget)->pluck('provider_message_id');

            foreach ($rows as $messageId) {
                $status = $this->gateway->status((string) $messageId);
                $summary['asked']++;
                $budget--;

                if ($status->state === 'delivered' || $status->state === 'failed') {
                    $this->campaigns->applyReport((string) $messageId, $status->state, $status->code);
                    $summary[$status->state]++;
                } else {
                    $summary['still_pending']++;
                }

                if ($pause > 0) {
                    usleep($pause * 1000);
                }
            }
        }

        return $summary;
    }
}
