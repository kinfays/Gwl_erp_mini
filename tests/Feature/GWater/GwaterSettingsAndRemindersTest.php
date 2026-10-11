<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\Overview;
use App\Livewire\GWater\Settings;
use App\Models\AuditLog;
use App\Models\GwReminderState;
use App\Models\GwSetting;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\GWater\GwaterReminderService;
use App\Services\GWater\GwaterSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GwaterSettingsAndRemindersTest extends GwaterTestCase
{
    private function place($district): array
    {
        return ['region_id' => $district->region_id, 'district_id' => $district->id];
    }

    /** What a user was told, as the notification would store it. @return list<array<string, mixed>> */
    private function noticesTo(User $user): array
    {
        return Notification::sent($user, GeneralDatabaseNotification::class)->map(fn ($notification) => $notification->toArray($user))->values()->all();
    }

    // ---------------------------------------------------------------- settings

    public function test_settings_default_to_the_config_and_a_location_can_override_them(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $settings = app(GwaterSettings::class);

        $this->assertSame([50, 30, 7], [$settings->lowStock($this->place($this->sowutuom), 'bottle'), $settings->value($this->place($this->sowutuom), 'payment_overdue_days'), $settings->value($this->place($this->sowutuom), 'reminder_repeat_days')]);

        $after = $this->workflow()->saveSettings($user, ['low_stock_bottle' => 80, 'payment_overdue_days' => 14]);
        $this->assertSame([80, 14, 7, 50], [$after['low_stock_bottle'], $after['payment_overdue_days'], $after['reminder_repeat_days'], $after['low_stock_sachet']]);
        $this->assertSame(2, GwSetting::query()->count(), 'only what differs from the default is stored');

        // Another district of the same region shares it; Head Office (same region id) and Kumasi do not.
        $this->assertSame(80, $settings->lowStock($this->place($this->odorkor), 'bottle'));
        $this->assertSame(50, $settings->lowStock($this->place($this->headOffice), 'bottle'));
        $this->assertSame(50, $settings->lowStock($this->place($this->kumasi), 'bottle'));
    }

    public function test_a_value_saved_equal_to_its_default_is_stored_as_no_override(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $this->workflow()->saveSettings($user, ['low_stock_bottle' => 80]);
        $this->assertSame(1, GwSetting::query()->count());

        $this->workflow()->saveSettings($user, ['low_stock_bottle' => 50]);
        $this->assertSame(0, GwSetting::query()->count());
        $this->assertSame(50, app(GwaterSettings::class)->lowStock($this->place($this->sowutuom), 'bottle'));

        // Saving twice does not pile up rows.
        $this->workflow()->saveSettings($user, ['low_stock_bottle' => 80]);
        $this->workflow()->saveSettings($user, ['low_stock_bottle' => 90]);
        $this->assertSame([90], GwSetting::query()->pluck('value')->all());
    }

    public function test_settings_are_validated_audited_and_need_manage_prices(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        foreach ([['low_stock_bottle' => -1], ['low_stock_bottle' => 'many'], ['payment_overdue_days' => 0], ['reminder_repeat_days' => 1000], ['low_stock_bottle' => 1.5]] as $bad) {
            try {
                $this->workflow()->saveSettings($user, $bad);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(0, GwSetting::query()->count());
            }
        }

        $this->workflow()->saveSettings($user, ['low_stock_bottle' => 80, 'not_a_setting' => 5]);
        $row = AuditLog::query()->where('action', 'gwater.settings_saved')->firstOrFail();
        $this->assertSame([['low_stock_bottle' => 50], ['low_stock_bottle' => 80]], [$row->old_values, $row->new_values]);

        $plain = $this->person('E1', $this->sowutuom);
        $this->actingAs($plain);
        $this->expectException(HttpException::class);
        $this->workflow()->saveSettings($plain, ['low_stock_bottle' => 1]);
    }

    public function test_the_settings_screen_saves_resets_and_stays_in_its_location(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);

        Livewire::actingAs($user)->test(Settings::class)
            ->assertSet('values.low_stock_bottle', '50')
            ->set('values.low_stock_bottle', '75')
            ->call('save')->assertHasNoErrors()
            ->assertSet('values.low_stock_bottle', '75')
            ->call('resetToDefault', 'low_stock_bottle')->assertSet('values.low_stock_bottle', '50')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(0, GwSetting::query()->count());

        Livewire::actingAs($user)->test(Settings::class)->set('values.reminder_repeat_days', '0')->call('save')->assertHasErrors(['values.reminder_repeat_days']);

        Livewire::actingAs($user)->test(Settings::class)->set('values.low_stock_bottle', '75')->call('save');
        Livewire::actingAs($kumasi)->test(Settings::class)->assertSet('values.low_stock_bottle', '50');

        $this->assertContains($this->actingAs($this->person('E1', $this->sowutuom))->get(route('gwater.settings'))->getStatusCode(), [302, 403]);
    }

    public function test_the_overview_uses_the_locations_own_low_stock_threshold(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->trip($user, 'WB-1', 30, 100);

        Livewire::actingAs($user)->test(Overview::class)->assertSee('Low stock: Bottled water')->assertDontSee('Low stock: Sachet water');

        $this->workflow()->saveSettings($user, ['low_stock_bottle' => 10, 'low_stock_sachet' => 200]);

        Livewire::actingAs($user)->test(Overview::class)->assertDontSee('Low stock: Bottled water')->assertSee('Low stock: Sachet water');
    }

    public function test_the_settings_and_import_routes_open_for_g_water_users(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        foreach (['gwater.settings', 'gwater.import', 'gwater.import.template', 'gwater.pnl'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }

        $navigation = collect(app(\App\Support\ErpNavigation::class)->build($user, 'gwater')['sidebar'])->pluck('route')->all();
        $this->assertContains('gwater.settings', $navigation);
        $this->assertContains('gwater.import', $navigation);
    }

    // ---------------------------------------------------------------- reminders

    public function test_low_stock_is_reminded_once_per_interval_to_the_locations_g_water_users_only(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $accraToo = $this->gw('G4', $this->odorkor);
        $kumasi = $this->gw('G2', $this->kumasi);
        $headOffice = $this->gw('G3', $this->headOffice);
        $plain = $this->person('E1', $this->sowutuom);
        $super = $this->superAdmin();
        $this->trip($accra, 'WB-SECRET-1', 20, 500);   // bottle 20 <= 50: low; sachet 500: fine
        $this->trip($kumasi, 'WB-K', 500, 500);       // fine
        $this->trip($headOffice, 'WB-H', 500, 500);   // fine
        $reminders = app(GwaterReminderService::class);

        $first = $reminders->remind(now());

        $this->assertSame([3, 1, 1, 2], [$first['locations'], $first['low_stock'], $first['reminded'], $first['notifications']]);
        foreach ([$accra, $accraToo] as $recipient) {
            $notices = $this->noticesTo($recipient);
            $this->assertCount(1, $notices);
            $this->assertSame('G-Water: low stock', $notices[0]['title']);
            $this->assertSame('Bottled water (pack of 15) is low at Accra West: 20 pack(s) on hand (limit 50).', $notices[0]['message']);
            $this->assertSame('gwater', $notices[0]['module']);
        }

        foreach ([$kumasi, $headOffice, $plain, $super] as $bystander) {
            Notification::assertNotSentTo($bystander, GeneralDatabaseNotification::class);
        }

        // A notice carries the product, the location and counts: no waybill, no amount, no person.
        $text = json_encode($this->noticesTo($accra));
        foreach (['WB-SECRET-1', 'Staff', 'G1', 'GH'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }

        // Again the same day, and the next day: not again.
        $this->assertSame(0, $reminders->remind(now()->addDay())['reminded']);
        $this->assertCount(1, $this->noticesTo($accra));

        // After the repeat interval (7 days), once more; and then not again until the interval has passed again.
        $this->assertSame(1, $reminders->remind(now()->addDays(7))['reminded']);
        $this->assertCount(2, $this->noticesTo($accra));
        $this->assertSame(0, $reminders->remind(now()->addDays(8))['reminded']);
        $this->assertSame(1, GwReminderState::query()->count(), 'one state row per item, updated in place');
    }

    public function test_the_locations_own_repeat_interval_and_threshold_apply(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->actingAs($accra);
        $this->trip($accra, 'WB-1', 20, 500);
        $this->workflow()->saveSettings($accra, ['reminder_repeat_days' => 2, 'low_stock_bottle' => 10]);
        $reminders = app(GwaterReminderService::class);

        // 20 packs is above the location's own limit of 10: nothing to say.
        $this->assertSame(0, $reminders->remind(now())['low_stock']);

        $this->workflow()->recordSale($accra, $this->saleData(12, '2026-10-10'));
        $this->assertSame(1, $reminders->remind(now())['reminded']);
        $this->assertSame(0, $reminders->remind(now()->addDay())['reminded']);
        $this->assertSame(1, $reminders->remind(now()->addDays(2))['reminded'], 'the location asked for a 2-day interval');
    }

    public function test_an_overdue_payment_request_is_reminded_once_per_interval_and_stops_when_paid(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->actingAs($accra);
        $this->trip($accra, 'WB-1', 500, 500);   // plenty of stock: only the payments matter
        $old = $this->workflow()->recordPayment($accra, ['request_date' => '2026-08-20', 'reference' => 'PR-SECRET-1', 'amount' => 123456]);       // 52 days old
        $this->workflow()->recordPayment($accra, ['request_date' => '2026-10-01', 'reference' => 'PR-NEW', 'amount' => 5000]);                  // 10 days: not yet
        $this->workflow()->recordPayment($accra, ['request_date' => '2026-08-01', 'reference' => 'PR-PAID', 'amount' => 5000, 'paid_on' => '2026-08-02']);
        $reminders = app(GwaterReminderService::class);

        $summary = $reminders->remind(now());

        $this->assertSame([1, 1], [$summary['overdue'], $summary['reminded']]);
        $notice = $this->noticesTo($accra)[0];
        $this->assertSame('G-Water: payment requests overdue', $notice['title']);
        $this->assertStringContainsString('1 payment request(s) to G Water at Accra West are overdue', $notice['message']);
        $this->assertStringNotContainsString('PR-SECRET-1', json_encode($notice));
        $this->assertStringNotContainsString('1,234', json_encode($notice));

        $this->assertSame(0, $reminders->remind(now()->addDays(3))['reminded']);
        $this->assertSame(1, $reminders->remind(now()->addDays(7))['reminded']);

        // Once paid it is no longer overdue (the other request is still under the limit eight days on).
        $this->workflow()->markPaymentPaid($old, $accra, '2026-10-11');
        $later = $reminders->remind(now()->addDays(8));
        $this->assertSame([0, 0], [$later['overdue'], $later['reminded']]);

        // The overdue limit is the location's own setting.
        $this->workflow()->saveSettings($accra, ['payment_overdue_days' => 5]);
        $this->assertSame(1, $reminders->remind(now()->addDays(60))['overdue'], 'PR-NEW is now older than 5 days');
    }

    public function test_head_office_and_its_region_are_reminded_separately(): void
    {
        $regional = $this->gw('G1', $this->sowutuom);
        $headOffice = $this->gw('G3', $this->headOffice);
        $this->trip($headOffice, 'WB-H', 10, 500);
        $this->trip($regional, 'WB-R', 500, 500);

        app(GwaterReminderService::class)->remind(now());

        $this->assertCount(1, $this->noticesTo($headOffice));
        $this->assertStringContainsString('Head Office', $this->noticesTo($headOffice)[0]['message']);
        Notification::assertNotSentTo($regional, GeneralDatabaseNotification::class);
    }

    public function test_a_dry_run_sends_and_records_nothing_and_an_unattended_location_records_nothing(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->trip($accra, 'WB-1', 10, 500);
        $reminders = app(GwaterReminderService::class);

        $dry = $reminders->remind(now(), dryRun: true);
        $this->assertSame([1, 1], [$dry['reminded'], $dry['notifications']]);
        Notification::assertSentTimes(GeneralDatabaseNotification::class, 0);
        $this->assertSame(0, GwReminderState::query()->count());

        // Nobody who can record is based there: nothing is sent and nothing is marked, so the next person to arrive hears it.
        User::query()->whereKey($accra->id)->first()->roles()->detach();
        $none = $reminders->remind(now());
        $this->assertSame([0, 1], [$none['reminded'], $none['nobody']]);
        $this->assertSame(0, GwReminderState::query()->count());
    }

    public function test_the_command_runs_and_reports(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->trip($accra, 'WB-1', 10, 500);

        $this->artisan('gwater:remind --dry-run')->expectsOutputToContain('would send 1 reminder(s)')->assertSuccessful();
        Notification::assertSentTimes(GeneralDatabaseNotification::class, 0);

        $this->artisan('gwater:remind')->expectsOutputToContain('sent 1 reminder(s)')->assertSuccessful();
        $this->assertCount(1, $this->noticesTo($accra));
    }

    public function test_with_the_module_flag_off_there_is_no_command_no_schedule_and_the_service_does_nothing(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->trip($accra, 'WB-1', 10, 500);

        $commands = fn () => collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode(' ');
        $this->assertStringContainsString('gwater:remind', $commands(), 'sanity: scheduled while the flag is on');

        // The service refuses on its own too.
        config(['gwl.gwater_module_enabled' => false]);
        $this->assertFalse(app(GwaterReminderService::class)->remind(now())['enabled']);
        Notification::assertSentTimes(GeneralDatabaseNotification::class, 0);

        // And a fresh application with the flag off schedules nothing (console routes are only loaded then; closures registered by an
        // earlier boot in this process would still be listed by Artisan, which is a test artefact, so the schedule is what is checked).
        $this->rebuildWithFlag('false');

        try {
            $this->assertFalse(config('gwl.gwater_module_enabled'));
            $this->assertStringNotContainsString('gwater:remind', $commands());
        } finally {
            $this->rebuildWithFlag('true');
        }
    }

    private function rebuildWithFlag(string $value): void
    {
        $_ENV['GWL_GWATER_MODULE_ENABLED'] = $value;
        $_SERVER['GWL_GWATER_MODULE_ENABLED'] = $value;
        putenv('GWL_GWATER_MODULE_ENABLED='.$value);

        $this->refreshApplication();
    }
}
