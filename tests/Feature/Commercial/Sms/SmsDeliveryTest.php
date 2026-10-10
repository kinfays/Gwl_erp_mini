<?php

namespace Tests\Feature\Commercial\Sms;

use App\Livewire\Commercial\Sms\CampaignReport;
use App\Models\AuditLog;
use App\Models\CommercialSmsCampaign;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Services\Commercial\Sms\SmsDeliveryPoller;
use App\Services\Commercial\Sms\SmsDeliveryStatus;
use App\Services\Commercial\Sms\SmsReportService;
use App\Services\Commercial\Sms\SmsRetention;
use App\Services\Commercial\Sms\SmsSendResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\ReportWorkbooks;

/** Delivery: the webhook, the poll, the report and its export, and the retention purge. The provider is the fake gateway. */
class SmsDeliveryTest extends SmsTestCase
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

    /** A finished campaign of $count customers; the fake gives message ids fake-1 .. fake-N. */
    protected function sent(int $count = 12, array $over = []): CommercialSmsCampaign
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n), range(1, $count))]]]);

        return $this->service()->create($this->westOfficer(), $over + [
            'audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => 'Hello customer, please pay your bill.', 'confirm_count' => $count,
            'confirm_checked' => true, 'submission_token' => 'tk-'.++$this->token,
        ]);
    }

    protected function hook(array $payload, string $token = 'test-webhook-secret')
    {
        return $this->postJson('/webhooks/sms-arkesel?token='.$token, $payload);
    }

    protected function statusOf(string $messageId): string
    {
        return (string) DB::table('commercial_sms_recipients')->where('provider_message_id', $messageId)->value('status');
    }

    // ---------------------------------------------------------------- webhook

    public function test_the_webhook_refuses_a_wrong_token_and_is_off_without_one(): void
    {
        $this->sent();

        $this->hook(['id' => 'fake-1', 'state' => 'delivered'], 'wrong')->assertForbidden();
        $this->postJson('/webhooks/sms-arkesel', ['id' => 'fake-1', 'state' => 'delivered'])->assertForbidden();
        $this->assertSame('submitted', $this->statusOf('fake-1'));

        config(['gwl.arkesel.webhook_token' => '']);
        $this->hook(['id' => 'fake-1', 'state' => 'delivered'], '')->assertNotFound();

        config(['gwl.arkesel.webhook_token' => 'test-webhook-secret', 'gwl.commercial_sms_delivery_reports' => false]);
        $this->hook(['id' => 'fake-1', 'state' => 'delivered'])->assertNotFound();
        $this->assertSame('submitted', $this->statusOf('fake-1'));
    }

    public function test_a_good_report_updates_the_message_and_a_replay_changes_nothing(): void
    {
        $this->sent();

        $this->hook(['id' => 'fake-1', 'state' => 'delivered'])->assertNoContent();
        $this->assertSame('delivered', $this->statusOf('fake-1'));
        $at = DB::table('commercial_sms_recipients')->where('provider_message_id', 'fake-1')->value('delivered_at');

        Carbon::setTestNow(now()->addHour());
        $this->hook(['id' => 'fake-1', 'state' => 'delivered'])->assertNoContent();
        $this->assertSame($at, DB::table('commercial_sms_recipients')->where('provider_message_id', 'fake-1')->value('delivered_at'), 'a replay leaves it as it was');

        // a late "failed" can never undo a delivery
        $this->hook(['id' => 'fake-1', 'state' => 'failed', 'code' => 'late'])->assertNoContent();
        $this->assertSame('delivered', $this->statusOf('fake-1'));
        Carbon::setTestNow();

        $this->hook(['id' => 'fake-2', 'state' => 'failed', 'code' => 'E-12'])->assertNoContent();
        $this->assertSame('failed', $this->statusOf('fake-2'));
        $this->assertSame('E-12', DB::table('commercial_sms_recipients')->where('provider_message_id', 'fake-2')->value('error_code'));

        // a "still pending" report, an unknown id and nonsense do nothing
        $this->hook(['id' => 'fake-3', 'state' => 'pending'])->assertNoContent();
        $this->hook(['id' => 'no-such-id', 'state' => 'delivered'])->assertNoContent();
        $this->hook(['hello' => 'world'])->assertNoContent();
        $this->assertSame('submitted', $this->statusOf('fake-3'));
    }

    public function test_several_reports_in_one_call_are_all_applied(): void
    {
        $this->sent();

        $this->hook([['id' => 'fake-4', 'state' => 'delivered'], ['id' => 'fake-5', 'state' => 'failed'], ['id' => 'fake-6', 'state' => 'delivered']])->assertNoContent();

        $this->assertSame(['delivered', 'failed', 'delivered'], [$this->statusOf('fake-4'), $this->statusOf('fake-5'), $this->statusOf('fake-6')]);
    }

    public function test_an_unknown_message_can_still_be_confirmed_by_a_late_report(): void
    {
        $this->sms->nextSend = SmsSendResult::unknown('timeout');
        $campaign = $this->sent(10);

        $this->assertSame(10, DB::table('commercial_sms_recipients')->where('status', 'unknown')->count());
        $this->assertSame(0, $this->service()->applyReport('nothing', 'delivered'), 'an unknown message has no provider id to be matched by');
        $this->assertSame(CommercialSmsCampaign::STATUS_FAILED, $campaign->fresh()->status);
    }

    public function test_the_webhook_stores_nothing_from_the_report_but_its_three_fields(): void
    {
        $this->sent();

        $this->hook(['id' => 'fake-1', 'state' => 'delivered', 'recipient' => '233240000001', 'message' => 'a secret text', 'note' => 'Customer 000001'])->assertNoContent();

        foreach (DB::select("select name from sqlite_master where type = 'table' and name not in ('commercial_customer_contacts', 'commercial_customer_staging')") as $table) {
            $rows = json_encode(DB::table($table->name)->get());
            $this->assertStringNotContainsString('233240000001', $rows, $table->name);
            $this->assertStringNotContainsString('a secret text', $rows, $table->name);
        }
    }

    // ---------------------------------------------------------------- poll

    public function test_the_poll_asks_about_submitted_messages_and_applies_what_it_learns(): void
    {
        $this->sent();
        $this->sms->statuses['fake-1'] = new SmsDeliveryStatus('delivered');
        $this->sms->statuses['fake-2'] = new SmsDeliveryStatus('failed', 'X9');
        // the rest are still pending

        $result = app(SmsDeliveryPoller::class)->poll();

        $this->assertSame(['asked' => 12, 'delivered' => 1, 'failed' => 1, 'still_pending' => 10], $result);
        $this->assertSame(['delivered', 'failed', 'submitted'], [$this->statusOf('fake-1'), $this->statusOf('fake-2'), $this->statusOf('fake-3')]);

        // delivered and failed are not asked about again
        $this->sms->statusCalls = 0;
        app(SmsDeliveryPoller::class)->poll();
        $this->assertSame(10, $this->sms->statusCalls);
    }

    public function test_the_poll_is_limited_per_run_skips_old_campaigns_and_stands_down_when_reports_are_off(): void
    {
        $campaign = $this->sent();

        config(['gwl.commercial_sms_poll_batch' => 5]);
        $this->assertSame(5, app(SmsDeliveryPoller::class)->poll()['asked']);

        config(['gwl.commercial_sms_poll_batch' => 500]);
        $campaign->forceFill(['started_at' => now()->subDays(10)])->save();
        $this->assertSame(0, app(SmsDeliveryPoller::class)->poll()['asked'], 'older than the poll window');

        $campaign->forceFill(['started_at' => now()])->save();
        config(['gwl.commercial_sms_delivery_reports' => false]);
        $this->sms->statusCalls = 0;
        $this->assertSame(0, app(SmsDeliveryPoller::class)->poll()['asked']);
        $this->assertSame(0, $this->sms->statusCalls);
    }

    // ---------------------------------------------------------------- report

    public function test_the_report_totals_are_counts_with_percentages_of_the_audience(): void
    {
        $campaign = $this->sent();
        $this->hook(['id' => 'fake-1', 'state' => 'delivered']);
        $this->hook(['id' => 'fake-2', 'state' => 'delivered']);
        $this->hook(['id' => 'fake-3', 'state' => 'failed', 'code' => 'E1']);

        $summary = app(SmsReportService::class)->summary($campaign->fresh());
        $rows = collect($summary['rows'])->keyBy('key');

        $this->assertSame([12, 12, 2, 9, 1], [$rows['audience']['count'], $rows['accepted']['count'], $rows['delivered']['count'], $rows['awaiting']['count'], $rows['failed']['count']]);
        $this->assertSame(16.7, $rows['delivered']['pct']);
        $this->assertSame(100.0, $rows['accepted']['pct']);
        $this->assertSame(12, $summary['credits_used']);
        $this->assertSame(['E1' => 1], collect($summary['failures'])->pluck('count', 'code')->all());
        $this->assertContains('Finished', array_column($summary['timeline'], 'label'));
    }

    public function test_without_delivery_reports_the_report_is_accepted_only(): void
    {
        $campaign = $this->sent();
        config(['gwl.commercial_sms_delivery_reports' => false]);

        $summary = app(SmsReportService::class)->summary($campaign->fresh());

        $this->assertSame(['audience', 'accepted', 'failed', 'unknown', 'pending'], array_column($summary['rows'], 'key'));
        Livewire::actingAs($this->westOfficer())->test(CampaignReport::class, ['campaign' => $campaign->id])->assertSee('accepted only')->assertDontSee('Delivered');
    }

    public function test_the_report_screen_lists_masked_recipients_filters_by_status_and_pages_by_keyset(): void
    {
        $campaign = $this->sent(25);
        $this->hook(['id' => 'fake-1', 'state' => 'delivered']);

        $page = Livewire::actingAs($this->westOfficer())->test(CampaignReport::class, ['campaign' => $campaign->id]);
        $page->assertSee('100000000001')->assertDontSee('0240000001')->assertDontSee('024****001', false)->assertSee('Delivered');

        $page->set('status', 'delivered')->assertSee('100000000001')->assertDontSee('100000000002');

        $reports = app(SmsReportService::class);
        $first = $reports->recipients($campaign, null, null, false, 10);
        $second = $reports->recipients($campaign, null, $first['next'], false, 10);
        $this->assertCount(10, $first['rows']);
        $this->assertCount(10, $second['rows']);
        $this->assertEmpty(array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')));
        $this->assertNull($first['rows'][0]['name']);
        $this->assertNull($first['rows'][0]['mobile'], 'no numbers at all without the details permission');

        $detailed = $reports->recipients($campaign, null, null, true, 3);
        $this->assertSame('Customer 000001', $detailed['rows'][0]['name']);
        $this->assertSame('024****001', $detailed['rows'][0]['mobile']);
    }

    public function test_a_report_of_another_region_is_404_and_the_report_needs_its_permission(): void
    {
        $campaign = $this->sent();
        $eastCampaign = CommercialSmsCampaign::query()->create([
            'region_id' => $this->ashanti->id, 'created_by' => null, 'status' => 'completed', 'audience_type' => 'all', 'audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => 'x', 'segments' => 1, 'encoding' => 'gsm7',
        ]);

        $this->actingAs($this->westOfficer())->get(route('commercial.sms.campaigns.show', $campaign->id))->assertOk();
        $this->actingAs($this->westOfficer())->get(route('commercial.sms.campaigns.show', $eastCampaign->id))->assertNotFound();
        $this->actingAs($this->eastOfficer())->get(route('commercial.sms.campaigns.show', $campaign->id))->assertForbidden();
        $this->actingAs($this->westWith('910200', ['commercial.send_sms']))->get(route('commercial.sms.campaigns.show', $campaign->id))->assertForbidden();
    }

    public function test_the_export_is_masked_text_capped_audited_and_needs_both_permissions(): void
    {
        $campaign = $this->sent();
        $detailer = $this->userWith('910210', ['commercial.send_sms', 'commercial.view_sms_reports', 'commercial.export_reports', 'commercial.view_customer_details'], $this->accraWest, $this->sowutuom);

        $response = $this->actingAs($detailer)->get(route('commercial.sms.campaigns.export', $campaign->id))->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $rows = $sheet->toArray(null, false, false, false);

        $this->assertSame([...SmsReportService::HEADINGS, ...SmsReportService::PII_HEADINGS], $rows[0]);
        $this->assertCount(13, $rows);
        $this->assertSame('100000000001', $rows[1][0]);
        $this->assertSame('024****001', $rows[1][7]);
        $this->assertSame('Customer 000001', $rows[1][6]);
        $this->assertStringNotContainsString('0240000001', json_encode($rows));
        $this->assertContains($sheet->getCell('A2')->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE]);

        $audit = AuditLog::query()->where('action', 'commercial.sms_report_exported')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('0240000001', json_encode($audit->toArray()));

        // without the details permission: no names, no numbers
        $plain = $this->userWith('910211', ['commercial.send_sms', 'commercial.view_sms_reports', 'commercial.export_reports'], $this->accraWest, $this->sowutuom);
        $plainRows = IOFactory::load($this->actingAs($plain)->get(route('commercial.sms.campaigns.export', $campaign->id))->baseResponse->getFile()->getPathname())->getSheet(0)->toArray(null, false, false, false);
        $this->assertSame(SmsReportService::HEADINGS, $plainRows[0]);

        // both permissions are needed
        $this->actingAs($this->westWith('910212', ['commercial.view_sms_reports']))->get(route('commercial.sms.campaigns.export', $campaign->id))->assertForbidden();
        $this->actingAs($this->westWith('910213', ['commercial.export_reports']))->get(route('commercial.sms.campaigns.export', $campaign->id))->assertForbidden();
        $this->actingAs($this->eastOfficer())->get(route('commercial.sms.campaigns.export', $campaign->id))->assertForbidden();

        // the row cap
        config(['gwl.commercial_customer_export_max_rows' => 5]);
        $capped = IOFactory::load($this->actingAs($detailer)->get(route('commercial.sms.campaigns.export', $campaign->id))->baseResponse->getFile()->getPathname())->getSheet(0)->toArray(null, false, false, false);
        $this->assertCount(7, $capped, 'a header, five rows and the note');
        $this->assertStringContainsString('Limited to the first 5 rows', (string) $capped[6][0]);
    }

    public function test_a_name_that_looks_like_a_formula_stays_text_in_the_export(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['name' => '=HYPERLINK("http://x.test")'])]]]]);
        $detailer = $this->userWith('910214', ['commercial.send_sms', 'commercial.view_sms_reports', 'commercial.export_reports', 'commercial.view_customer_details'], $this->accraWest, $this->sowutuom);
        $campaign = $this->service()->create($detailer, ['audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => 'Hi', 'confirm_checked' => true, 'submission_token' => 'f-1']);

        $sheet = IOFactory::load($this->actingAs($detailer)->get(route('commercial.sms.campaigns.export', $campaign->id))->baseResponse->getFile()->getPathname())->getSheet(0);

        $this->assertFalse($sheet->getCell('G2')->isFormula());
        $this->assertSame('=HYPERLINK("http://x.test")', (string) $sheet->getCell('G2')->getValue());
    }

    // ---------------------------------------------------------------- retention

    public function test_retention_deletes_recipient_rows_but_keeps_the_totals_and_the_report(): void
    {
        $old = $this->sent();
        $this->hook(['id' => 'fake-1', 'state' => 'delivered']);

        // retention is off by default
        $this->assertSame(['campaigns' => 0, 'rows' => 0], app(SmsRetention::class)->purge());
        $this->assertSame(12, DB::table('commercial_sms_recipients')->count());

        config(['gwl.commercial_sms_recipient_retention_days' => 30]);
        $this->assertSame(['campaigns' => 0, 'rows' => 0], app(SmsRetention::class)->purge(), 'too recent');

        $old->forceFill(['finished_at' => now()->subDays(45)])->save();
        $this->assertSame(['campaigns' => 1, 'rows' => 12], app(SmsRetention::class)->purge(true), 'a dry run counts and deletes nothing');
        $this->assertSame(12, DB::table('commercial_sms_recipients')->count());

        $this->assertSame(['campaigns' => 1, 'rows' => 12], app(SmsRetention::class)->purge());
        $this->assertSame(0, DB::table('commercial_sms_recipients')->count());
        $this->assertSame(['delivered' => 1, 'submitted' => 11], $old->fresh()->totals, 'the final counts, delivery reports included');
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.sms_recipients_purged')->count());

        $summary = app(SmsReportService::class)->summary($old->fresh());
        $this->assertTrue($summary['purged']);
        $this->assertSame(1, collect($summary['rows'])->firstWhere('key', 'delivered')['count']);
        Livewire::actingAs($this->westOfficer())->test(CampaignReport::class, ['campaign' => $old->id])->assertSee('retention setting');

        $this->actingAs($this->userWith('910215', ['commercial.send_sms', 'commercial.view_sms_reports', 'commercial.export_reports'], $this->accraWest, $this->sowutuom))
            ->get(route('commercial.sms.campaigns.export', $old->id))->assertNotFound();
    }
}
