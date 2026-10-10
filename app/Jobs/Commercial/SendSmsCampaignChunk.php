<?php

namespace App\Jobs\Commercial;

use App\Services\Commercial\Sms\SmsCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs ONE step of an SMS campaign (start it, build its recipients, or send one chunk) and queues the next step after the
 * configured pause. The payload is the campaign id and nothing else: no number, no text, no name. SmsCampaignService re-checks
 * the region lock and the creator inside, because a job has no signed-in user. A crash loses nothing and repeats nothing: the
 * recipients claimed by the dead run become "unknown" after a while, and commercial:sms:run-due picks the campaign up again.
 */
class SendSmsCampaignChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Unlimited attempts: a wait is a release(), not a failure. A real exception fails the job once (maxExceptions). */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 900;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(SmsCampaignService $campaigns): void
    {
        $next = $campaigns->runChunk($this->campaignId);

        if ($next === 'again') {
            static::dispatch($this->campaignId)->delay(now()->addSeconds(max(0, (int) config('gwl.commercial_sms_chunk_delay_seconds', 2))));
        } elseif ($next === 'wait') {
            // Another worker holds the campaign, the region is busy with another campaign, or the dead run's messages are
            // not yet "unknown": look again shortly. (release(), not a new dispatch, so a synchronous queue cannot loop.)
            $this->release(30);
        }
    }

    /** Never log the exception text: it is not expected to hold a number, but nothing here needs it. */
    public function failed(Throwable $exception): void
    {
        logger()->warning('SMS campaign chunk failed', ['campaign_id' => $this->campaignId, 'exception' => $exception::class]);
    }
}
