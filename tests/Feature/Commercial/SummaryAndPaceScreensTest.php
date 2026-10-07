<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Billing;
use App\Livewire\Commercial\Reading;
use App\Livewire\Commercial\Summary;
use App\Models\CommercialImportBatch;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ErpNavigation;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** The Summary page, the month-to-date pace card and the export buttons. "Today" is 15 Oct 2026. */
class SummaryAndPaceScreensTest extends CommercialTestCase
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

    protected function seedBoth(array $billingOptions = []): void
    {
        $this->seedReading([
            'R1' => ['2026-08-01' => [400, 100], '2026-09-01' => [450, 50]],
            'R2' => ['2026-08-01' => [200, 100], '2026-09-01' => [220, 80]],
        ], options: ['strength' => 1000, 'names' => ['R1' => 'ADWOA BOATENG', 'R2' => 'KWESI PAINTSIL'], 'status' => ['R2' => 'unmatched'], 'districts' => ['R1' => $this->sowutuom->id]]);

        $this->seedBilling($this->billingFixture(), '2026-09-01', $billingOptions + ['districts' => ['SOWUTUOM' => $this->sowutuom->id]]);
    }

    protected function userWith(string $staffId, array $permissions): User
    {
        $role = Role::query()->create(['name' => 'custom_'.$staffId, 'display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, []);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    // ---------------------------------------------------------------- Summary

    public function test_the_summary_shows_the_headlines_exceptions_freshness_and_quality_and_no_reader_names(): void
    {
        $this->seedBoth();

        Livewire::actingAs($this->officer())->test(Summary::class)
            ->assertSee('Sep 2026')                       // reading headline: latest complete month
            ->assertSee('latest complete month')
            ->assertSee('Configured target: at most 10%')
            ->assertSee('Above target')                   // skip rate 13.3% against 10%
            ->assertSee('GH¢ 1,600.00')
            ->assertSee('75.0%')                          // cash collection ratio
            ->assertSee('Configured target: at least 95%')
            ->assertSee('Below target')
            ->assertSee('Route exceptions')
            ->assertSee('Data freshness')
            ->assertSee('upload times')
            ->assertSee('Data quality')
            ->assertSee('Readers not in the staff directory')
            ->assertDontSee('ADWOA BOATENG')
            ->assertDontSee('KWESI PAINTSIL');
    }

    public function test_the_not_like_for_like_note_appears_only_when_the_segment_is_not_all(): void
    {
        $this->seedBoth();

        $page = Livewire::actingAs($this->officer())->test(Summary::class);
        $page->assertSee('Billing covers New Service customers only; reading covers all customers: not like for like.');

        CommercialImportBatch::query()->where('report_type', 'billing_summary')->update(['customer_segment' => 'all']);

        Livewire::actingAs($this->officer())->test(Summary::class)->assertDontSee('not like for like');
    }

    public function test_the_scorecard_and_the_estimation_chart_need_both_permissions(): void
    {
        $this->seedBoth();

        // Both: the district scorecard, with its caveats.
        Livewire::actingAs($this->officer())->test(Summary::class)
            ->assertSee('District scorecard')
            ->assertSee("grouped by the reader's home district")
            ->assertSee('An approximation')
            ->assertSee('Coverage is not shown per district')
            ->assertSee('Estimation and skip rate')
            ->assertSee('Indicative, not causal')
            ->assertSee('Sowutuom');

        // Billing alone, reading alone, and the dashboard permission alone do not get it.
        foreach ([['commercial.view_billing'], ['commercial.view_reading'], ['commercial.view_dashboard']] as $i => $permissions) {
            Livewire::actingAs($this->userWith('9007'.$i, $permissions))->test(Summary::class)->assertDontSee('District scorecard')->assertDontSee('Estimation and skip rate');
        }
    }

    public function test_each_section_is_computed_only_for_the_permissions_the_user_holds(): void
    {
        $this->seedBoth();

        $readingOnly = Livewire::actingAs($this->userWith('900701', ['commercial.view_reading']))->test(Summary::class);
        $readingOnly->assertSee('latest complete month')->assertDontSee('Cash collection ratio')->assertDontSee('Route exceptions')->assertDontSee('Billing periods loaded');

        $billingOnly = Livewire::actingAs($this->userWith('900702', ['commercial.view_billing']))->test(Summary::class);
        $billingOnly->assertSee('Cash collection ratio')->assertSee('Route exceptions')->assertDontSee('latest complete month')->assertDontSee('Readers not in the staff directory');

        $dashboard = Livewire::actingAs($this->userWith('900703', ['commercial.view_dashboard']))->test(Summary::class);
        $dashboard->assertSee('latest complete month')->assertSee('Cash collection ratio');
    }

    public function test_view_dashboard_alone_gives_the_same_numbers_on_home_summary_and_the_data_rule(): void
    {
        $this->seedBoth();
        $dashboard = $this->userWith('900705', ['commercial.view_dashboard']);

        // One rule everywhere: view_dashboard = the reading AND billing headline numbers (never the detail pages).
        $this->actingAs($dashboard);
        $data = app(\App\Services\Commercial\CommercialReportData::class);
        $this->assertTrue($data->canSeeReadingNumbers());
        $this->assertTrue($data->canSeeBillingNumbers());

        $home = Livewire::actingAs($dashboard)->test(\App\Livewire\Commercial\Home::class);
        $home->assertSee('latest complete month')->assertSee('Cash collection ratio')->assertSee('GH¢ 1,600.00');
        $home->assertDontSee('Open Meter Reading')->assertDontSee(route('commercial.billing'));

        Livewire::actingAs($dashboard)->test(Summary::class)->assertSee('latest complete month')->assertSee('Cash collection ratio');

        // ...and the detail pages stay closed to it.
        $this->actingAs($dashboard)->get(route('commercial.billing'))->assertForbidden();
        $this->actingAs($dashboard)->get(route('commercial.reading'))->assertForbidden();

        // A user holding neither reading nor billing nor dashboard sees neither set of tiles on Home.
        $uploader = $this->userWith('900706', ['commercial.upload_reports']);
        Livewire::actingAs($uploader)->test(\App\Livewire\Commercial\Home::class)->assertDontSee('latest complete month')->assertDontSee('Cash collection ratio');
    }

    public function test_a_user_with_no_view_permission_gets_403(): void
    {
        $this->seedBoth();
        $nothing = $this->userWith('900704', ['commercial.upload_reports']);

        $this->actingAs($nothing)->get(route('commercial.summary'))->assertForbidden();
        Livewire::actingAs($nothing)->test(Summary::class)->assertForbidden();
        $this->actingAs($this->officer())->get(route('commercial.summary'))->assertOk();
    }

    public function test_the_summary_is_region_scoped_and_empty_states_say_what_is_missing(): void
    {
        $this->seedBoth();
        $this->seedBilling([['district' => 'KUMASI', 'code' => 'K', 'billing_for_period' => 777000]], '2026-09-01', ['region' => $this->ashanti]);

        Livewire::actingAs($this->officer())->test(Summary::class)->assertSee('GH¢ 1,600.00')->assertDontSee('777,000');

        CommercialImportBatch::query()->delete();

        Livewire::actingAs($this->officer())->test(Summary::class)
            ->assertSee('No complete month of meter reading yet.')
            ->assertSee('No billing report has been loaded yet.');
    }

    public function test_the_summary_picker_changes_the_billing_snapshot_and_a_foreign_one_falls_back(): void
    {
        $this->seedBoth();
        $august = $this->seedBilling([['district' => 'D', 'code' => 'A', 'billing_for_period' => 4321]], '2026-08-01');
        $foreign = $this->seedBilling([['district' => 'K', 'code' => 'K', 'billing_for_period' => 777000]], '2026-09-01', ['region' => $this->ashanti]);

        $page = Livewire::actingAs($this->officer())->test(Summary::class)->assertSee('GH¢ 1,600.00');
        $page->set('snapshot', (string) $august->id)->assertSee('GH¢ 4,321.00')->assertDontSee('GH¢ 1,600.00');
        $page->set('snapshot', (string) $foreign->id)->assertDontSee('777,000')->assertSee('GH¢ 1,600.00');
    }

    // ---------------------------------------------------------------- R14 pace card

    public function test_the_reading_page_shows_month_to_date_pace_for_a_month_uploaded_more_than_once(): void
    {
        // September is complete (1,000 visits); October has been uploaded three times, one of them since voided.
        $this->seedReading(['R1' => ['2026-09-01' => [800, 200]]]);
        $one = $this->seedReading(['R1' => ['2026-10-01' => [150, 50]]]);
        $two = $this->seedReading(['R1' => ['2026-10-01' => [420, 100]]]);
        $voided = $this->seedReading(['R1' => ['2026-10-01' => [9999, 1]]]);
        $one->update(['imported_at' => '2026-10-08 00:00:00']);
        $two->update(['imported_at' => '2026-10-16 00:00:00']);
        $voided->update(['imported_at' => '2026-10-20 00:00:00', 'status' => CommercialImportBatch::STATUS_VOIDED]);

        Livewire::actingAs($this->officer())->test(Reading::class)
            ->assertSee('Month to date: Oct 2026')
            ->assertSee('indicative')
            ->assertSee('upload time')
            ->assertSee('22.6%')       // 7 / 31 of the month elapsed at the first upload
            ->assertSee('886')         // projected visits: 200 / (7/31)
            ->assertSee('-11.4%')      // against September's 1,000
            ->assertSee('1,075')
            ->assertSee('+7.5%')
            ->assertSee('Sep 2026 had 1,000 visits')
            ->assertDontSee('9,999');
    }

    public function test_a_month_with_a_single_upload_has_no_pace_card_only_a_hint(): void
    {
        $this->seedReading(['R1' => ['2026-09-01' => [800, 200], '2026-10-01' => [10, 0]]]);

        Livewire::actingAs($this->officer())->test(Reading::class)
            ->assertDontSee('Month to date:')
            ->assertSee('Month-to-date pace appears once a month has been uploaded at least twice');
    }

    public function test_the_pace_card_is_region_scoped(): void
    {
        $mine = $this->seedReading(['R1' => ['2026-10-01' => [100, 0]]]);
        $theirs = $this->seedReading(['R9' => ['2026-10-01' => [100, 0]]], $this->ashanti);
        $theirsToo = $this->seedReading(['R9' => ['2026-10-01' => [300, 0]]], $this->ashanti);
        $mine->update(['imported_at' => '2026-10-05 00:00:00']);
        $theirs->update(['imported_at' => '2026-10-05 00:00:00']);
        $theirsToo->update(['imported_at' => '2026-10-12 00:00:00']);

        // Only one upload is in the officer's region, so there is nothing to pace; head office sees Ashanti's two.
        Livewire::actingAs($this->officer())->test(Reading::class)->assertDontSee('Month to date:');
        Livewire::actingAs($this->officer('900010', $this->accraWest, $this->headOffice))->test(Reading::class)->assertSee('Month to date: Oct 2026');
    }

    // ---------------------------------------------------------------- export buttons and sidebar

    public function test_export_buttons_show_only_to_users_who_may_use_them(): void
    {
        $this->seedBoth();
        $officer = $this->officer();

        Livewire::actingAs($officer)->test(Reading::class)->assertSee('/commercial/export/reading-trend/excel', false)
            ->set('tab', 'readers')->assertSee('/commercial/export/readers/excel', false)->assertSee('/commercial/export/readers/pdf', false);
        Livewire::actingAs($officer)->test(Billing::class)->assertSee('/commercial/export/billing/excel', false)->assertSee('/commercial/export/billing/pdf', false);
        Livewire::actingAs($officer)->test(Summary::class)->assertSee('/commercial/export/summary/pdf', false)->assertSee('/commercial/export/scorecard/excel', false);

        // A chief manager reads but cannot export.
        $chief = $this->userWithRoles('900710', ['regional_chief_manager']);
        Livewire::actingAs($chief)->test(Reading::class)->assertDontSee('/commercial/export/', false);
        Livewire::actingAs($chief)->test(Billing::class)->assertDontSee('/commercial/export/', false);
        Livewire::actingAs($chief)->test(Summary::class)->assertDontSee('/commercial/export/', false);

        // Exporting without seeing reader performance: no reader export button.
        $limited = $this->userWith('900711', ['commercial.export_reports', 'commercial.view_reading']);
        Livewire::actingAs($limited)->test(Reading::class)->assertSee('/commercial/export/reading-trend/excel', false)->assertDontSee('/commercial/export/readers', false);
    }

    public function test_the_summary_sidebar_item_follows_the_permissions(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn (User $user) => collect($navigation->build($user, 'commercial')['sidebar'])->pluck('label')->all();

        $this->assertContains('Summary', $labels($this->officer()));
        $this->assertContains('Summary', $labels($this->userWith('900720', ['commercial.view_reading'])));
        $this->assertContains('Summary', $labels($this->userWith('900721', ['commercial.view_billing'])));
        $this->assertContains('Summary', $labels($this->userWith('900722', ['commercial.view_dashboard'])));
        $this->assertNotContains('Summary', $labels($this->userWith('900723', ['commercial.upload_reports'])));
        $this->assertContains('Summary', $labels($this->superAdmin()));
    }
}
