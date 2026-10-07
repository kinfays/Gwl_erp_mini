<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Billing;
use App\Livewire\Commercial\Home;
use App\Livewire\Commercial\Reading;
use App\Livewire\Commercial\Settings;
use App\Models\AuditLog;
use App\Models\CommercialSetting;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\CommercialSettings;
use App\Services\Commercial\ReadingAnalyticsService;
use App\Support\ErpNavigation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/** The settings screen and the overlay that makes every screen and export see an edited value. "Today" is 15 Oct 2026. */
class CommercialSettingsTest extends CommercialTestCase
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

    private function settings(): CommercialSettings
    {
        return app(CommercialSettings::class);
    }

    // ---------------------------------------------------------------- access

    public function test_only_a_holder_of_manage_settings_reaches_the_settings_screen(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->get(route('commercial.settings'))->assertOk()->assertSee('Commercial settings')->assertSee('placeholders');

        foreach (['commercial_officer', 'commercial_manager', 'regional_chief_manager', 'district_manager'] as $i => $role) {
            $user = $this->userWithRoles('90080'.$i, [$role]);

            $this->assertFalse($user->hasPermission('commercial.manage_settings'));
            $this->actingAs($user)->get(route('commercial.settings'))->assertForbidden();
            Livewire::actingAs($user)->test(Settings::class)->assertForbidden();
        }
    }

    public function test_the_sidebar_item_follows_the_permission(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'commercial')['sidebar'])->pluck('label')->all();

        $this->assertContains('Settings', $labels($this->superAdmin()));
        $this->assertNotContains('Settings', $labels($this->officer()));
        $this->assertNotContains('Settings', $labels($this->userWithRoles('900810', ['commercial_manager'])));
    }

    // ---------------------------------------------------------------- saving

    public function test_an_edited_value_is_stored_as_an_override_audited_and_used_everywhere(): void
    {
        $admin = $this->superAdmin();
        $this->assertSame(10.0, (float) config('gwl.commercial_target_skip_rate_pct'));

        Livewire::actingAs($admin)->test(Settings::class)
            ->assertSet('values.commercial_target_skip_rate_pct', 10.0)
            ->set('values.commercial_target_skip_rate_pct', '12.5')
            ->set('values.commercial_exception_credit_amount', '450')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('12.5', CommercialSetting::query()->where('name', 'commercial_target_skip_rate_pct')->value('value'));
        $this->assertSame($admin->id, CommercialSetting::query()->where('name', 'commercial_target_skip_rate_pct')->value('updated_by'));
        $this->assertSame(12.5, config('gwl.commercial_target_skip_rate_pct'), 'laid over the config at once');
        $this->assertSame(450.0, config('gwl.commercial_exception_credit_amount'));
        $this->assertSame(2, CommercialSetting::query()->count(), 'only the two edited values are stored');

        $log = AuditLog::query()->where('action', 'commercial.settings_changed')->sole();
        $this->assertEquals(['from' => 10.0, 'to' => 12.5], $log->metadata['changes']['commercial_target_skip_rate_pct']);
        $this->assertEquals(['from' => 300.0, 'to' => 450.0], $log->metadata['changes']['commercial_exception_credit_amount']);
        $this->assertArrayNotHasKey('commercial_target_coverage_pct', $log->metadata['changes']);
    }

    public function test_the_edited_target_is_what_the_screens_show(): void
    {
        $this->seedReading(['R1' => ['2026-08-01' => [400, 100], '2026-09-01' => [450, 50]]], options: ['strength' => 1000]);
        $this->settings()->save(['commercial_target_skip_rate_pct' => 12, 'commercial_target_coverage_pct' => 80], $this->superAdmin()->id);

        Livewire::actingAs($this->officer())->test(Home::class)
            ->assertSee('Configured target: 12%')
            ->assertSee('Configured target: 80%')
            ->assertDontSee('Configured target: 10%');
    }

    public function test_a_value_saved_equal_to_its_default_is_no_override_at_all(): void
    {
        $this->settings()->save(['commercial_target_skip_rate_pct' => 12], $this->superAdmin()->id);
        $this->assertTrue($this->settings()->isOverridden('commercial_target_skip_rate_pct'));

        $this->settings()->save(['commercial_target_skip_rate_pct' => 10], $this->superAdmin()->id);

        $this->assertFalse($this->settings()->isOverridden('commercial_target_skip_rate_pct'));
        $this->assertSame(0, CommercialSetting::query()->count(), 'so a later change to .env still takes effect');
        $this->assertSame(10.0, config('gwl.commercial_target_skip_rate_pct'));
    }

    public function test_saving_with_nothing_changed_writes_nothing_and_no_audit_row(): void
    {
        Livewire::actingAs($this->superAdmin())->test(Settings::class)->call('save')->assertHasNoErrors();

        $this->assertSame(0, CommercialSetting::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'commercial.settings_changed')->count());
    }

    // ---------------------------------------------------------------- validation

    public function test_out_of_range_and_inconsistent_values_are_refused_and_nothing_is_saved(): void
    {
        $page = Livewire::actingAs($this->superAdmin())->test(Settings::class);

        $page->set('values.commercial_target_skip_rate_pct', '150')->call('save')->assertHasErrors('values.commercial_target_skip_rate_pct');
        $page->set('values.commercial_target_skip_rate_pct', '10')
            ->set('values.commercial_min_visits_for_outlier', '5.5')->call('save')->assertHasErrors('values.commercial_min_visits_for_outlier');
        $page->set('values.commercial_min_visits_for_outlier', '200')
            ->set('values.commercial_workload_low_pct', '160')->call('save')->assertHasErrors('values.commercial_workload_low_pct');
        $page->set('values.commercial_workload_low_pct', '50')
            ->set('values.commercial_exception_min_customers', '0')->call('save')->assertHasErrors('values.commercial_exception_min_customers');
        $page->set('values.commercial_exception_min_customers', '3')
            ->set('values.commercial_target_collection_pct', 'abc')->call('save')->assertHasErrors('values.commercial_target_collection_pct');

        $this->assertSame(0, CommercialSetting::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'commercial.settings_changed')->count());
    }

    public function test_the_scorecard_weights_must_add_up_to_one(): void
    {
        $page = Livewire::actingAs($this->superAdmin())->test(Settings::class);

        $page->set('values.commercial_scorecard_weights__volume', '0.6')->call('save')
            ->assertHasErrors('values.commercial_scorecard_weights__volume');
        $this->assertStringContainsString('add up to 1', collect($page->errors()->get('values.commercial_scorecard_weights__volume'))->first());
        $this->assertSame(0, CommercialSetting::query()->count());

        $page->set('values.commercial_scorecard_weights__volume', '0.5')
            ->set('values.commercial_scorecard_weights__skip', '0.3')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(0.5, config('gwl.commercial_scorecard_weights.volume'));
        $this->assertSame(0.3, config('gwl.commercial_scorecard_weights.skip'));
        $this->assertSame(0.2, config('gwl.commercial_scorecard_weights.consistency'), 'the third is still the default');
    }

    // ---------------------------------------------------------------- the analyses honour an edited value

    public function test_the_billing_exception_thresholds_come_from_the_settings(): void
    {
        $this->seedBilling($this->billingFixture());
        $page = Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'exceptions');

        $page->assertSee('Heavy credit');
        $flagged = fn () => collect(app(BillingAnalyticsService::class)->exceptions(\App\Models\CommercialBillingRoute::query()->with('district')->get())['rows'])->keyBy('route');

        $this->assertContains('heavy_credit', $flagged()['ODORKOR 1']['flags']);

        $this->settings()->save(['commercial_exception_credit_amount' => 400, 'commercial_exception_high_unbilled_pct' => 90], $this->superAdmin()->id);

        $this->assertArrayNotHasKey('ODORKOR 1', $flagged()->all(), '-300 is no longer a heavy credit once the amount is 400');
        $this->assertArrayNotHasKey('SOWUTUOM 2', $flagged()->all(), '80% unbilled is under a 90% threshold');
        $this->assertSame(400.0, app(BillingAnalyticsService::class)->exceptions(collect())['thresholds']['credit_amount']);
    }

    public function test_the_reader_thresholds_and_scorecard_weights_come_from_the_settings(): void
    {
        $this->seedReading([
            'A' => ['2026-06-01' => [900, 100], '2026-07-01' => [900, 100], '2026-08-01' => [900, 100]],
            'B' => ['2026-06-01' => [300, 100], '2026-07-01' => [300, 100], '2026-08-01' => [300, 100]],
        ]);
        $stats = fn () => \App\Models\CommercialReadingStat::query()->effective()->readers()->get();
        $analytics = new ReadingAnalyticsService;

        $this->assertSame(0, $analytics->outliers($stats(), now())['excluded']);

        $this->settings()->save(['commercial_min_visits_for_outlier' => 500], $this->superAdmin()->id);
        $this->assertSame(1, $analytics->outliers($stats(), now())['excluded'], 'B does 400 visits a month: under the new minimum of 500');

        $this->settings()->save(['commercial_scorecard_weights.volume' => 0.7, 'commercial_scorecard_weights.skip' => 0.2, 'commercial_scorecard_weights.consistency' => 0.1], $this->superAdmin()->id);
        $this->assertSame(0.7, $analytics->scorecard($stats(), now())['weights']['volume']);

        Livewire::actingAs($this->officer())->test(Reading::class)->set('tab', 'scorecard')->assertSee('volume 70%');
    }

    // ---------------------------------------------------------------- live preview of the exception thresholds

    private function flaggedNow(): int
    {
        return count(app(BillingAnalyticsService::class)->exceptions(\App\Models\CommercialBillingRoute::query()->with('district')->get())['rows']);
    }

    public function test_the_exception_preview_counts_what_the_typed_values_would_flag_next_to_what_is_flagged_now(): void
    {
        $this->seedBilling($this->billingFixture());
        $now = $this->flaggedNow();
        $this->assertGreaterThan(0, $now);

        $page = Livewire::actingAs($this->superAdmin())->test(Settings::class);
        $page->assertSee("With these values <strong>{$now} of 4</strong> routes in the latest billing snapshot", false)->assertSee("(now: {$now})", false);

        // -300 is no longer a heavy credit once the amount is 400, so one route drops out.
        $page->set('values.commercial_exception_credit_amount', 400);
        $page->assertSee('<strong>'.($now - 1).' of 4</strong>', false)->assertSee("(now: {$now})", false)->assertSee('Nothing changes until you save');

        $this->assertSame(0, CommercialSetting::query()->count(), 'the preview saves nothing');
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.setting%')->count());
        $this->assertSame(300.0, (float) config('gwl.commercial_exception_credit_amount'), 'and the configured value is untouched');

        // Saving makes the new value the one "now" is measured against.
        $page->call('save');
        $page->assertSee('<strong>'.($now - 1).' of 4</strong>', false)->assertSee('(now: '.($now - 1).')', false);
    }

    public function test_a_value_that_is_not_valid_yet_is_ignored_by_the_preview(): void
    {
        $this->seedBilling($this->billingFixture());
        $now = $this->flaggedNow();

        $page = Livewire::actingAs($this->superAdmin())->test(Settings::class);

        foreach (['', 'abc', -5, 99999999, 'x1'] as $typed) {
            $page->set('values.commercial_exception_credit_amount', $typed)->assertSee("With these values <strong>{$now} of 4</strong>", false);
        }

        $page->set('values.commercial_exception_min_customers', 2.5)->assertSee("With these values <strong>{$now} of 4</strong>", false);
    }

    public function test_the_exception_preview_says_so_when_there_is_no_billing_report_yet(): void
    {
        Livewire::actingAs($this->superAdmin())->test(Settings::class)
            ->assertSee('Upload a billing report to see how many routes these values would flag.')
            ->assertDontSee('With these values');
    }

    public function test_the_preview_counts_the_latest_billing_snapshot_not_an_older_one(): void
    {
        $this->seedBilling([['district' => 'SOWUTUOM', 'code' => 'OLD', 'billing_for_period' => 10]], '2026-08-01');
        $this->seedBilling($this->billingFixture(), '2026-09-01');

        Livewire::actingAs($this->superAdmin())->test(Settings::class)->assertSee(' of 4</strong> routes in the latest billing snapshot', false);
    }

    // ---------------------------------------------------------------- overlay, reset, defaults

    public function test_overrides_are_laid_over_the_config_by_apply_to_config(): void
    {
        CommercialSetting::query()->create(['name' => 'commercial_reminder_reading_days', 'value' => '21']);
        CommercialSetting::query()->create(['name' => 'commercial_reminders_enabled', 'value' => '0']);
        CommercialSetting::query()->create(['name' => 'commercial_scorecard_weights.skip', 'value' => '0.3']);
        CommercialSetting::query()->create(['name' => 'not_a_setting', 'value' => '1']);
        Cache::forget(CommercialSettings::CACHE_KEY);

        $this->assertSame(10, config('gwl.commercial_reminder_reading_days'));

        $this->settings()->applyToConfig();

        $this->assertSame(21, config('gwl.commercial_reminder_reading_days'));
        $this->assertFalse(config('gwl.commercial_reminders_enabled'));
        $this->assertSame(0.3, config('gwl.commercial_scorecard_weights.skip'));
        $this->assertNull(config('gwl.not_a_setting'), 'only known settings are applied');
    }

    public function test_apply_to_config_is_quiet_before_the_table_exists(): void
    {
        \Illuminate\Support\Facades\Schema::drop('commercial_settings');
        Cache::forget(CommercialSettings::CACHE_KEY);

        $this->settings()->applyToConfig();   // a fresh install or a migration in progress must not throw

        $this->assertSame(10.0, (float) config('gwl.commercial_target_skip_rate_pct'));
    }

    public function test_reset_puts_a_group_or_everything_back_to_the_defaults_and_audits_it(): void
    {
        $admin = $this->superAdmin();
        $this->settings()->save(['commercial_target_skip_rate_pct' => 12, 'commercial_target_coverage_pct' => 80, 'commercial_exception_credit_amount' => 450], $admin->id);

        Livewire::actingAs($admin)->test(Settings::class)
            ->assertSee('Edited')
            ->call('resetGroup', 'targets')
            ->assertSet('values.commercial_target_skip_rate_pct', 10.0);

        $this->assertSame(10.0, config('gwl.commercial_target_skip_rate_pct'));
        $this->assertSame(90.0, config('gwl.commercial_target_coverage_pct'));
        $this->assertSame(450.0, config('gwl.commercial_exception_credit_amount'), 'another group is left alone');
        $this->assertSame(['commercial_exception_credit_amount'], CommercialSetting::query()->pluck('name')->all());

        $reset = AuditLog::query()->where('action', 'commercial.settings_reset')->sole();
        $this->assertEquals(['from' => 12.0, 'to' => 10.0], $reset->metadata['reset']['commercial_target_skip_rate_pct']);

        Livewire::actingAs($admin)->test(Settings::class)->call('resetAll');
        $this->assertSame(0, CommercialSetting::query()->count());
        $this->assertSame(300.0, config('gwl.commercial_exception_credit_amount'));
    }

    public function test_the_screen_shows_each_default_and_who_changed_a_value(): void
    {
        $admin = $this->superAdmin();
        $this->settings()->save(['commercial_target_collection_pct' => 90], $admin->id);

        Livewire::actingAs($admin)->test(Settings::class)
            ->assertSee('Default 95 %')
            ->assertSee('Cash collection target')
            ->assertSee('changed 15 Oct 2026')
            ->assertSee($admin->full_name)
            ->assertSee('Billing route exceptions')
            ->assertSee('Upload reminders')
            ->assertSee('Reader scorecard weights');
    }

    public function test_the_defaults_come_from_a_copy_of_the_config_never_from_a_file_so_config_cache_cannot_break_them(): void
    {
        $settings = $this->settings();

        // What .env gave (8). config/gwl.php's own hard-coded fallback is 10, which is all a re-read of the file could return
        // once `php artisan config:cache` has stopped .env being loaded.
        config(['gwl.commercial_target_skip_rate_pct' => 8.0]);
        $settings->forgetPristine();
        $settings->applyToConfig();   // what boot does: keep the copy before laying anything over the config

        $this->assertSame(8.0, $settings->default('commercial_target_skip_rate_pct'));
        $this->assertStringNotContainsString('require config_path', file_get_contents(app_path('Services/Commercial/CommercialSettings.php')));

        $admin = $this->superAdmin();

        $settings->save(['commercial_target_skip_rate_pct' => 8.0], $admin->id);
        $this->assertFalse($settings->isOverridden('commercial_target_skip_rate_pct'), '8 is the true default, so it is no override');
        $this->assertSame(0, CommercialSetting::query()->count());

        $settings->save(['commercial_target_skip_rate_pct' => 9], $admin->id);
        $this->assertTrue($settings->isOverridden('commercial_target_skip_rate_pct'));
        $this->assertSame(9.0, config('gwl.commercial_target_skip_rate_pct'));
        $this->assertSame(8.0, $settings->default('commercial_target_skip_rate_pct'), 'the default is untouched by the override');

        Livewire::actingAs($admin)->test(Settings::class)->assertSee('Default 8 %')->assertSet('values.commercial_target_skip_rate_pct', 9.0);

        $settings->reset(['commercial_target_skip_rate_pct']);
        $this->assertSame(8.0, config('gwl.commercial_target_skip_rate_pct'), 'after a reset the config holds the pristine value');
        $this->assertSame(0, CommercialSetting::query()->count());
    }

    public function test_the_excel_row_cap_cannot_be_raised_past_20000_and_the_pdf_cap_has_its_own_lower_limit(): void
    {
        $definitions = CommercialSettings::definitions();

        $this->assertSame(20000, $definitions['commercial_export_max_rows']['max']);
        $this->assertSame(3000, $definitions['commercial_export_pdf_max_rows']['max']);
        $this->assertLessThan((int) config('gwl.commercial_export_max_rows'), (int) config('gwl.commercial_export_pdf_max_rows'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->settings()->save(['commercial_export_max_rows' => 20001], $this->superAdmin()->id);
    }

    public function test_the_service_is_one_shared_instance_so_the_pristine_copy_survives_between_uses(): void
    {
        $this->assertSame(app(CommercialSettings::class), app(CommercialSettings::class));
    }

    public function test_every_definition_has_a_default_in_config_and_an_entry_in_env_example(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        foreach (array_keys(CommercialSettings::definitions()) as $key) {
            $this->assertNotNull($this->settings()->default($key), "{$key} has no default in config/gwl.php");
        }

        foreach (['GWL_COMMERCIAL_EXPORT_PDF_MAX_ROWS', 'GWL_COMMERCIAL_REMINDERS_ENABLED', 'GWL_COMMERCIAL_REMINDER_READING_DAYS', 'GWL_COMMERCIAL_REMINDER_BILLING_DAYS', 'GWL_COMMERCIAL_REMINDER_REPEAT_DAYS'] as $variable) {
            $this->assertStringContainsString($variable.'=', $envExample);
        }
    }
}
