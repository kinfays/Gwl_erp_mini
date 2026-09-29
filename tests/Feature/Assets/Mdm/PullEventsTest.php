<?php

namespace Tests\Feature\Assets\Mdm;

use App\Jobs\Assets\Mdm\ProcessAndroidNotification;
use App\Models\MdmEvent;
use App\Services\Assets\Mdm\EventIntakeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\Support\Mdm\FakeAndroidManagementGateway as Fake;
use Tests\TestCase;

class PullEventsTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    private const SUBSCRIPTION = 'projects/gwl-test-project/subscriptions/amapi-pull';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        Queue::fake();
    }

    public function test_pulled_messages_are_stored_dispatched_and_only_then_acknowledged(): void
    {
        $this->gateway->pullBatches = [[
            Fake::message('p-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/a']),
            Fake::message('p-2', 'ENROLLMENT', ['name' => 'enterprises/LC0test/devices/b']),
        ]];

        $storedWhenAcked = null;
        $queuedWhenAcked = null;
        $this->gateway->onAcknowledge = function () use (&$storedWhenAcked, &$queuedWhenAcked) {
            $storedWhenAcked = MdmEvent::query()->count();
            $queuedWhenAcked = count(Queue::pushedJobs()[ProcessAndroidNotification::class] ?? []);
        };

        $this->artisan('mdm:poll-events')->expectsOutputToContain('2 new, 0 already received')->assertExitCode(0);

        $this->assertSame(2, $storedWhenAcked, 'both rows were stored before the acknowledgement');
        $this->assertSame(2, $queuedWhenAcked, 'both jobs were dispatched before the acknowledgement');
        $this->assertSame(['ack-p-1', 'ack-p-2'], $this->gateway->acknowledged);
        $this->assertSame(self::SUBSCRIPTION, $this->gateway->calls('pullMessages')[0][0]);
        $this->assertSame('pull', MdmEvent::query()->where('pubsub_message_id', 'p-1')->firstOrFail()->delivery_mode);
        Queue::assertPushed(ProcessAndroidNotification::class, 2);
    }

    public function test_a_message_already_received_by_push_is_acknowledged_but_not_processed_again(): void
    {
        MdmEvent::factory()->processed()->create(['pubsub_message_id' => 'dup-1', 'delivery_mode' => 'push']);

        $this->gateway->pullBatches = [[
            Fake::message('dup-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/a']),
            Fake::message('fresh-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/b']),
        ]];

        $this->artisan('mdm:poll-events')->expectsOutputToContain('1 new, 1 already received')->assertExitCode(0);

        $this->assertContains('ack-dup-1', $this->gateway->acknowledged, 'acked so Pub/Sub stops redelivering it');
        $this->assertSame(1, MdmEvent::query()->where('pubsub_message_id', 'dup-1')->count());
        $this->assertSame('push', MdmEvent::query()->where('pubsub_message_id', 'dup-1')->firstOrFail()->delivery_mode, 'the first delivery wins');
        Queue::assertPushed(ProcessAndroidNotification::class, 1);
    }

    public function test_a_message_that_could_not_be_stored_is_left_unacknowledged_for_redelivery(): void
    {
        $real = app(EventIntakeService::class);

        $this->app->instance(EventIntakeService::class, new class($real) extends EventIntakeService
        {
            public function __construct(private readonly EventIntakeService $inner) {}

            public function ingest(string $messageId, string $deliveryMode, string $data, array $attributes = [], ?string $publishTime = null): array
            {
                if ($messageId === 'boom') {
                    throw new \RuntimeException('database unavailable');
                }

                return $this->inner->ingest($messageId, $deliveryMode, $data, $attributes, $publishTime);
            }
        });

        $this->gateway->pullBatches = [[
            Fake::message('ok-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/a']),
            Fake::message('boom', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/b']),
        ]];

        $this->artisan('mdm:poll-events')->expectsOutputToContain('1 new')->assertExitCode(0);

        $this->assertSame(['ack-ok-1'], $this->gateway->acknowledged, 'the failed message must be redelivered, so it is not acknowledged');
        $this->assertSame(1, MdmEvent::query()->count());
    }

    public function test_an_undecodable_message_is_dropped_and_acknowledged_so_it_is_not_redelivered_forever(): void
    {
        $this->gateway->pullBatches = [[
            ['ackId' => 'ack-junk', 'messageId' => 'junk-1', 'data' => '***', 'attributes' => [], 'publishTime' => null],
            Fake::message('good-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/a']),
        ]];

        $this->artisan('mdm:poll-events')->expectsOutputToContain('1 new')->assertExitCode(0);

        $this->assertSame(['ack-junk', 'ack-good-1'], $this->gateway->acknowledged);
        $this->assertSame(1, MdmEvent::query()->count());
    }

    public function test_an_empty_subscription_acknowledges_nothing(): void
    {
        $this->artisan('mdm:poll-events')->expectsOutputToContain('0 new')->assertExitCode(0);

        $this->assertSame([], $this->gateway->acknowledged);
        $this->assertFalse($this->gateway->called('acknowledgeMessages'));
    }

    public function test_the_poll_keeps_pulling_while_batches_are_full(): void
    {
        config(['gwl.mdm_pubsub_pull_batch' => 2]);
        $this->gateway->pullBatches = [
            [Fake::message('b1-1', 'STATUS_REPORT', ['name' => 'a']), Fake::message('b1-2', 'STATUS_REPORT', ['name' => 'b'])],
            [Fake::message('b2-1', 'STATUS_REPORT', ['name' => 'c'])],
        ];

        $this->artisan('mdm:poll-events')->expectsOutputToContain('3 new')->assertExitCode(0);

        $this->assertCount(2, $this->gateway->calls('pullMessages'));
        $this->assertSame(3, MdmEvent::query()->count());
    }

    public function test_the_command_is_a_no_op_in_push_mode_unless_forced(): void
    {
        config(['gwl.mdm_pubsub_mode' => 'push']);
        $this->gateway->pullBatches = [[Fake::message('p-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/a'])]];

        $this->artisan('mdm:poll-events')->expectsOutputToContain('push; skipping')->assertExitCode(0);

        $this->assertFalse($this->gateway->called('pullMessages'));
        $this->assertSame(0, MdmEvent::query()->count());

        $this->artisan('mdm:poll-events', ['--force' => true])->assertExitCode(0);

        $this->assertTrue($this->gateway->called('pullMessages'));
        $this->assertSame(1, MdmEvent::query()->count());
        $this->assertSame(['ack-p-1'], $this->gateway->acknowledged);
    }

    public function test_an_unknown_mode_value_falls_back_to_pull(): void
    {
        config(['gwl.mdm_pubsub_mode' => 'pusssh']);

        $this->artisan('mdm:poll-events')->assertExitCode(0);

        $this->assertTrue($this->gateway->called('pullMessages'));
    }

    public function test_the_poll_is_scheduled_every_minute_without_overlapping_and_the_resync_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->keyBy(fn ($event) => $event->command);

        $poll = $events->first(fn ($event, $command) => str_contains((string) $command, 'mdm:poll-events'));
        $sync = $events->first(fn ($event, $command) => str_contains((string) $command, 'mdm:sync-devices'));

        $this->assertNotNull($poll, 'mdm:poll-events must be scheduled');
        $this->assertSame('* * * * *', $poll->expression);
        $this->assertTrue($poll->withoutOverlapping);
        $this->assertNotNull($sync, 'the nightly resync must be scheduled');
        $this->assertSame('15 2 * * *', $sync->expression);
    }

    public function test_switching_modes_never_double_processes_an_event(): void
    {
        $body = base64_encode(json_encode(['name' => 'enterprises/LC0test/devices/a']));

        // Arrives by push first…
        app(EventIntakeService::class)->ingest('cut-1', 'push', $body, ['notificationType' => 'STATUS_REPORT']);
        // …then again by pull during the cutover.
        $this->gateway->pullBatches = [[Fake::message('cut-1', 'STATUS_REPORT', ['name' => 'enterprises/LC0test/devices/a'])]];
        $this->artisan('mdm:poll-events')->assertExitCode(0);

        $this->assertSame(1, MdmEvent::query()->count());
        Queue::assertPushed(ProcessAndroidNotification::class, 1);
        $this->assertContains('ack-cut-1', $this->gateway->acknowledged);
    }
}
