<?php

namespace App\Services\Commercial\Sms;

use App\Jobs\Commercial\SendSmsCampaignChunk;
use App\Models\CommercialSmsCampaign;
use App\Models\CommercialSmsRecipient;
use App\Models\CommercialSmsTemplate;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY place an SMS campaign changes state. Every change re-reads the campaign inside a transaction (lockForUpdate),
 * authorises on that copy, and is audited with ids, counts, sender ID and the audience RULES, never a number or a message text.
 *
 * Never send twice. A recipient moves pending -> claimed -> submitted -> delivered | failed, or ends "unknown":
 *   - the claim is ONE atomic UPDATE ... WHERE status = 'pending' carrying a token, made BEFORE the provider is called, so a retried
 *     job, a second worker or a double dispatch finds nothing left to claim;
 *   - a crash after the call and before the result is saved leaves the row 'claimed'; after commercial_sms_unknown_after_minutes it
 *     is marked 'unknown', shown as such, and NEVER retried automatically;
 *   - a timeout after the request left is 'unknown' too; only a refusal (the provider certainly did not take it) is 'failed';
 *   - one chunk of one campaign runs at a time (cache lock) and one campaign per region at a time.
 * The numbers and the merged texts live in memory for the length of a chunk and nowhere else.
 */
class SmsCampaignService
{
    public function __construct(
        protected SmsAudienceResolver $audiences,
        protected SmsMessageBuilder $messages,
        protected SmsBalanceService $balance,
        protected SmsGateway $gateway,
    ) {
    }

    // ---------------------------------------------------------------- what the sender may choose

    /** @return list<string> the approved sender IDs; the first is the default */
    public function senderIds(): array
    {
        return array_values(array_filter(array_map('trim', (array) config('gwl.arkesel.sender_ids', []))));
    }

    /** Whether the person may merge a customer's name into a message (names are personal data). */
    public function mayUseName(User $user): bool
    {
        return $user->hasRoles('super_admin') || $user->hasPermission('commercial.view_customer_details');
    }

    /** First numbers only: the audience a campaign is made from, as its rules. @return array<string, mixed> */
    protected function audienceSpec(array $input): array
    {
        return $this->audiences->normalise(array_merge($input, ['include_second' => false]));
    }

    // ---------------------------------------------------------------- preview (changes nothing)

    /**
     * Everything the send screen shows before anything is sent.
     *
     * @return array<string, mixed>
     */
    public function preview(User $user, array $input): array
    {
        SmsAccess::authorize($user, SmsAccess::SEND);

        $regionId = (int) SmsAccess::campaignRegionId($user);
        $spec = $this->audienceSpec((array) ($input['audience'] ?? []));
        $counts = $this->audiences->counts($spec, [$regionId]);
        $message = $this->messages->inspect((string) ($input['body'] ?? ''));
        $credits = $counts['numbers'] * $message['segments'];
        $details = $this->mayUseName($user);
        $sample = $this->audiences->sample($spec, [$regionId], $details);

        return [
            'spec' => $spec,
            'definition' => $this->audiences->describe($spec),
            'counts' => $counts,
            'message' => $message,
            'credits' => $credits,
            'sample' => $sample,
            'balance' => SmsAccess::allows($user, SmsAccess::BALANCE) ? $this->balance->current() : null,
            'covers' => $this->balance->covers($credits),
            'max_recipients' => (int) config('gwl.commercial_sms_max_recipients'),
        ];
    }

    // ---------------------------------------------------------------- create

    /**
     * Files a campaign: validates everything again on the server, then queues it (or schedules it).
     *
     * $input: audience[] (see SmsAudienceResolver::normalise), sender_id, body, template_id?, name?, scheduled_at?,
     * confirm_count? (the recipient count typed in), confirm_checked? (the box for small campaigns), submission_token.
     */
    public function create(User $user, array $input): CommercialSmsCampaign
    {
        SmsAccess::authorize($user, SmsAccess::SEND);

        $regionId = (int) SmsAccess::campaignRegionId($user);
        $spec = $this->audienceSpec((array) ($input['audience'] ?? []));
        $body = trim((string) ($input['body'] ?? ''));
        $sender = trim((string) ($input['sender_id'] ?? ''));

        if (! in_array($sender, $this->senderIds(), true)) {
            throw new SmsCampaignException('Choose one of the approved sender IDs.');
        }

        $message = $this->checkBody($user, $body);
        $counts = $this->audiences->counts($spec, [$regionId]);
        $recipients = $counts['numbers'];
        $max = (int) config('gwl.commercial_sms_max_recipients');

        if ($recipients < 1) {
            throw new SmsCampaignException('No customer in this audience can be messaged: none has a valid number.');
        }

        if ($recipients > $max) {
            throw new SmsCampaignException('This audience has '.number_format($recipients).' recipients; one campaign may have at most '.number_format($max).'. Narrow it down.');
        }

        $credits = $recipients * $message['segments'];
        $scheduledAt = $this->scheduleTime($input['scheduled_at'] ?? null);
        $covers = $this->balance->covers($credits, true);

        if (! $covers['ok']) {
            throw new SmsCampaignException((string) $covers['reason']);
        }

        // Typing the recipient count (or ticking the box for a small campaign) is the confirmation.
        if ($recipients >= 20) {
            if ((int) ($input['confirm_count'] ?? -1) !== $recipients) {
                throw new SmsCampaignException('Type the number of recipients ('.number_format($recipients).') to confirm.');
            }
        } elseif (empty($input['confirm_checked'])) {
            throw new SmsCampaignException('Tick the box to confirm that you want to send this message.');
        }

        // A double click or a repeated submit carries the same token: only the first one files a campaign.
        $token = (string) ($input['submission_token'] ?? '');

        if ($token === '' || ! Cache::add('commercial:sms:submitted:'.$user->id.':'.$token, 1, 900)) {
            throw new SmsCampaignException('This campaign has already been submitted. Check the campaigns list.');
        }

        $template = ! empty($input['template_id']) ? SmsAccess::scopeRegion(CommercialSmsTemplate::query(), $user)->whereKey((int) $input['template_id'])->first() : null;

        $campaign = DB::transaction(function () use ($user, $regionId, $spec, $body, $sender, $message, $counts, $recipients, $credits, $scheduledAt, $template, $input): CommercialSmsCampaign {
            $campaign = CommercialSmsCampaign::query()->create([
                'region_id' => $regionId,
                'created_by' => $user->id,
                'name' => ($name = trim((string) ($input['name'] ?? ''))) !== '' ? Str::limit($name, 120, '') : null,
                'status' => $scheduledAt ? CommercialSmsCampaign::STATUS_SCHEDULED : CommercialSmsCampaign::STATUS_QUEUED,
                'audience_type' => $spec['type'],
                'audience' => $spec,
                'include_second_number' => false,
                'include_missing' => (bool) $spec['include_missing'],
                'sender_id' => $sender,
                'template_id' => $template?->id,
                'body' => $body,
                'personalised' => $message['personalised'],
                'encoding' => $message['encoding'],
                'segments' => $message['segments'],
                'accounts_total' => $counts['reachable'],
                'recipients_total' => $recipients,
                'credits_estimated' => $credits,
                'phase' => 'new',
                'scheduled_at' => $scheduledAt,
            ]);

            $this->audit($campaign, 'commercial.sms_campaign_created', ['recipients' => $recipients, 'accounts' => $counts['reachable'], 'credits_estimated' => $credits]);

            if ($scheduledAt) {
                $this->audit($campaign, 'commercial.sms_campaign_scheduled', ['scheduled_at' => $scheduledAt->toIso8601String()]);
            }

            return $campaign;
        });

        if (! $scheduledAt) {
            SendSmsCampaignChunk::dispatch($campaign->id);
        }

        return $campaign;
    }

    /**
     * The checks every message text must pass, for a campaign and for a template.
     *
     * @return array{fields: list<string>, personalised: bool, unknown: list<string>, encoding: string, segments: int, length: int}
     */
    public function checkBody(User $user, string $body): array
    {
        if ($body === '') {
            throw new SmsCampaignException('Write the message.');
        }

        if (mb_strlen($body) > 1000) {
            throw new SmsCampaignException('The message is too long (1,000 characters at most).');
        }

        $message = $this->messages->inspect($body);

        if ($message['unknown'] !== []) {
            throw new SmsCampaignException('Unknown merge field: {'.implode('}, {', $message['unknown']).'}. You can use {name}, {account_no} and {balance}.');
        }

        if (in_array('name', $message['fields'], true) && ! $this->mayUseName($user)) {
            throw new SmsCampaignException('{name} needs the permission to see customer names. Take it out of the message.');
        }

        return $message;
    }

    protected function scheduleTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $time = Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new SmsCampaignException('That date and time cannot be read.');
        }

        if ($time->lt(now()->addMinute())) {
            throw new SmsCampaignException('Pick a time at least a minute from now, or leave it empty to send now.');
        }

        if ($time->gt(now()->addDays(90))) {
            throw new SmsCampaignException('A campaign can be scheduled at most 90 days ahead.');
        }

        return $time;
    }

    // ---------------------------------------------------------------- cancel

    /** Cancels a campaign that has not started, or stops a running one cleanly after the chunk in hand. */
    public function cancel(User $user, int $campaignId): CommercialSmsCampaign
    {
        SmsAccess::authorize($user, SmsAccess::SEND);
        SmsAccess::findCampaign($campaignId, $user);   // 404 for another region's campaign

        return DB::transaction(function () use ($user, $campaignId): CommercialSmsCampaign {
            $campaign = CommercialSmsCampaign::query()->lockForUpdate()->findOrFail($campaignId);

            // Authorise on the re-read copy.
            abort_unless(SmsAccess::canSeeRegion($user, (int) $campaign->region_id), 404);

            if (! $campaign->isOpen()) {
                throw new SmsCampaignException('This campaign has already finished.');
            }

            if ($campaign->status === CommercialSmsCampaign::STATUS_RUNNING || $campaign->status === CommercialSmsCampaign::STATUS_CANCELLING) {
                $campaign->forceFill(['status' => CommercialSmsCampaign::STATUS_CANCELLING, 'cancel_requested' => true])->save();
            } else {
                $campaign->forceFill(['status' => CommercialSmsCampaign::STATUS_CANCELLED, 'cancel_requested' => true, 'cancelled_at' => now(), 'finished_at' => now()])->save();
            }

            $this->audit($campaign, 'commercial.sms_campaign_cancelled', ['by' => $user->id, 'while' => $campaign->status]);

            return $campaign;
        });
    }

    // ---------------------------------------------------------------- templates

    public function saveTemplate(User $user, ?int $id, string $name, string $body): CommercialSmsTemplate
    {
        SmsAccess::authorize($user, SmsAccess::SEND);

        $regionId = (int) SmsAccess::campaignRegionId($user);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new SmsCampaignException('Give the template a name (80 characters at most).');
        }

        $this->checkBody($user, trim($body));

        $template = $id ? SmsAccess::scopeRegion(CommercialSmsTemplate::query(), $user)->findOrFail($id) : new CommercialSmsTemplate(['region_id' => $regionId, 'created_by' => $user->id]);

        if (CommercialSmsTemplate::query()->where('region_id', $template->region_id ?: $regionId)->where('name', $name)->when($template->exists, fn ($q) => $q->whereKeyNot($template->id))->exists()) {
            throw new SmsCampaignException('A template with that name already exists.');
        }

        $template->forceFill(['name' => $name, 'body' => trim($body)])->save();

        Audit::log('commercial.sms_template_saved', Permission::MODULE_COMMERCIAL, CommercialSmsTemplate::class, $template->id, ['by' => $user->id, 'fields' => $this->messages->inspect($body)['fields']]);

        return $template;
    }

    public function deleteTemplate(User $user, int $id): void
    {
        SmsAccess::authorize($user, SmsAccess::SEND);

        $template = SmsAccess::scopeRegion(CommercialSmsTemplate::query(), $user)->findOrFail($id);
        $template->delete();

        Audit::log('commercial.sms_template_deleted', Permission::MODULE_COMMERCIAL, CommercialSmsTemplate::class, $id, ['by' => $user->id]);
    }

    // ---------------------------------------------------------------- running (called by the queued job and the scheduler)

    /**
     * Does one step of a campaign: start it, build its recipients, or send one chunk. Returns what the caller should do next:
     * 'again' (queue another step after the configured pause), 'wait' (something else holds the campaign or the region; try later)
     * or 'done'. Safe to call twice at once and after a crash.
     */
    public function runChunk(int $campaignId): string
    {
        $lock = Cache::lock('commercial:sms:campaign:'.$campaignId, 300);

        if (! $lock->get()) {
            return 'wait';
        }

        try {
            $campaign = $this->begin($campaignId);

            if (is_string($campaign)) {
                return $campaign;
            }

            if (in_array($campaign->phase, ['new', 'materialising'], true)) {
                $blocked = $this->prepareRecipients($campaign);

                if ($blocked !== null) {
                    return 'done';
                }

                $campaign = $campaign->fresh();
            }

            $this->sweepUnknown($campaign);

            if ($this->cancelRequested($campaign->id)) {
                $this->finish($campaign->fresh(), CommercialSmsCampaign::STATUS_CANCELLED);

                return 'done';
            }

            return $this->sendNext($campaign->fresh());
        } finally {
            $lock->release();
        }
    }

    /** Moves a queued / scheduled campaign to running (unless the region is busy), or ends it. @return CommercialSmsCampaign|string */
    protected function begin(int $campaignId): CommercialSmsCampaign|string
    {
        return DB::transaction(function () use ($campaignId): CommercialSmsCampaign|string {
            $campaign = CommercialSmsCampaign::query()->lockForUpdate()->find($campaignId);

            if (! $campaign || ! $campaign->isOpen()) {
                return 'done';
            }

            // Jobs have no signed-in user: the region and the creator are checked again here.
            if (! SmsAccess::campaignMayRun($campaign)) {
                $this->end($campaign, CommercialSmsCampaign::STATUS_BLOCKED, 'This campaign can no longer run: SMS is switched off, or its region or its creator is no longer allowed.');

                return 'done';
            }

            if ($campaign->status === CommercialSmsCampaign::STATUS_SCHEDULED && $campaign->scheduled_at?->isFuture()) {
                return 'done';
            }

            if ($campaign->cancel_requested || $campaign->status === CommercialSmsCampaign::STATUS_CANCELLING) {
                return $campaign;
            }

            if (in_array($campaign->status, [CommercialSmsCampaign::STATUS_QUEUED, CommercialSmsCampaign::STATUS_SCHEDULED], true)) {
                $busy = CommercialSmsCampaign::query()->where('region_id', $campaign->region_id)->whereKeyNot($campaign->id)
                    ->whereIn('status', [CommercialSmsCampaign::STATUS_RUNNING, CommercialSmsCampaign::STATUS_CANCELLING])->exists();

                if ($busy) {
                    return 'wait';   // one campaign at a time per region, to respect the provider's rate limit
                }

                $campaign->forceFill(['status' => CommercialSmsCampaign::STATUS_RUNNING, 'started_at' => $campaign->started_at ?? now(), 'phase' => 'materialising'])->save();
                $this->audit($campaign, 'commercial.sms_campaign_started', ['recipients_estimated' => $campaign->recipients_total]);
            }

            return $campaign;
        });
    }

    /** Builds the recipients (idempotent), then checks the size and the cost once more. Returns a reason when the campaign was stopped. */
    protected function prepareRecipients(CommercialSmsCampaign $campaign): ?string
    {
        if ($campaign->cancel_requested) {
            $this->finish($campaign, CommercialSmsCampaign::STATUS_CANCELLED);

            return 'cancelled';
        }

        try {
            $result = $this->audiences->materialise($campaign);
        } catch (SmsAudienceException) {
            $this->end($campaign, CommercialSmsCampaign::STATUS_BLOCKED, 'The audience could not be built: its region is no longer one SMS is switched on for.');

            return 'blocked';
        }

        $max = (int) config('gwl.commercial_sms_max_recipients');

        if ($result['recipients'] > $max) {
            $this->end($campaign, CommercialSmsCampaign::STATUS_BLOCKED, 'The audience grew past the limit of '.number_format($max).' recipients before it started.');

            return 'blocked';
        }

        $credits = $result['recipients'] * (int) $campaign->segments;
        $covers = $this->balance->covers($credits, true);

        if (! $covers['ok']) {
            $this->end($campaign, CommercialSmsCampaign::STATUS_BLOCKED, (string) $covers['reason']);

            return 'blocked';
        }

        $campaign->forceFill(['phase' => 'sending', 'recipients_total' => $result['recipients'], 'accounts_total' => $result['accounts'], 'credits_estimated' => $credits])->save();

        return null;
    }

    protected function cancelRequested(int $id): bool
    {
        return (bool) DB::table('commercial_sms_campaigns')->where('id', $id)->where(fn ($q) => $q->where('cancel_requested', true)->orWhere('status', CommercialSmsCampaign::STATUS_CANCELLING))->exists();
    }

    /** Claimed messages nobody saved a result for are "unknown": possibly sent, never retried. */
    public function sweepUnknown(CommercialSmsCampaign $campaign): int
    {
        return DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('status', CommercialSmsRecipient::CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(max(1, (int) config('gwl.commercial_sms_unknown_after_minutes', 15))))
            ->update(['status' => CommercialSmsRecipient::UNKNOWN, 'error_code' => 'not_saved']);
    }

    /** One chunk: claim, read the numbers, send, save. @return string again|done */
    protected function sendNext(CommercialSmsCampaign $campaign): string
    {
        $size = max(1, (int) config('gwl.commercial_sms_chunk_size', 200));
        $token = Str::random(16);

        $ids = DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('status', CommercialSmsRecipient::PENDING)->orderBy('id')->limit($size)->pluck('id')->all();

        if ($ids === []) {
            // Nothing left to claim. Messages a dead worker left claimed are swept to "unknown" after a while; wait for them.
            $claimed = DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('status', CommercialSmsRecipient::CLAIMED)->exists();

            if ($claimed) {
                return 'wait';   // nothing to claim; the dead worker's messages are swept to "unknown" after a while
            }

            $this->finish($campaign, CommercialSmsCampaign::STATUS_COMPLETED);

            return 'done';
        }

        // THE CLAIM: one atomic UPDATE. Whoever else tries the same rows finds them no longer pending.
        DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('status', CommercialSmsRecipient::PENDING)->whereIn('id', $ids)
            ->update(['status' => CommercialSmsRecipient::CLAIMED, 'claim_token' => $token, 'claimed_at' => now()]);

        $rows = DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('claim_token', $token)->where('status', CommercialSmsRecipient::CLAIMED)->get(['id', 'customer_id', 'slot']);

        if ($rows->isEmpty()) {
            return 'wait';
        }

        $outcome = $this->deliver($campaign, $rows);

        DB::table('commercial_sms_campaigns')->where('id', $campaign->id)->update([
            'cursor' => (int) $rows->max('id'),
            'credits_used' => DB::raw('credits_used + '.(int) $outcome['credits']),
            'updated_at' => now(),
        ]);

        if ($outcome['calls'] > 0 && $outcome['accepted'] === 0 && $outcome['stop_code'] !== null) {
            $this->end($campaign->fresh(), CommercialSmsCampaign::STATUS_FAILED, 'Stopped: the SMS provider accepted none of the last messages ('.$outcome['stop_code'].'). Nothing more was sent.');

            return 'done';
        }

        return 'again';
    }

    /**
     * Reads the numbers (in memory), applies the audience rules once more, calls the provider, and saves the results.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array{calls: int, accepted: int, credits: int, stop_code: string|null}
     */
    protected function deliver(CommercialSmsCampaign $campaign, $rows): array
    {
        $customerIds = $rows->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
        $contacts = DB::table('commercial_customer_contacts')->whereIn('customer_id', $customerIds)->get(['customer_id', 'phone_primary', 'phone_secondary', 'account_name'])->keyBy('customer_id');

        // The audience rules again, inside the job: a customer who has since left the region or the file is not messaged.
        $regionIds = SmsAccess::audienceRegionIds($campaign);
        $eligible = DB::table('commercial_customers')->whereIn('id', $customerIds)->whereIn('region_id', $regionIds ?: [0])
            ->when(! $campaign->include_missing, fn ($q) => $q->whereNull('missing_since_batch_id'))->get(['id', 'account_no', 'balance'])->keyBy('id');

        $results = [];
        $send = [];
        $seen = [];

        foreach ($rows as $row) {
            $contact = $contacts[$row->customer_id] ?? null;
            $number = $contact ? ((int) $row->slot === 2 ? $contact->phone_secondary : $contact->phone_primary) : null;

            if (! $eligible->has($row->customer_id)) {
                $results[$row->id] = [CommercialSmsRecipient::FAILED, 'not_eligible', null];
            } elseif ($number === null || $number === '') {
                $results[$row->id] = [CommercialSmsRecipient::FAILED, 'no_number', null];
            } elseif (isset($seen[$number])) {
                $results[$row->id] = [CommercialSmsRecipient::FAILED, 'duplicate_number', null];   // one message per number, whatever happens
            } else {
                $seen[$number] = true;
                $send[] = ['id' => (int) $row->id, 'number' => (string) $number, 'contact' => $contact, 'customer' => $eligible[$row->customer_id]];
            }
        }

        $calls = 0;
        $accepted = 0;
        $credits = 0;
        $stop = null;

        $apply = function (array $items, SmsSendResult $result, int $segments) use (&$results, &$accepted, &$credits, &$stop): void {
            foreach ($items as $item) {
                $number = $item['number'];

                if ($result->outcome === 'ok') {
                    if (array_key_exists($number, $result->accepted)) {
                        $results[$item['id']] = [CommercialSmsRecipient::SUBMITTED, null, $result->accepted[$number]];
                        $accepted++;
                        $credits += $segments;
                    } else {
                        $results[$item['id']] = [CommercialSmsRecipient::FAILED, $result->rejected[$number] ?? 'not_accepted', null];
                    }
                } elseif ($result->outcome === 'failed') {
                    $results[$item['id']] = [CommercialSmsRecipient::FAILED, $result->code, null];
                    $stop = $result->code;
                } else {
                    $results[$item['id']] = [CommercialSmsRecipient::UNKNOWN, $result->code, null];
                    $stop = $result->code;
                }
            }
        };

        if ($campaign->personalised) {
            foreach ($send as $item) {
                $text = $this->messages->render($campaign->body, [
                    'name' => (string) ($item['contact']->account_name ?? ''),
                    'account_no' => (string) $item['customer']->account_no,
                    'balance' => SmsMessageBuilder::money((int) $item['customer']->balance),
                ]);
                $segments = $this->messages->count($text)['segments'];

                $calls++;
                $apply([$item], $this->gateway->send($campaign->sender_id, $text, [$item['number']]), $segments);
            }
        } elseif ($send !== []) {
            $calls++;
            $apply($send, $this->gateway->send($campaign->sender_id, $campaign->body, array_column($send, 'number')), (int) $campaign->segments);
        }

        DB::transaction(function () use ($results): void {
            foreach ($results as $id => [$status, $code, $messageId]) {
                DB::table('commercial_sms_recipients')->where('id', $id)->where('status', CommercialSmsRecipient::CLAIMED)->update([
                    'status' => $status,
                    'error_code' => $code,
                    'provider_message_id' => $messageId,
                    'submitted_at' => $status === CommercialSmsRecipient::SUBMITTED ? now() : null,
                ]);
            }
        });

        return ['calls' => $calls, 'accepted' => $accepted, 'credits' => $credits, 'stop_code' => $accepted === 0 ? $stop : null];
    }

    // ---------------------------------------------------------------- end states

    /** Completed or cancelled: snapshot the totals, audit, forget the cached balance. */
    protected function finish(CommercialSmsCampaign $campaign, string $status): void
    {
        $totals = $this->totals($campaign->id);

        DB::transaction(function () use ($campaign, $status, $totals): void {
            $fresh = CommercialSmsCampaign::query()->lockForUpdate()->find($campaign->id);

            if (! $fresh || ! $fresh->isOpen()) {
                return;
            }

            $fresh->forceFill(['status' => $status, 'phase' => 'done', 'finished_at' => now(), 'cancelled_at' => $status === CommercialSmsCampaign::STATUS_CANCELLED ? ($fresh->cancelled_at ?? now()) : null, 'totals' => $totals])->save();
            $this->audit($fresh, $status === CommercialSmsCampaign::STATUS_COMPLETED ? 'commercial.sms_campaign_finished' : 'commercial.sms_campaign_cancelled', ['totals' => $totals, 'credits_used' => $fresh->credits_used]);
        });

        $this->balance->forget();
    }

    /** Failed or blocked: the campaign stops with a plain reason (never a number). */
    protected function end(CommercialSmsCampaign $campaign, string $status, string $reason): void
    {
        $campaign->forceFill(['status' => $status, 'phase' => 'done', 'finished_at' => now(), 'error_message' => Str::limit($reason, 250, ''), 'totals' => $this->totals($campaign->id)])->save();
        $this->audit($campaign, 'commercial.sms_campaign_'.$status, ['reason' => Str::limit($reason, 250, '')]);
        $this->balance->forget();
    }

    /** @return array<string, int> status => recipients */
    public function totals(int $campaignId): array
    {
        $totals = DB::table('commercial_sms_recipients')->where('campaign_id', $campaignId)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        ksort($totals);

        return $totals;
    }

    // ---------------------------------------------------------------- scheduler hooks

    /**
     * Starts campaigns whose time has come and picks up open campaigns nobody has touched for a few minutes (a worker that died).
     * Safe to run every minute: the chunk job is protected by the campaign lock.
     *
     * @return array{started: int, resumed: int}
     */
    public function dispatchDue(): array
    {
        $started = 0;
        $resumed = 0;

        if (! SmsAccess::enabled()) {
            return compact('started', 'resumed');
        }

        foreach (CommercialSmsCampaign::query()->where('status', CommercialSmsCampaign::STATUS_SCHEDULED)->where('scheduled_at', '<=', now())->pluck('id') as $id) {
            DB::table('commercial_sms_campaigns')->where('id', $id)->where('status', CommercialSmsCampaign::STATUS_SCHEDULED)->update(['status' => CommercialSmsCampaign::STATUS_QUEUED, 'updated_at' => now()]);
            SendSmsCampaignChunk::dispatch((int) $id);
            $started++;
        }

        $stale = CommercialSmsCampaign::query()->whereIn('status', [CommercialSmsCampaign::STATUS_QUEUED, CommercialSmsCampaign::STATUS_RUNNING, CommercialSmsCampaign::STATUS_CANCELLING])
            ->where('updated_at', '<', now()->subMinutes(3))->pluck('id');

        foreach ($stale as $id) {
            SendSmsCampaignChunk::dispatch((int) $id);
            $resumed++;
        }

        return compact('started', 'resumed');
    }

    // ---------------------------------------------------------------- delivery reports

    /**
     * Applies one delivery report (from the webhook or the poll) to the recipient it belongs to. Idempotent and forward-only: a
     * replayed or late report can never move a message back (delivered > failed > submitted > unknown).
     */
    public function applyReport(string $messageId, string $state, ?string $code = null): int
    {
        if ($messageId === '' || ! in_array($state, ['delivered', 'failed'], true)) {
            return 0;
        }

        $query = DB::table('commercial_sms_recipients')->where('provider_message_id', $messageId);

        if ($state === 'delivered') {
            return $query->whereIn('status', [CommercialSmsRecipient::SUBMITTED, CommercialSmsRecipient::UNKNOWN, CommercialSmsRecipient::FAILED])
                ->update(['status' => CommercialSmsRecipient::DELIVERED, 'delivered_at' => now(), 'error_code' => null]);
        }

        return $query->whereIn('status', [CommercialSmsRecipient::SUBMITTED, CommercialSmsRecipient::UNKNOWN])
            ->update(['status' => CommercialSmsRecipient::FAILED, 'error_code' => $code ? Str::limit(preg_replace('/[^A-Za-z0-9_.-]/', '', $code) ?? '', 30, '') : 'undelivered']);
    }

    // ---------------------------------------------------------------- audit

    /** @param  array<string, mixed>  $extra  ids, counts and rules only */
    protected function audit(CommercialSmsCampaign $campaign, string $action, array $extra = []): void
    {
        Audit::log($action, Permission::MODULE_COMMERCIAL, CommercialSmsCampaign::class, $campaign->id, [
            'region_id' => $campaign->region_id,
            'audience_type' => $campaign->audience_type,
            'audience' => $this->auditAudience($campaign),
            'sender_id' => $campaign->sender_id,
            'created_by' => $campaign->created_by,
        ] + $extra);
    }

    /** The audience RULES for the audit row: a hand-picked list is recorded as a count, not as thousands of customer ids. @return array<string, mixed> */
    protected function auditAudience(CommercialSmsCampaign $campaign): array
    {
        $audience = (array) $campaign->audience;

        if (isset($audience['customer_ids'])) {
            $audience['customer_count'] = count($audience['customer_ids']);
            unset($audience['customer_ids']);
        }

        return $audience;
    }
}
