<?php

namespace Tests\Feature\Commercial\Sms;

use App\Livewire\Commercial\Sms\Home;
use App\Models\CommercialReminderState;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Commercial\Sms\SmsBalance;
use App\Services\Commercial\Sms\SmsBalanceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** The balance on screen: cached, refreshable (throttled), "unavailable" is never zero, the low-balance notice goes once per interval. */
class SmsBalanceTest extends SmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function service(): SmsBalanceService
    {
        return app(SmsBalanceService::class);
    }

    public function test_the_balance_is_cached_and_a_refresh_bypasses_the_cache_but_is_throttled(): void
    {
        $this->sms->balance = SmsBalance::of(500);

        $this->assertSame(500, $this->service()->current()['credits']);
        $this->sms->balance = SmsBalance::of(450);
        $this->assertSame(500, $this->service()->current()['credits'], 'served from the cache');
        $this->assertSame(1, $this->sms->balanceCalls);

        $this->assertSame(450, $this->service()->current(true)['credits'], 'Refresh bypasses the cache');
        $this->assertSame(2, $this->sms->balanceCalls);

        $this->sms->balance = SmsBalance::of(400);
        $this->assertSame(450, $this->service()->current(true)['credits'], 'but only once every 30 seconds: a second press is served from the cache');
        $this->assertSame(2, $this->sms->balanceCalls);
    }

    public function test_an_unavailable_balance_is_not_a_zero_balance_and_blocks_a_send(): void
    {
        $this->sms->balance = SmsBalance::unavailable('http_500');

        $balance = $this->service()->current();
        $this->assertFalse($balance['available']);
        $this->assertNull($balance['credits']);
        $this->assertFalse($balance['low'], 'unavailable is not "low"');

        $covers = $this->service()->covers(10);
        $this->assertFalse($covers['ok']);
        $this->assertStringContainsString('cannot be read', $covers['reason']);

        $this->sms->balance = SmsBalance::of(40);
        Cache::flush();
        $this->assertTrue($this->service()->covers(40)['ok']);
        $blocked = $this->service()->covers(41);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('estimate', $blocked['reason']);
    }

    public function test_the_page_shows_the_balance_the_low_banner_and_the_unavailable_state(): void
    {
        $officer = $this->westOfficer();

        $this->sms->balance = SmsBalance::of(150);
        Livewire::actingAs($officer)->test(Home::class)->assertSee('150')->assertSee('running low');

        Cache::flush();
        $this->sms->balance = SmsBalance::unavailable('unreachable');
        Livewire::actingAs($officer)->test(Home::class)->assertSee('Unavailable')->assertSee('not a zero balance')->assertDontSee('running low');

        Cache::flush();
        $this->sms->balance = SmsBalance::of(100000);
        $page = Livewire::actingAs($officer)->test(Home::class)->assertSee('100,000');
        $this->sms->balance = SmsBalance::of(99000);
        $page->call('refreshBalance')->assertSee('99,000')->assertSee('Refreshed');
    }

    public function test_someone_who_may_not_see_the_balance_sees_no_figure(): void
    {
        $sender = $this->westWith('910050', ['commercial.send_sms']);

        Livewire::actingAs($sender)->test(Home::class)->assertDontSee('100,000')->assertDontSee('SMS balance');
        Livewire::actingAs($sender)->test(Home::class)->call('refreshBalance')->assertForbidden();
        $this->assertSame(0, $this->sms->balanceCalls, 'the provider was not even asked for someone who cannot see it');
    }

    public function test_a_low_balance_tells_the_officers_and_super_admin_of_accra_west_once_per_repeat_interval(): void
    {
        $officer = $this->westOfficer();
        $superAdmin = $this->superAdmin();
        $other = $this->eastOfficer();
        $manager = $this->userWithRoles('910060', ['commercial_manager'], $this->accraWest, $this->sowutuom);

        $this->sms->balance = SmsBalance::of(50);
        Notification::fake();

        $first = $this->service()->checkLowBalance();
        $this->assertSame('low', $first['status']);
        $this->assertSame(2, $first['notified']);
        Notification::assertSentTo($officer, GeneralDatabaseNotification::class);
        Notification::assertSentTo($superAdmin, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($other, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($manager, GeneralDatabaseNotification::class);

        $again = $this->service()->checkLowBalance();
        $this->assertSame(['already_told', 0], [$again['status'], $again['notified']]);

        CommercialReminderState::query()->where('report_type', 'sms_balance')->update(['last_reminded_at' => now()->subDays(8)]);
        $this->assertSame(2, $this->service()->checkLowBalance()['notified'], 'after the repeat interval it tells them again');
    }

    public function test_a_healthy_unreadable_or_disabled_balance_notifies_nobody_and_the_message_never_holds_the_key(): void
    {
        $this->westOfficer();
        Notification::fake();

        $this->sms->balance = SmsBalance::of(5000);
        $this->assertSame('ok', $this->service()->checkLowBalance()['status']);

        $this->sms->balance = SmsBalance::unavailable('http_500');
        Cache::flush();
        $this->assertSame('unavailable', $this->service()->checkLowBalance()['status']);

        config(['gwl.commercial_sms_enabled' => false]);
        $this->assertSame('off', $this->service()->checkLowBalance()['status']);

        Notification::assertNothingSent();

        config(['gwl.commercial_sms_enabled' => true]);
        $this->sms->balance = SmsBalance::of(10);
        Cache::flush();
        $this->service()->checkLowBalance();
        $text = '';
        Notification::assertSentTo($this->westOfficer(), GeneralDatabaseNotification::class, function ($notification) use (&$text) {
            $text = json_encode($notification->toArray($this->westOfficer()));

            return true;
        });
        $this->assertStringNotContainsString((string) config('gwl.arkesel.api_key'), $text);
    }
}
