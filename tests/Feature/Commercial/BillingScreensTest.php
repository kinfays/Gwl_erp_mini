<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Billing;
use App\Livewire\Commercial\Home;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ErpNavigation;
use Livewire\Livewire;

/** The billing screens as users meet them. The fixture is billingFixture() (arithmetic in BillingAnalyticsTest). */
class BillingScreensTest extends CommercialTestCase
{
    private const TABS = ['overview', 'collections', 'balances', 'estimation', 'bands', 'exceptions', 'compare'];

    // ---------------------------------------------------------------- the numbers on screen

    public function test_the_overview_shows_hand_computed_totals_and_the_pareto(): void
    {
        $this->seedBilling($this->billingFixture());

        Livewire::actingAs($this->officer())->test(Billing::class)
            ->assertSee('GH¢ 1,600.00')    // billing
            ->assertSee('94.12')           // 1600 / 17 per billed customer
            ->assertSee('GH¢ 10.00')       // per m3
            ->assertSee('68.8%')           // SOWUTUOM's share (68.75)
            ->assertSee('100.0%')          // the top routes carry all of a 4-route snapshot
            ->assertSee('Balance roll-forward')
            ->assertSee('SOWUTUOM 1');
    }

    public function test_the_collections_tab_shows_ratios_from_the_sums_and_the_configured_target(): void
    {
        $this->seedBilling($this->billingFixture());

        Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'collections')
            ->assertSee('75.0%')           // 1200 / 1600
            ->assertSee('43.8%')           // 700 / 1600
            ->assertSee('41.7%')           // 500 / 1200 arrears
            ->assertSee('Configured target: 95%')
            ->assertSee('placeholder');
    }

    public function test_balances_and_exceptions_carry_the_credit_hint_and_not_as_fact(): void
    {
        $this->seedBilling($this->billingFixture());

        Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'balances')
            ->assertSee('Negative balances are treated as customer credits (to be confirmed')
            ->assertSee('ODORKOR 1')
            ->assertSee('300.00')
            ->set('tab', 'exceptions')
            ->assertSee('Negative balances are treated as customer credits (to be confirmed')
            ->assertSee('High unbilled')
            ->assertSee('Heavy credit')
            ->assertSee('No activity')
            ->assertSee('Placeholder thresholds');
    }

    public function test_the_estimation_and_bands_tabs(): void
    {
        $this->seedBilling($this->billingFixture(), options: ['bands' => [['<=5', 80, 400, 3000], ['>5', 20, 600, 7000]]]);

        Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'estimation')
            ->assertSee('31.3%')           // 50 / 160 by volume (31.25)
            ->assertSee('41.2%')           // 7 / 17 by count
            ->assertSee('32.0%')           // unbilled rate
            ->assertSee('Suspense, metered')
            ->set('tab', 'bands')
            ->assertSee('Domestic consumption bands (category 611)')
            ->assertSee('11.67')           // GH¢ per m3 of the upper band
            ->assertSee('7.50');
    }

    public function test_a_district_link_filters_every_figure_to_that_district(): void
    {
        $this->seedBilling($this->billingFixture());

        Livewire::actingAs($this->officer())->test(Billing::class)
            ->set('district', 'SOWUTUOM')
            ->assertSee('Showing')
            ->assertSee('GH¢ 1,100.00')
            ->assertDontSee('ODORKOR 1')
            ->set('district', 'NOT A DISTRICT')
            ->assertSee('GH¢ 1,600.00');
    }

    public function test_customers_count_never_appears_on_any_tab(): void
    {
        $this->seedBilling($this->billingFixture(), options: ['bands' => [['<=5', 80, 400, 3000]]]);

        $component = Livewire::actingAs($this->officer())->test(Billing::class);

        foreach (self::TABS as $tab) {
            $component->set('tab', $tab)->assertDontSee('7777')->assertDontSee('7,777')->assertDontSee('Number Of Customers');
        }
    }

    // ---------------------------------------------------------------- snapshots, compare, trend

    public function test_the_picker_offers_snapshots_and_a_multi_month_period_is_usable_but_cannot_be_compared(): void
    {
        $this->seedBilling($this->billingFixture(), '2026-06-01', ['period_to' => '2026-08-31']);
        $this->seedBilling($this->billingFixture(), '2026-09-01');

        $component = Livewire::actingAs($this->officer())->test(Billing::class);
        $component->assertSee('Sep 2026')->assertSee('Jun to Aug 2026 (period)');

        // The default is the single month; the period is usable once picked, with the muted note.
        $period = \App\Models\CommercialImportBatch::query()->where('period_to', '>=', '2026-08-31')->whereDate('period_from', '2026-06-01')->firstOrFail();

        $component->set('snapshot', (string) $period->id)
            ->assertSee('multi-month period')
            ->assertSee('GH¢ 1,600.00')
            ->set('tab', 'compare')
            ->assertSee('cannot be compared or trended');
    }

    public function test_compare_and_trend_on_single_month_snapshots(): void
    {
        $this->seedBilling([['district' => 'D', 'code' => 'R', 'billing_for_period' => 1000, 'total_payments' => 500, 'payment_for_month' => 250, 'billed_total' => 9, 'unbilled_total' => 1]], '2026-07-01');
        $this->seedBilling([['district' => 'D', 'code' => 'R', 'billing_for_period' => 1000, 'total_payments' => 800, 'payment_for_month' => 400, 'billed_total' => 9, 'unbilled_total' => 1]], '2026-08-01');

        $two = Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'compare');
        $two->assertSee('Compare two months')->assertSee('+30.0 pts')->assertSee('No trend yet')->assertSee('there are 2');

        $this->seedBilling([['district' => 'D', 'code' => 'R', 'billing_for_period' => 1000, 'total_payments' => 900, 'payment_for_month' => 450, 'billed_total' => 9, 'unbilled_total' => 1]], '2026-09-01');

        Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'compare')
            ->assertSee('Monthly trend')
            ->assertDontSee('No trend yet')
            ->assertSee('Jul 2026');
    }

    public function test_a_snapshot_with_no_band_table_shows_an_empty_state(): void
    {
        $this->seedBilling($this->billingFixture(), options: ['bands' => false]);

        Livewire::actingAs($this->officer())->test(Billing::class)->set('tab', 'bands')->assertSee('has no consumption-band table');
    }

    public function test_with_no_billing_batch_there_is_an_empty_state(): void
    {
        Livewire::actingAs($this->officer())->test(Billing::class)->assertSee('No billing report has been loaded yet.');
    }

    // ---------------------------------------------------------------- scoping and permissions

    public function test_a_regional_user_only_gets_their_regions_snapshots_and_a_hand_edited_snapshot_id_falls_back_safely(): void
    {
        $own = $this->seedBilling([['district' => 'D', 'code' => 'OWN', 'billing_for_period' => 1000]]);
        $other = $this->seedBilling([['district' => 'D', 'code' => 'OTHER', 'billing_for_period' => 99999]], '2026-09-01', ['region' => $this->ashanti]);

        $page = Livewire::actingAs($this->officer())->test(Billing::class);
        $page->assertSee('GH¢ 1,000.00')->assertDontSee('Ashanti')->assertDontSee('99,999.00');

        // Typing another region's batch id into the URL must not reveal it, nor say that it exists: the default is shown.
        $page->set('snapshot', (string) $other->id)
            ->assertSee('GH¢ 1,000.00')
            ->assertSee('batch #'.$own->id)
            ->assertDontSee('99,999.00')
            ->assertDontSee('OTHER');

        // Head office sees both and may pick either.
        $headOffice = $this->officer('900010', $this->accraWest, $this->headOffice);
        Livewire::actingAs($headOffice)->test(Billing::class)->set('snapshot', (string) $other->id)->assertSee('99,999.00')->assertSee('Ashanti');
    }

    public function test_a_user_without_view_billing_gets_403_and_no_billing_tiles_on_home(): void
    {
        $this->seedBilling($this->billingFixture());

        $role = Role::query()->create(['name' => 'commercial_upload_only', 'display_name' => 'Upload only', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('name', 'commercial.upload_reports')->value('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $uploader = $this->userWithRoles('900050', []);
        $uploader->roles()->attach($role);
        $uploader = $uploader->fresh();

        $this->actingAs($uploader)->get(route('commercial.billing'))->assertForbidden();
        Livewire::actingAs($uploader)->test(Billing::class)->assertForbidden();
        Livewire::actingAs($uploader)->test(Home::class)->assertDontSee('Cash collection ratio')->assertDontSee('GH¢ 1,600.00');

        // A holder of view_billing gets in.
        $this->actingAs($this->userWithRoles('900051', ['regional_chief_manager']))->get(route('commercial.billing'))->assertOk();
    }

    public function test_the_sidebar_item_follows_the_permission(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'commercial')['sidebar'])->pluck('label')->all();

        $this->assertContains('Billing', $labels($this->officer()));
        $this->assertContains('Billing', $labels($this->userWithRoles('900052', ['commercial_manager'])));
        $this->assertContains('Billing', $labels($this->userWithRoles('900053', ['district_manager'])));
        $this->assertContains('Billing', $labels($this->superAdmin()));

        $role = Role::query()->create(['name' => 'commercial_reading_only', 'display_name' => 'Reading only', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('name', 'commercial.view_reading')->value('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $reader = $this->userWithRoles('900054', []);
        $reader->roles()->attach($role);

        $this->assertNotContains('Billing', $labels($reader->fresh()));
    }

    // ---------------------------------------------------------------- Home

    public function test_home_shows_billing_tiles_for_the_default_snapshot_with_its_period_labelled(): void
    {
        $this->seedBilling($this->billingFixture(), '2026-09-01');
        $this->seedBilling([['district' => 'D', 'code' => 'P', 'billing_for_period' => 1]], '2026-06-01', ['period_to' => '2026-08-31']);

        Livewire::actingAs($this->officer())->test(Home::class)
            ->assertSee('Billing, Sep 2026')      // the single month, not the later-ending period
            ->assertSee('GH¢ 1,600.00')
            ->assertSee('GH¢ 1,200.00')           // payments
            ->assertSee('75.0%')                  // cash collection ratio
            ->assertSee('32.0%')                  // unbilled rate
            ->assertSee('Configured target: 95%');
    }

    public function test_home_billing_tiles_are_scoped_to_the_users_region(): void
    {
        $this->seedBilling($this->billingFixture());
        $this->seedBilling([['district' => 'D', 'code' => 'A', 'billing_for_period' => 777000]], '2026-09-01', ['region' => $this->ashanti]);

        Livewire::actingAs($this->officer())->test(Home::class)->assertSee('GH¢ 1,600.00')->assertDontSee('777,000');
    }
}
