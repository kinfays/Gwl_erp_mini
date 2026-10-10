<?php

namespace App\Jobs\Commercial;

use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies one delivery report from the provider's webhook. The payload is the provider's message id, a state and a short code:
 * no number and no text (the webhook reads the report into these three fields and throws the rest away).
 */
class ApplySmsDeliveryReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $messageId, public string $state, public ?string $code = null)
    {
    }

    public function handle(SmsCampaignService $campaigns): void
    {
        if (! SmsAccess::enabled()) {
            return;
        }

        $campaigns->applyReport($this->messageId, $this->state, $this->code);
    }
}
