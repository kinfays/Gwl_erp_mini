<?php

namespace Tests\Feature\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Services\Commercial\Sms\ArkeselGateway;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Services\Commercial\Sms\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * After full runs (a normal one, a personalised one, failures and a timeout, a report and an export), no phone number, name, merged
 * message or message wording is in an audit row, a notification, a queue table, a campaign or recipient row, a log line, or an
 * exception message, and the API key is nowhere. The only places a number may be are the customer contact tables. The real
 * ArkeselGateway is used here, with its HTTP calls faked.
 */
class SmsPrivacyTest extends SmsTestCase
{
    private const WORDING = 'PRIVATE-WORDING-7731';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_sms_chunk_size' => 10, 'gwl.arkesel.base_url' => 'https://sms.arkesel.test']);
        app()->instance(SmsGateway::class, new ArkeselGateway);   // the real one: only its HTTP is faked
    }

    /** @return list<string> every way a number or a name of the fixtures could be written */
    protected function secrets(int $count): array
    {
        $secrets = [];

        foreach (range(1, $count) as $n) {
            $local = '024'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);
            array_push($secrets, $local, '233'.substr($local, 1), 'Customer '.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'c'.$n.'@example.test');
        }

        return $secrets;
    }

    public function test_nothing_personal_or_secret_is_stored_or_logged_by_a_full_sms_run(): void
    {
        $log = [];
        Log::listen(function (MessageLogged $event) use (&$log) {
            $log[] = $event->message.' '.json_encode($event->context);
        });

        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n), range(1, 40))]]]);
        $detailer = $this->userWith('910400', ['commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports', 'commercial.export_reports', 'commercial.view_customer_details'], $this->accraWest, $this->sowutuom);

        // 1. a normal campaign whose request the provider takes
        Http::preventStrayRequests();
        Http::fake([
            '*/api/v2/clients/balance-details' => Http::response(['data' => ['sms_balance' => 50000]]),
            '*/api/v2/sms/send' => Http::sequence()
                ->push(['status' => 'success', 'data' => [['recipient' => '233240000001', 'id' => 'msg-1']]])
                ->push(['status' => 'error', 'code' => '102', 'message' => 'bad key test-key-never-real for 233240000011'])
                ->pushStatus(500)
                ->whenEmpty(Http::response(['status' => 'success'])),
        ]);

        $service = app(SmsCampaignService::class);
        $first = $service->create($detailer, ['audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => self::WORDING.' pay now', 'confirm_count' => 40, 'submission_token' => 'p1']);

        // 2. a personalised one (name, balance, account number are merged in memory)
        Http::fake([
            '*/api/v2/clients/balance-details' => Http::response(['data' => ['sms_balance' => 50000]]),
            '*/api/v2/sms/send' => Http::response(['status' => 'success']),
        ]);
        $second = $service->create($detailer, ['audience' => ['type' => 'group', 'group' => 'actb'], 'sender_id' => 'GWCL', 'body' => 'Dear {name}, '.self::WORDING.' you owe {balance} ({account_no})', 'confirm_count' => 40, 'submission_token' => 'p2']);

        // 3. a timeout after the request left
        Http::fake([
            '*/api/v2/clients/balance-details' => Http::response(['data' => ['sms_balance' => 50000]]),
            '*/api/v2/sms/send' => fn () => throw new ConnectionException('cURL error 28: timed out sending 233240000020 with test-key-never-real'),
        ]);
        $third = $service->create($detailer, ['audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => self::WORDING, 'confirm_count' => 40, 'submission_token' => 'p3']);

        // 4. the screens a person looks at, and the export
        Http::fake();
        $this->actingAs($detailer)->get(route('commercial.sms.campaigns.show', $first->id))->assertOk();
        Livewire::actingAs($detailer)->test(\App\Livewire\Commercial\Sms\CampaignReport::class, ['campaign' => $second->id])->assertSee('Customer 000001');
        $this->actingAs($detailer)->get(route('commercial.sms.campaigns.export', $second->id))->assertOk();
        $this->postJson('/webhooks/sms-arkesel?token=test-webhook-secret', ['id' => 'msg-1', 'status' => 'delivered', 'recipient' => '233240000001']);

        $this->assertNotSame([], $first->fresh()->totals ?? [], 'sanity: the first campaign ran');
        $this->assertGreaterThan(0, DB::table('commercial_sms_recipients')->count());

        // ---- the sweep: every table except the customer contact data
        $needles = $this->secrets(40);
        $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
            ->reject(fn ($name) => in_array($name, ['commercial_customer_contacts', 'commercial_customer_staging', 'migrations'], true));

        foreach ($tables as $table) {
            $rows = json_encode(DB::table($table)->get());

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $rows, "{$table} holds {$needle}");
            }

            $this->assertStringNotContainsString('test-key-never-real', $rows, "{$table} holds the API key");
        }

        // the merged text is never stored anywhere, and the wording only in the campaign templates
        foreach ($tables as $table) {
            $rows = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('Dear Customer', $rows, "{$table} holds a merged message");

            if ($table !== 'commercial_sms_campaigns') {
                $this->assertStringNotContainsString(self::WORDING, $rows, "{$table} holds the message wording");
            }
        }

        // audit rows and notifications in particular
        $audit = json_encode(DB::table('audit_logs')->get());
        $this->assertStringContainsString('commercial.sms_campaign_created', $audit);
        $this->assertStringContainsString('commercial.sms_report_exported', $audit);
        $this->assertStringNotContainsString(self::WORDING, $audit);
        $this->assertStringNotContainsString('{name}', $audit);

        // the recipients table has no column that could hold a number, a name or a text
        $columns = Schema::getColumnListing('commercial_sms_recipients');
        $this->assertSame([], array_values(array_filter($columns, fn (string $column) => $column !== 'provider_message_id' && preg_match('/phone|number|mobile|msisdn|name|message|text|body|email/i', $column) === 1)), 'columns: '.implode(', ', $columns));

        // the logs
        $logged = implode("\n", $log);

        foreach ([...$needles, 'test-key-never-real', self::WORDING] as $needle) {
            $this->assertStringNotContainsString($needle, $logged, 'a log line holds '.$needle);
        }

        // the stored error text of a stopped campaign and the failure reasons shown
        $messages = json_encode(CommercialSmsCampaign::query()->pluck('error_message')->all()).json_encode(DB::table('commercial_sms_recipients')->pluck('error_code')->all());

        foreach ([...$needles, 'test-key-never-real'] as $needle) {
            $this->assertStringNotContainsString($needle, $messages);
        }
    }

    public function test_the_job_payload_is_the_campaign_id_and_nothing_else(): void
    {
        app()->instance(SmsGateway::class, $this->sms);   // the fake: this test is about the job, not the provider
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n), range(1, 12))]]]);
        \Illuminate\Support\Facades\Queue::fake();

        $detailer = $this->westOfficer();
        app(SmsCampaignService::class)->create($detailer, [
            'audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => self::WORDING, 'confirm_checked' => true, 'submission_token' => 'q1',
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Commercial\SendSmsCampaignChunk::class, function ($job) {
            $payload = json_encode(get_object_vars($job));

            return array_keys(get_object_vars($job)) !== [] && ! str_contains($payload, self::WORDING) && ! str_contains($payload, '024') && is_int($job->campaignId)
                && preg_match('/^\{.*\}$/', $payload) === 1 && count(array_filter(get_object_vars($job), fn ($value) => is_string($value) && strlen($value) > 40)) === 0;
        });

        // the delivery-report job carries a message id, a state and a short code
        $report = new \App\Jobs\Commercial\ApplySmsDeliveryReport('msg-9', 'delivered', null);
        $this->assertSame(['messageId' => 'msg-9', 'state' => 'delivered', 'code' => null], ['messageId' => $report->messageId, 'state' => $report->state, 'code' => $report->code]);
    }
}
