<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Batches;
use App\Livewire\Commercial\Summary;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReminderState;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Commercial\CommercialSettings;
use App\Services\Commercial\UploadReminderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Upload reminders. "Today" is 15 Oct 2026 and the default limits apply: meter reading 10 days, billing 35 days,
 * remind again after 7.
 */
class UploadReminderTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A reading or billing upload that arrived on the given day. */
    protected function uploaded(string $type, string $day, $region = null, array $attributes = []): CommercialImportBatch
    {
        $batch = $type === 'reading'
            ? $this->seedReading(['R1' => ['2026-09-01' => [1, 0]]], $region)
            : $this->seedBilling([['district' => 'D', 'code' => 'R']], '2026-09-01', ['region' => $region ?? $this->accraWest]);

        $batch->forceFill(['imported_at' => $day.' 09:00:00', ...$attributes])->save();

        return $batch->fresh();
    }

    protected function batches()
    {
        return CommercialImportBatch::query()->notVoided()->with('region')->get();
    }

    // ---------------------------------------------------------------- what is overdue

    public function test_overdue_is_hand_computed_per_region_and_report_type(): void
    {
        $this->uploaded('reading', '2026-10-01');                       // Accra West reading: 14 days > 10: overdue
        $this->uploaded('billing', '2026-09-20');                       // Accra West billing: 25 days <= 35: fine
        $this->uploaded('reading', '2026-10-10', $this->ashanti);       // Ashanti reading: 5 days: fine
        $this->uploaded('billing', '2026-08-01', $this->ashanti);       // Ashanti billing: 75 days > 35: overdue

        $overdue = (new UploadReminderService)->overdue($this->batches(), now());

        $this->assertCount(2, $overdue);
        $byKey = collect($overdue)->keyBy(fn ($item) => $item['region'].'|'.$item['report_type']);

        $this->assertSame([75, 35, 'billing'], [$byKey['Ashanti|billing_summary']['days'], $byKey['Ashanti|billing_summary']['limit'], $byKey['Ashanti|billing_summary']['label']]);
        $this->assertSame([14, 10, 'meter reading'], [$byKey['Accra West|reading_summary']['days'], $byKey['Accra West|reading_summary']['limit'], $byKey['Accra West|reading_summary']['label']]);
        $this->assertSame('Ashanti', $overdue[0]['region'], 'the most overdue first');
    }

    public function test_exactly_at_the_limit_is_not_overdue_and_a_day_over_is(): void
    {
        $this->uploaded('reading', '2026-10-05');   // 10 days and 1 hour before now (10:00 on the 15th vs 09:00 on the 5th)

        $this->assertCount(1, (new UploadReminderService)->overdue($this->batches(), now()));
        $this->assertSame([], (new UploadReminderService)->overdue($this->batches(), now()->copy()->subHour()->subMinute()));
    }

    public function test_a_voided_upload_does_not_rescue_a_late_region_and_a_region_that_never_uploaded_a_type_is_not_listed(): void
    {
        $this->uploaded('reading', '2026-10-01');
        $this->uploaded('reading', '2026-10-14', attributes: ['status' => CommercialImportBatch::STATUS_VOIDED]);

        $overdue = (new UploadReminderService)->overdue($this->batches(), now());

        $this->assertCount(1, $overdue, 'no billing was ever uploaded, so nothing is late');
        $this->assertSame(14, $overdue[0]['days']);
    }

    public function test_the_latest_upload_counts_not_the_first(): void
    {
        $this->uploaded('reading', '2026-09-01');
        $this->uploaded('reading', '2026-10-12');

        $this->assertSame([], (new UploadReminderService)->overdue($this->batches(), now()));
    }

    public function test_the_limits_come_from_the_settings(): void
    {
        $this->uploaded('reading', '2026-10-01');   // 14 days
        $this->assertCount(1, (new UploadReminderService)->overdue($this->batches(), now()));

        app(CommercialSettings::class)->save(['commercial_reminder_reading_days' => 20], $this->superAdmin()->id);

        $this->assertSame([], (new UploadReminderService)->overdue($this->batches(), now()));
    }

    // ---------------------------------------------------------------- who is told

    public function test_the_officers_of_that_region_and_those_who_see_every_region_are_told_nobody_else(): void
    {
        $this->uploaded('reading', '2026-10-01');                       // Accra West is late
        $mine = $this->officer('900901');                                // Accra West officer
        $other = $this->officer('900902', $this->ashanti);               // Ashanti officer
        $headOffice = $this->officer('900903', $this->accraWest, $this->headOffice);
        $manager = $this->userWithRoles('900904', ['commercial_manager']);   // views, does not upload
        $inactive = $this->officer('900905');
        $inactive->forceFill(['is_active' => false])->save();
        $this->superAdmin();

        $summary = app(UploadReminderService::class)->remind();

        $this->assertSame(['enabled' => true, 'overdue' => 1, 'reminded' => 1, 'notifications' => 2, 'skipped' => 0], $summary);

        Notification::assertSentTo($mine, GeneralDatabaseNotification::class, function ($notification) use ($mine) {
            $data = $notification->toArray($mine);

            return $data['module'] === 'commercial'
                && $data['kind'] === 'commercial_upload_overdue'
                && str_contains($data['title'], 'Meter reading upload overdue: Accra West')
                && str_contains($data['message'], '14 days')
                && str_contains($data['message'], 'limit is 10')
                && str_contains($data['message'], '01 Oct 2026')
                && $data['url'] === route('commercial.batches');
        });
        Notification::assertSentTo($headOffice, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($other, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($manager, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($inactive, GeneralDatabaseNotification::class);
        $this->assertSame(2, collect([$mine, $other, $headOffice, $manager, $inactive])->sum(fn ($user) => Notification::sent($user, GeneralDatabaseNotification::class)->count()), 'two reminders in all');
    }

    // ---------------------------------------------------------------- not every day

    public function test_a_reminder_is_not_repeated_until_the_repeat_interval_or_a_new_late_cycle(): void
    {
        $this->uploaded('reading', '2026-10-01');
        $officer = $this->officer('900901');
        $service = app(UploadReminderService::class);

        $this->assertSame(1, $service->remind()['reminded']);
        Notification::assertSentToTimes($officer, GeneralDatabaseNotification::class, 1);

        // Same day, and six days later: nothing more.
        $this->assertSame(['overdue' => 1, 'reminded' => 0, 'skipped' => 1], collect($service->remind())->only(['overdue', 'reminded', 'skipped'])->all());
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->assertSame(0, $service->remind(now())['reminded']);
        Notification::assertSentToTimes($officer, GeneralDatabaseNotification::class, 1);

        // Eight days later the repeat interval (7) has passed.
        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->assertSame(1, $service->remind(now())['reminded']);
        Notification::assertSentToTimes($officer, GeneralDatabaseNotification::class, 2);

        $state = CommercialReminderState::query()->sole();
        $this->assertSame($this->accraWest->id, $state->region_id);
        $this->assertSame('2026-10-23 10:00:00', $state->last_reminded_at->format('Y-m-d H:i:s'));
    }

    public function test_a_fresh_upload_that_goes_late_again_is_reminded_at_once_not_after_the_interval(): void
    {
        $this->uploaded('reading', '2026-10-01');
        $this->officer('900901');
        $service = app(UploadReminderService::class);

        $this->assertSame(1, $service->remind()['reminded']);   // reminded on 15 Oct

        $this->uploaded('reading', '2026-10-16');               // they uploaded
        Carbon::setTestNow('2026-10-27 10:00:00');              // and 11 days later it is late again

        $this->assertSame(1, $service->remind(now())['reminded'], 'the last reminder predates the latest upload, so this is a new cycle');
    }

    public function test_switching_reminders_off_in_the_settings_sends_nothing(): void
    {
        $this->uploaded('reading', '2026-10-01');
        $officer = $this->officer('900901');
        app(CommercialSettings::class)->save(['commercial_reminders_enabled' => false], $this->superAdmin()->id);

        $summary = app(UploadReminderService::class)->remind();

        $this->assertFalse($summary['enabled']);
        Notification::assertNotSentTo($officer, GeneralDatabaseNotification::class);
        $this->assertSame(0, CommercialReminderState::query()->count());
    }

    // ---------------------------------------------------------------- the command and the schedule

    public function test_the_command_reports_what_it_did_and_dry_run_sends_nothing(): void
    {
        $this->uploaded('reading', '2026-10-01');
        $officer = $this->officer('900901');

        $this->artisan('commercial:remind-uploads', ['--dry-run' => true])
            ->expectsOutputToContain('1 overdue upload(s): would send 1 reminder(s) to 1 officer notification(s)')
            ->assertExitCode(0);
        Notification::assertNotSentTo($officer, GeneralDatabaseNotification::class);
        $this->assertSame(0, CommercialReminderState::query()->count());

        $this->artisan('commercial:remind-uploads')
            ->expectsOutputToContain('1 overdue upload(s): sent 1 reminder(s) to 1 officer notification(s)')
            ->assertExitCode(0);
        Notification::assertSentTo($officer, GeneralDatabaseNotification::class);

        $this->artisan('commercial:remind-uploads')->expectsOutputToContain('already reminded recently')->assertExitCode(0);
        Notification::assertSentToTimes($officer, GeneralDatabaseNotification::class, 1);
    }

    public function test_the_command_says_so_when_reminders_are_switched_off(): void
    {
        $this->uploaded('reading', '2026-10-01');
        app(CommercialSettings::class)->save(['commercial_reminders_enabled' => false], $this->superAdmin()->id);

        $this->artisan('commercial:remind-uploads')->expectsOutputToContain('switched off')->assertExitCode(0);
    }

    public function test_the_command_is_scheduled_daily_while_the_module_is_on_and_not_when_it_is_off(): void
    {
        $commands = fn () => collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode(' ');

        $this->assertStringContainsString('commercial:remind-uploads', $commands());

        $_ENV['GWL_COMMERCIAL_MODULE_ENABLED'] = $_SERVER['GWL_COMMERCIAL_MODULE_ENABLED'] = 'false';
        putenv('GWL_COMMERCIAL_MODULE_ENABLED=false');
        $this->refreshApplication();

        try {
            $this->assertStringNotContainsString('commercial:remind-uploads', $commands());
        } finally {
            $_ENV['GWL_COMMERCIAL_MODULE_ENABLED'] = $_SERVER['GWL_COMMERCIAL_MODULE_ENABLED'] = 'true';
            putenv('GWL_COMMERCIAL_MODULE_ENABLED=true');
        }
    }

    // ---------------------------------------------------------------- where people see it

    public function test_the_summary_and_the_uploads_page_show_the_overdue_upload_to_those_who_may_see_that_region(): void
    {
        $this->uploaded('reading', '2026-10-01');
        $this->uploaded('billing', '2026-08-01', $this->ashanti);

        Livewire::actingAs($this->officer())->test(Summary::class)
            ->assertSee('Meter reading upload overdue for Accra West: 14 days since the last one (reminder limit 10 days).')
            ->assertDontSee('Ashanti');

        Livewire::actingAs($this->officer())->test(Batches::class)
            ->assertSee('Meter reading upload overdue for Accra West')
            ->assertSee('01 Oct 2026')
            ->assertDontSee('overdue for Ashanti');

        Livewire::actingAs($this->superAdmin())->test(Summary::class)->assertSee('Billing upload overdue for Ashanti');
    }

    public function test_the_summary_export_lists_the_overdue_uploads(): void
    {
        $this->uploaded('reading', '2026-10-01');

        $response = $this->actingAs($this->officer())->get(route('commercial.export', ['report' => 'summary', 'format' => 'excel']))->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('OVERDUE: meter reading upload, Accra West', json_encode($book->getSheetByName('Data freshness')->toArray()));
    }

    public function test_nothing_is_overdue_when_everything_is_fresh(): void
    {
        $this->uploaded('reading', '2026-10-12');
        $this->uploaded('billing', '2026-10-01');

        Livewire::actingAs($this->officer())->test(Summary::class)->assertDontSee('upload overdue');
        Livewire::actingAs($this->officer())->test(Batches::class)->assertDontSee('upload overdue');
    }
}
