<?php

namespace Tests\Feature\Commercial\Sms;

use App\Jobs\Commercial\SendSmsCampaignChunk;
use App\Models\AuditLog;
use App\Models\CommercialSmsCampaign;
use App\Models\User;
use App\Services\Commercial\Sms\SmsBalance;
use App\Services\Commercial\Sms\SmsCampaignException;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Services\Commercial\Sms\SmsSendResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Sending: one call per unique number, never twice (retried jobs, killed workers, double dispatch, double click), failures,
 * balance, cancel, schedule, merge fields, and the checks a job makes for itself. The provider is the fake gateway.
 */
class SmsSendingTest extends SmsTestCase
{
    protected int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_sms_chunk_size' => 10]);
    }

    protected function service(): SmsCampaignService
    {
        return app(SmsCampaignService::class);
    }

    protected function load(int $count, array $over = [], int $from = 1): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n, $over), range($from, $from + $count - 1))]]]);
    }

    /** @return array<string, mixed> */
    protected function input(array $over = []): array
    {
        return $over + [
            'audience' => ['type' => 'all'],
            'sender_id' => 'GWCL',
            'body' => 'Your water bill is ready. Please pay at any GWCL office.',
            'confirm_count' => null,
            'confirm_checked' => true,
            'submission_token' => 'token-'.++$this->token,
        ];
    }

    /** Files a campaign for $count customers, confirming it the way the screen does. */
    protected function file(User $user, int $count, array $over = []): CommercialSmsCampaign
    {
        return $this->service()->create($user, $this->input(['confirm_count' => $count] + $over));
    }

    /** @return array<string, int> status => recipients */
    protected function totals(CommercialSmsCampaign $campaign): array
    {
        $totals = $this->service()->totals($campaign->id);
        ksort($totals);

        return $totals;
    }

    // ---------------------------------------------------------------- one call per number

    public function test_every_unique_number_gets_exactly_one_message_in_chunks(): void
    {
        $this->load(25, ['mobile' => null]);   // 25 customers, then give 23 distinct numbers and two that share one
        foreach (range(1, 25) as $n) {
            DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId($n))->update(['phone_primary' => $n <= 2 ? '0241112223' : '024'.str_pad((string) (1000 + $n), 7, '0', STR_PAD_LEFT), 'mobiles' => 'x']);
        }

        $officer = $this->westOfficer();
        $campaign = $this->file($officer, 24);

        $this->assertSame(CommercialSmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertCount(3, $this->sms->sent, '24 numbers in chunks of 10');
        $numbers = $this->sms->allNumbers();
        $this->assertCount(24, $numbers);
        $this->assertCount(24, array_unique($numbers), 'no number twice');
        $this->assertSame(['submitted' => 24], $this->totals($campaign));
        $this->assertSame(24, $campaign->fresh()->credits_used);
        $this->assertSame(24, $campaign->fresh()->recipients_total);
        $this->assertSame(['GWCL'], array_unique(array_column($this->sms->sent, 'sender')));
        $this->assertSame($campaign->fresh()->totals, ['submitted' => 24], 'the totals are snapshotted when it finishes');
    }

    public function test_a_killed_worker_leaves_its_chunk_unknown_and_nothing_is_sent_twice(): void
    {
        $this->load(30);
        $calls = 0;
        $this->sms->onSend = function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('worker killed');   // after the provider was called, before the result was saved
            }
        };

        $officer = $this->westOfficer();

        try {
            $this->file($officer, 30);
            $this->fail('the kill should surface');
        } catch (RuntimeException $e) {
            $this->assertSame('worker killed', $e->getMessage());
        }

        $campaign = CommercialSmsCampaign::query()->firstOrFail();
        $this->assertSame(['claimed' => 10, 'pending' => 10, 'submitted' => 10], $this->totals($campaign), 'chunk 1 saved, chunk 2 claimed and lost, chunk 3 untouched');
        $sentBefore = $this->sms->allNumbers();
        $this->sms->onSend = null;

        // a restart straight away must not touch the claimed chunk: it waits for it to become "unknown"
        $this->assertSame('again', $this->service()->runChunk($campaign->id), 'the pending chunk is sent');
        $this->assertSame('wait', $this->service()->runChunk($campaign->id), 'and now only the dead run\'s claimed rows remain: wait');
        $this->assertCount(30, $this->sms->allNumbers(), 'chunk 1, the lost chunk 2 (the provider did get it) and now chunk 3');

        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame('done', $this->service()->runChunk($campaign->id));

        $this->assertSame(CommercialSmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertSame(['submitted' => 20, 'unknown' => 10], $this->totals($campaign));
        $all = $this->sms->allNumbers();
        $this->assertCount(30, $all, 'ten numbers were sent by the dead worker and are not sent again; the rest once');
        $this->assertCount(30, array_unique($all), 'no number was sent twice');
        $this->assertSame(10, DB::table('commercial_sms_recipients')->where('error_code', 'not_saved')->count());
        Carbon::setTestNow();
    }

    public function test_a_repeated_dispatch_or_a_second_worker_sends_nothing_twice(): void
    {
        $this->load(12);
        $campaign = $this->file($this->westOfficer(), 12);
        $this->assertSame(2, count($this->sms->sent));

        // the campaign is over: any number of further dispatches do nothing
        SendSmsCampaignChunk::dispatch($campaign->id);
        SendSmsCampaignChunk::dispatch($campaign->id);
        $this->assertSame('done', $this->service()->runChunk($campaign->id));
        $this->assertCount(2, $this->sms->sent);

        // a second worker arriving while the first holds the campaign does nothing
        $this->load(12, [], 101);
        $other = CommercialSmsCampaign::query()->create($this->row($campaign, ['status' => 'queued', 'phase' => 'new']));
        $lock = Cache::lock('commercial:sms:campaign:'.$other->id, 60);
        $this->assertTrue($lock->get());
        $this->assertSame('wait', $this->service()->runChunk($other->id));
        $lock->release();
        $this->assertCount(2, $this->sms->sent, 'the held campaign was not touched');
    }

    public function test_rows_claimed_by_someone_else_are_never_sent_by_this_run(): void
    {
        $this->load(20);
        Queue::fake();
        $campaign = $this->file($this->westOfficer(), 20);
        $this->assertSame([], $this->sms->sent, 'nothing runs while the queue is faked');

        $this->service()->runChunk($campaign->id);   // materialises and sends chunk 1
        $this->assertCount(1, $this->sms->sent);

        // another worker claimed the next ten between our two steps
        DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('status', 'pending')->update(['status' => 'claimed', 'claim_token' => 'other-worker', 'claimed_at' => now()]);
        $this->assertSame('wait', $this->service()->runChunk($campaign->id));
        $this->assertCount(1, $this->sms->sent, 'we did not send what they claimed');
    }

    public function test_a_double_click_files_one_campaign(): void
    {
        $this->load(5);
        $officer = $this->westOfficer();
        $input = $this->input(['confirm_checked' => true]);

        $this->service()->create($officer, $input);

        try {
            $this->service()->create($officer, $input);
            $this->fail('the second submit must be refused');
        } catch (SmsCampaignException $e) {
            $this->assertStringContainsString('already been submitted', $e->getMessage());
        }

        $this->assertSame(1, CommercialSmsCampaign::query()->count());
        $this->assertCount(1, $this->sms->sent);
    }

    // ---------------------------------------------------------------- failures

    public function test_a_refusal_marks_the_chunk_failed_and_stops_the_campaign_a_timeout_marks_it_unknown(): void
    {
        $this->load(30);
        $this->sms->nextSend = SmsSendResult::failed('arkesel_102');

        $campaign = $this->file($this->westOfficer(), 30);

        $campaign = $campaign->fresh();
        $this->assertSame(CommercialSmsCampaign::STATUS_FAILED, $campaign->status);
        $this->assertStringContainsString('arkesel_102', (string) $campaign->error_message);
        $this->assertSame(['failed' => 10, 'pending' => 20], $this->totals($campaign));
        $this->assertSame(0, $campaign->credits_used);
        $this->assertCount(1, $this->sms->sent, 'it stopped instead of failing every chunk');
        $this->assertSame(10, DB::table('commercial_sms_recipients')->where('error_code', 'arkesel_102')->count());

        // a timeout after the request left: unknown, never retried
        $this->load(30, [], 201);
        $this->sms->sent = [];
        $this->sms->nextSend = SmsSendResult::unknown('timeout');
        $second = $this->service()->create($this->westOfficer('910009'), $this->input(['confirm_count' => 30]));

        $this->assertSame(CommercialSmsCampaign::STATUS_FAILED, $second->fresh()->status);
        $this->assertSame(10, DB::table('commercial_sms_recipients')->where('campaign_id', $second->id)->where('status', 'unknown')->count());
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('done', $this->service()->runChunk($second->id));
        $this->assertCount(1, $this->sms->sent, 'an unknown message is not retried by a restart');
    }

    public function test_numbers_the_provider_rejects_are_failed_individually_and_the_campaign_goes_on(): void
    {
        $this->load(10);
        $this->sms->nextSend = fn ($sender, $message, array $numbers) => SmsSendResult::ok(array_fill_keys(array_slice($numbers, 1), 'm'), [$numbers[0] => 'invalid_number']);

        $campaign = $this->file($this->westOfficer(), 10);

        $this->assertSame(CommercialSmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertSame(['failed' => 1, 'submitted' => 9], $this->totals($campaign));
        $this->assertSame(9, $campaign->fresh()->credits_used);
    }

    // ---------------------------------------------------------------- balance, validation, confirmation

    public function test_not_enough_credit_blocks_the_campaign_before_anything_is_filed_or_sent(): void
    {
        $this->load(30);
        $this->sms->balance = SmsBalance::of(25);

        try {
            $this->file($this->westOfficer(), 30);
            $this->fail('30 messages cost 30 credits');
        } catch (SmsCampaignException $e) {
            $this->assertStringContainsString('estimate', $e->getMessage());
        }

        $this->assertSame(0, CommercialSmsCampaign::query()->count());
        $this->assertSame([], $this->sms->sent);

        // an unreadable balance blocks too
        Cache::flush();
        $this->sms->balance = SmsBalance::unavailable('http_500');
        $this->expectException(SmsCampaignException::class);
        $this->file($this->westOfficer('910009'), 30);
    }

    public function test_the_estimate_counts_message_parts(): void
    {
        $this->load(30);
        $this->sms->balance = SmsBalance::of(59);

        // 161 characters = 2 parts each: 60 credits needed
        try {
            $this->file($this->westOfficer(), 30, ['body' => str_repeat('a', 161)]);
            $this->fail('2 parts x 30 = 60 credits is more than 59');
        } catch (SmsCampaignException) {
            $this->addToAssertionCount(1);
        }

        Cache::flush();
        $this->sms->balance = SmsBalance::of(60);
        $campaign = $this->file($this->westOfficer('910009'), 30, ['body' => str_repeat('a', 161)]);

        $this->assertSame([2, 'gsm7', 60], [$campaign->segments, $campaign->encoding, $campaign->credits_estimated]);
        $this->assertSame(60, $campaign->fresh()->credits_used, 'each accepted message is charged its real parts');
    }

    public function test_the_sender_the_fields_and_the_confirmation_are_checked_on_the_server(): void
    {
        $this->load(30);
        $officer = $this->westOfficer();
        $expect = function (array $over, string $needle) use ($officer): void {
            try {
                $this->service()->create($officer, $this->input($over));
                $this->fail('expected a refusal containing: '.$needle);
            } catch (SmsCampaignException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        };

        $expect(['sender_id' => 'SOMEONE', 'confirm_count' => 30], 'approved sender');
        $expect(['body' => '', 'confirm_count' => 30], 'Write the message');
        $expect(['body' => 'Hi {foo}', 'confirm_count' => 30], 'Unknown merge field');
        $expect(['body' => 'Hi {name}', 'confirm_count' => 30], 'permission to see customer names');
        $expect(['confirm_count' => 29], 'Type the number of recipients (30)');
        $expect(['confirm_count' => null], 'Type the number of recipients (30)');
        $expect(['audience' => ['type' => 'group', 'group' => 'disc'], 'confirm_count' => 0], 'none has a valid number');

        $this->assertSame(0, CommercialSmsCampaign::query()->count());

        // a small campaign is confirmed with the box instead
        $this->load(3, [], 301);
        $expect(['audience' => ['type' => 'selected', 'customer_ids' => [$this->customerId(301), $this->customerId(302), $this->customerId(303)]], 'confirm_checked' => false], 'Tick the box');
        $this->assertSame(0, CommercialSmsCampaign::query()->count());
    }

    public function test_the_recipient_limit_is_enforced(): void
    {
        $this->load(30);
        config(['gwl.commercial_sms_max_recipients' => 25]);

        $this->expectException(SmsCampaignException::class);
        $this->expectExceptionMessage('at most 25');
        $this->file($this->westOfficer(), 30);
    }

    // ---------------------------------------------------------------- merge fields

    public function test_merge_fields_are_built_in_memory_one_message_each_and_never_stored(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [
            ReportWorkbooks::customer(1, ['balance' => 223.4]), ReportWorkbooks::customer(2, ['balance' => 123.4]), ReportWorkbooks::customer(3, ['balance' => -20.0]),
        ]]]]);
        $detailer = $this->userWith('910070', ['commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_customer_details'], $this->accraWest, $this->sowutuom);

        $campaign = $this->service()->create($detailer, $this->input([
            'body' => 'Dear {name}, you owe {balance} on {account_no}.',
            'confirm_count' => null,
            'confirm_checked' => true,
        ]));

        $this->assertTrue($campaign->fresh()->personalised);
        $this->assertCount(3, $this->sms->sent, 'one request per customer');
        $this->assertSame(['0240000001'], $this->sms->sent[0]['numbers']);
        $this->assertSame('Dear Customer 000001, you owe GHS 223.40 on 100000000001.', $this->sms->sent[0]['message']);
        $this->assertSame('Dear Customer 000002, you owe GHS 123.40 on 100000000002.', $this->sms->sent[1]['message']);
        $this->assertSame('Dear Customer 000003, you owe GHS 20.00 credit on 100000000003.', $this->sms->sent[2]['message']);

        // the stored thing is the template
        $this->assertSame('Dear {name}, you owe {balance} on {account_no}.', $campaign->fresh()->body);
        $this->assertSame(['Dear {name}, you owe {balance} on {account_no}.'], DB::table('commercial_sms_campaigns')->pluck('body')->all());
    }

    // ---------------------------------------------------------------- cancel and schedule

    public function test_cancelling_while_running_stops_after_the_chunk_in_hand(): void
    {
        $this->load(30);
        $officer = $this->westOfficer();
        $id = null;
        $this->sms->onSend = function () use (&$id, $officer): void {
            $id ??= CommercialSmsCampaign::query()->value('id');
            $this->service()->cancel($officer, (int) $id);
        };

        $campaign = $this->file($officer, 30);

        $campaign = $campaign->fresh();
        $this->assertSame(CommercialSmsCampaign::STATUS_CANCELLED, $campaign->status);
        $this->assertCount(1, $this->sms->sent, 'the chunk in hand finished, the rest was not sent');
        $this->assertSame(['pending' => 20, 'submitted' => 10], $this->totals($campaign));
        $this->assertNotNull($campaign->cancelled_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.sms_campaign_cancelled')->count() >= 1 ? 1 : 0);
    }

    public function test_a_scheduled_campaign_waits_can_be_cancelled_and_runs_when_due(): void
    {
        $this->load(12);
        $officer = $this->westOfficer();

        $later = $this->service()->create($officer, $this->input(['confirm_count' => 12, 'scheduled_at' => now()->addMinutes(30)->toDateTimeString()]));
        $this->assertSame(CommercialSmsCampaign::STATUS_SCHEDULED, $later->status);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame(['started' => 0, 'resumed' => 0], $this->service()->dispatchDue());

        $cancelled = $this->service()->create($officer, $this->input(['confirm_count' => 12, 'scheduled_at' => now()->addMinutes(40)->toDateTimeString()]));
        $this->service()->cancel($officer, $cancelled->id);
        $this->assertSame(CommercialSmsCampaign::STATUS_CANCELLED, $cancelled->fresh()->status);

        Carbon::setTestNow(now()->addMinutes(45));
        $this->assertSame(['started' => 1, 'resumed' => 0], $this->service()->dispatchDue(), 'the cancelled one is not started');

        $this->assertSame(CommercialSmsCampaign::STATUS_COMPLETED, $later->fresh()->status);
        $this->assertCount(2, $this->sms->sent, '12 numbers in chunks of 10, once');
        Carbon::setTestNow();
    }

    public function test_a_scheduled_time_must_be_sensible(): void
    {
        $this->load(5);

        foreach ([now()->subHour()->toDateTimeString(), now()->addDays(120)->toDateTimeString(), 'not a date'] as $bad) {
            try {
                $this->service()->create($this->westOfficer(), $this->input(['scheduled_at' => $bad]));
                $this->fail('refused: '.$bad);
            } catch (SmsCampaignException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CommercialSmsCampaign::query()->count());
    }

    public function test_one_campaign_at_a_time_per_region(): void
    {
        $this->load(12);
        Queue::fake();
        $officer = $this->westOfficer();
        $first = $this->file($officer, 12);
        $second = $this->file($officer, 12);

        DB::table('commercial_sms_campaigns')->where('id', $first->id)->update(['status' => 'running', 'phase' => 'sending']);

        $this->assertSame('wait', $this->service()->runChunk($second->id), 'the region is busy with the first campaign');
        $this->assertSame([], $this->sms->sent);

        DB::table('commercial_sms_campaigns')->where('id', $first->id)->update(['status' => 'completed']);
        $this->assertSame('again', $this->service()->runChunk($second->id));
        $this->assertNotSame([], $this->sms->sent);
    }

    public function test_run_due_picks_up_a_stalled_campaign(): void
    {
        $this->load(12);
        Queue::fake();
        $campaign = $this->file($this->westOfficer(), 12);
        Queue::assertPushed(SendSmsCampaignChunk::class, 1);

        $this->assertSame(['started' => 0, 'resumed' => 0], $this->service()->dispatchDue(), 'just filed: not stalled');

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame(['started' => 0, 'resumed' => 1], $this->service()->dispatchDue());
        Queue::assertPushed(SendSmsCampaignChunk::class, 2);
        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- the job's own checks

    public function test_a_job_stops_when_its_creator_is_no_longer_allowed_or_sms_is_switched_off(): void
    {
        $this->load(12);
        Queue::fake();
        $officer = $this->westOfficer();
        $campaign = $this->file($officer, 12);

        $officer->roles()->detach();   // lost the role since filing
        $this->assertSame('done', $this->service()->runChunk($campaign->id));

        $this->assertSame(CommercialSmsCampaign::STATUS_BLOCKED, $campaign->fresh()->status);
        $this->assertSame([], $this->sms->sent);
        $this->assertStringContainsString('no longer run', (string) $campaign->fresh()->error_message);

        // switched off
        $this->load(12, [], 101);
        $other = $this->file($this->westOfficer('910009'), 12);
        config(['gwl.commercial_sms_enabled' => false]);
        $this->assertSame('done', $this->service()->runChunk($other->id));
        $this->assertSame([], $this->sms->sent);
    }

    public function test_a_customer_who_leaves_the_region_or_the_file_before_their_chunk_is_not_messaged(): void
    {
        $this->load(30);
        $leaver = $this->customerId(25);
        $gone = $this->customerId(26);
        $first = true;
        $this->sms->onSend = function () use (&$first, $leaver, $gone): void {
            if ($first) {
                $first = false;
                DB::table('commercial_customers')->where('id', $leaver)->update(['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id]);
                DB::table('commercial_customers')->where('id', $gone)->update(['missing_since_batch_id' => 999]);
            }
        };

        $campaign = $this->file($this->westOfficer(), 30);

        $this->assertSame(['failed' => 2, 'submitted' => 28], $this->totals($campaign));
        $this->assertSame(2, DB::table('commercial_sms_recipients')->where('error_code', 'not_eligible')->count());
        $this->assertCount(28, $this->sms->allNumbers());
    }

    public function test_the_screen_and_the_job_agree_on_who_may_use_sms(): void
    {
        $this->load(5);
        Queue::fake();

        $people = [
            'west officer' => $this->westOfficer('910101'),
            'east officer' => $this->eastOfficer('910102'),
            'head office officer' => $this->headOfficeOfficer('910103'),
            'super admin' => $this->superAdmin('910104'),
            'west, no sms permission' => $this->westWith('910105', ['commercial.view_customer_analytics']),
        ];

        foreach ($people as $label => $user) {
            // the screen's decision: may they open the send route?
            $screen = $this->actingAs($user)->get(route('commercial.sms'))->status() === 200;
            // the job's decision: would a campaign they filed be allowed to run?
            $campaign = CommercialSmsCampaign::query()->create([
                'region_id' => $this->accraWest->id, 'created_by' => $user->id, 'status' => 'queued', 'audience_type' => 'all', 'audience' => ['type' => 'all'],
                'sender_id' => 'GWCL', 'body' => 'x', 'segments' => 1, 'encoding' => 'gsm7',
            ]);
            $job = \App\Services\Commercial\Sms\SmsAccess::campaignMayRun($campaign->fresh());
            $send = \App\Services\Commercial\Sms\SmsAccess::allows($user, \App\Services\Commercial\Sms\SmsAccess::SEND);

            $this->assertSame($send, $job, "{$label}: sending is allowed exactly when the job would run");

            if ($send) {
                $this->assertTrue($screen, "{$label}: the screen opens");
            }
        }

        $this->assertSame(['west officer', 'super admin'], array_keys(array_filter(array_map(fn (User $user) => \App\Services\Commercial\Sms\SmsAccess::campaignMayRun(CommercialSmsCampaign::query()->create([
            'region_id' => $this->accraWest->id, 'created_by' => $user->id, 'status' => 'queued', 'audience_type' => 'all', 'audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => 'x', 'segments' => 1, 'encoding' => 'gsm7',
        ])->fresh()), $people))));
    }

    public function test_the_screens_preview_and_the_jobs_recipients_are_the_same_audience(): void
    {
        $this->load(25);
        $officer = $this->westOfficer();

        $preview = $this->service()->preview($officer, ['audience' => ['type' => 'group', 'group' => 'actb'], 'body' => 'Hello']);
        $campaign = $this->file($officer, $preview['counts']['numbers'], ['audience' => ['type' => 'group', 'group' => 'actb']]);

        $this->assertSame($preview['counts']['numbers'], $campaign->fresh()->recipients_total);
        $this->assertSame($preview['counts']['numbers'], DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->count());
        $this->assertSame($preview['counts']['numbers'], count($this->sms->allNumbers()));
        $this->assertSame($preview['credits'], $campaign->fresh()->credits_used);
    }

    /** @return array<string, mixed> */
    private function row(CommercialSmsCampaign $like, array $over = []): array
    {
        return $over + $like->only(['region_id', 'created_by', 'audience_type', 'audience', 'sender_id', 'body', 'segments', 'encoding', 'include_second_number', 'include_missing', 'personalised']);
    }
}
