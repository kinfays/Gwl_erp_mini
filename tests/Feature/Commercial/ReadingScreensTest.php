<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Home;
use App\Livewire\Commercial\ReaderDetail;
use App\Livewire\Commercial\Reading;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ErpNavigation;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** The reading screens as users meet them. "Today" is 15 Oct 2026: Jun-Sep are complete months, October is in progress. */
class ReadingScreensTest extends CommercialTestCase
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

    // ---------------------------------------------------------------- Trend & coverage

    public function test_the_trend_tab_shows_hand_computed_rates_and_marks_the_month_in_progress(): void
    {
        $this->seedReading([
            '1001' => ['2026-09-01' => [400, 100], '2026-10-01' => [100, 25]],
            '1002' => ['2026-09-01' => [200, 200], '2026-10-01' => [0, 0]],
        ], options: ['strength' => ['2026-09-01' => 10000, '2026-10-01' => 10800]]);

        Livewire::actingAs($this->officer())->test(Reading::class)
            ->assertSee('Sep 2026')
            ->assertSee('33.3%')      // skip rate: 300 / 900
            ->assertSee('66.7%')      // read rate
            ->assertSee('9.0%')       // coverage: 900 / 10,000
            ->assertSee('10,000')
            ->assertSee('In progress')
            ->assertSee('Configured targets');
    }

    public function test_with_no_reading_batch_there_is_an_empty_state(): void
    {
        Livewire::actingAs($this->officer())->test(Reading::class)->assertSee('No meter reading report has been loaded yet.');
    }

    public function test_a_district_filter_is_by_home_district_and_hides_the_region_wide_coverage(): void
    {
        $this->seedReading([
            '1001' => ['2026-09-01' => [90, 10]],
            '1002' => ['2026-09-01' => [450, 50]],
        ], options: ['strength' => 10000, 'districts' => ['1001' => $this->sowutuom->id, '1002' => $this->odorkor->id]]);

        $all = Livewire::actingAs($this->officer())->test(Reading::class)->assertSee('6.0%'); // 600 / 10,000

        $all->set('district', (string) $this->sowutuom->id)
            ->assertSee('home district')
            ->assertSee('Coverage is not shown for one district')
            ->assertDontSee('6.0%');
    }

    // ---------------------------------------------------------------- Readers

    public function test_the_readers_tab_ranks_readers_flags_the_unmatched_and_never_lists_the_system_account(): void
    {
        $this->seedReading([
            '1001' => $this->everyMonth(['2026-08-01', '2026-09-01'], 300, 100),
            '1002' => $this->everyMonth(['2026-08-01', '2026-09-01'], 100, 100),
            '00000' => $this->everyMonth(['2026-08-01', '2026-09-01'], 9, 1),
        ], options: [
            'names' => ['1001' => 'ADWOA BOATENG', '1002' => 'KWESI PAINTSIL', '00000' => 'System Administrator'],
            'status' => ['1002' => 'unmatched', '00000' => 'system_account'],
        ]);

        Livewire::actingAs($this->officer())->test(Reading::class, ['tab' => 'readers'])
            ->set('tab', 'readers')
            ->assertSee('League table')
            ->assertSee('ADWOA BOATENG')
            ->assertSee('KWESI PAINTSIL')
            ->assertSee('Not in staff directory')
            ->assertDontSee('System Administrator')
            ->assertSee('Not enough months')   // consistency needs 3 complete months, there are 2
            ->assertSee('Month-on-month movement');
    }

    public function test_reader_names_come_from_the_directory_when_matched(): void
    {
        $employee = $this->employee('1001', 'Akua Directory-Name', $this->accraWest, $this->odorkor);
        $batch = $this->seedReading(['1001' => ['2026-09-01' => [10, 0]]], options: ['names' => ['1001' => 'RAW NAME IN REPORT']]);
        $batch->stats()->update(['employee_id' => $employee->id, 'district_id' => $this->odorkor->id]);

        Livewire::actingAs($this->officer())->test(Reading::class)
            ->set('tab', 'readers')
            ->assertSee('Akua Directory-Name')
            ->assertSee('Odorkor')
            ->assertDontSee('RAW NAME IN REPORT');
    }

    public function test_the_exceptions_and_scorecard_tabs_render_with_their_labels(): void
    {
        $this->seedReading(array_map(
            fn ($i) => $this->everyMonth(['2026-07-01', '2026-08-01', '2026-09-01'], 100 + $i * 10, 20),
            array_combine(['1', '2', '3'], [1, 2, 3])
        ));

        Livewire::actingAs($this->officer())->test(Reading::class)
            ->set('tab', 'exceptions')
            ->assertSee('Inactive and under-used readers')
            ->assertSee('Workload balance')
            ->assertSee('Skip-rate outliers')
            ->set('tab', 'scorecard')
            ->assertSee('indicative')
            ->assertSee('Indicative weights: volume 40%');
    }

    // ---------------------------------------------------------------- reader detail

    public function test_the_reader_page_shows_their_months_with_the_current_one_marked(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [80, 20], '2026-10-01' => [10, 0]]], options: ['names' => ['1001' => 'ADWOA BOATENG']]);

        $this->actingAs($this->officer())
            ->get(route('commercial.reading.reader', '1001'))
            ->assertOk()
            ->assertSee('ADWOA BOATENG')
            ->assertSee('Sep 2026')
            ->assertSee('20.0%')
            ->assertSee('In progress');
    }

    public function test_a_regional_user_gets_403_for_a_reader_whose_rows_are_all_in_another_region_and_404_for_an_unknown_one(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [80, 20]]]);
        $this->seedReading(['2002' => ['2026-09-01' => [80, 20]]], $this->ashanti);
        $officer = $this->officer();

        $this->actingAs($officer)->get(route('commercial.reading.reader', '1001'))->assertOk();
        $this->actingAs($officer)->get(route('commercial.reading.reader', '2002'))->assertForbidden();
        $this->actingAs($officer)->get(route('commercial.reading.reader', '9999'))->assertNotFound();

        // The system account is not a reader.
        $this->seedReading(['00000' => ['2026-09-01' => [1, 0]]], options: ['status' => ['00000' => 'system_account']]);
        $this->actingAs($officer)->get(route('commercial.reading.reader', '00000'))->assertNotFound();

        Livewire::actingAs($this->superAdmin())->test(ReaderDetail::class, ['staffId' => '2002'])->assertOk();
    }

    // ---------------------------------------------------------------- scoping

    public function test_a_regional_user_sees_only_their_region_while_head_office_sees_the_sum(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [700, 100]]]);                    // Accra West: 800 visits
        $this->seedReading(['2002' => ['2026-09-01' => [1000, 0]]], $this->ashanti);     // Ashanti: 1,000 visits

        $regional = Livewire::actingAs($this->officer())->test(Reading::class)->set('tab', 'readers');
        $regional->assertSee('Reader 1001')->assertDontSee('Reader 2002')->assertDontSee('All regions');

        $headOffice = $this->officer('900010', $this->accraWest, $this->headOffice);

        Livewire::actingAs($headOffice)->test(Reading::class)->set('tab', 'readers')
            ->assertSee('Reader 1001')->assertSee('Reader 2002')->assertSee('All regions')
            ->set('region', (string) $this->ashanti->id)
            ->assertSee('Reader 2002')->assertDontSee('Reader 1001');

        // A regional user cannot widen their view by putting another region in the URL.
        Livewire::actingAs($this->officer())->test(Reading::class)->set('tab', 'readers')->set('region', (string) $this->ashanti->id)
            ->assertDontSee('Reader 2002');
    }

    // ---------------------------------------------------------------- permissions

    public function test_a_user_with_view_reading_but_not_view_reader_performance_cannot_reach_reader_data(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [80, 20]]], options: ['names' => ['1001' => 'ADWOA BOATENG']]);
        $chief = $this->userWithRoles('900040', ['regional_chief_manager']);

        $this->assertTrue($chief->hasPermission('commercial.view_reading'));
        $this->assertFalse($chief->hasPermission('commercial.view_reader_performance'));

        // The trend is theirs...
        $this->actingAs($chief)->get(route('commercial.reading'))->assertOk()->assertSee('Month by month');

        // ...the reader tabs are not offered, and typing them into the URL falls back to the trend.
        $page = $this->actingAs($chief)->get(route('commercial.reading', ['tab' => 'readers']));
        $page->assertOk()->assertSee('Month by month')->assertDontSee('League table')->assertDontSee('ADWOA BOATENG');

        foreach (['readers', 'exceptions', 'scorecard'] as $tab) {
            Livewire::actingAs($chief)->test(Reading::class)->set('tab', $tab)
                ->assertDontSee('ADWOA BOATENG')
                ->assertDontSee('League table')
                ->assertSee('Month by month');
        }

        // And the reader page is forbidden outright.
        $this->actingAs($chief)->get(route('commercial.reading.reader', '1001'))->assertForbidden();
        Livewire::actingAs($chief)->test(ReaderDetail::class, ['staffId' => '1001'])->assertForbidden();
    }

    public function test_a_user_with_neither_reading_permission_cannot_open_the_reading_screens(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [80, 20]]]);
        $dashboardOnly = $this->userWithRoles('900041', []);
        $role = Role::query()->create(['name' => 'commercial_dashboard_only', 'display_name' => 'Dashboard only', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('name', 'commercial.view_dashboard')->value('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $dashboardOnly->roles()->attach($role);
        $dashboardOnly = $dashboardOnly->fresh();

        $this->actingAs($dashboardOnly)->get(route('commercial.reading'))->assertForbidden();
        Livewire::actingAs($dashboardOnly)->test(Reading::class)->assertForbidden();
        $this->actingAs($dashboardOnly)->get(route('commercial.home'))->assertOk();

        $labels = collect(app(ErpNavigation::class)->build($dashboardOnly, 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertNotContains('Reading', $labels);
    }

    public function test_the_sidebar_item_follows_the_permissions(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'commercial')['sidebar'])->pluck('label')->all();

        $this->assertContains('Reading', $labels($this->officer()));
        $this->assertContains('Reading', $labels($this->userWithRoles('900042', ['commercial_manager'])));
        $this->assertContains('Reading', $labels($this->userWithRoles('900043', ['regional_chief_manager'])));
        $this->assertContains('Reading', $labels($this->superAdmin()));
    }

    // ---------------------------------------------------------------- Home

    public function test_home_tiles_reflect_the_latest_complete_month_not_the_one_in_progress(): void
    {
        $this->seedReading([
            '1001' => [
                '2026-08-01' => [400, 100],   // 500 visited, skip 20.0%
                '2026-09-01' => [450, 150],   // 600 visited, skip 25.0%
                '2026-10-01' => [20, 5],      // in progress: 25 visited, must not become the tile
            ],
        ], options: ['strength' => ['2026-08-01' => 1000, '2026-09-01' => 1000, '2026-10-01' => 1000]]);

        Livewire::actingAs($this->officer())->test(Home::class)
            ->assertSee('Sep 2026')
            ->assertSee('latest complete month')
            ->assertSee('600')                      // visited
            ->assertSee('+20.0% on Aug 2026')       // 500 -> 600
            ->assertSee('25.0%')                    // skip rate
            ->assertSee('+5.0 pts on Aug 2026')     // 20.0 -> 25.0
            ->assertSee('60.0%')                    // coverage 600 / 1000
            ->assertSee('Configured target: 10%')
            ->assertSee('Configured target: 90%')
            ->assertSee('Monthly trend')
            ->assertSee('Latest data loaded');
    }

    public function test_home_shows_no_tiles_before_a_month_has_finished_and_no_reading_numbers_without_the_permission(): void
    {
        $this->seedReading(['1001' => ['2026-10-01' => [20, 5]]]);

        Livewire::actingAs($this->officer())->test(Home::class)->assertDontSee('latest complete month')->assertSee('Monthly trend');

        $this->seedReading(['1001' => ['2026-09-01' => [450, 150]]]);

        // An uploader with no view permission sees what is loaded, not the numbers.
        $role = Role::query()->create(['name' => 'commercial_upload_only', 'display_name' => 'Upload only', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('name', 'commercial.upload_reports')->value('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $user = $this->userWithRoles('900044', []);
        $user->roles()->attach($role);

        Livewire::actingAs($user->fresh())->test(Home::class)->assertDontSee('latest complete month')->assertDontSee('Monthly trend')->assertSee('Latest data loaded');
    }

    public function test_home_numbers_are_scoped_to_the_users_region(): void
    {
        $this->seedReading(['1001' => ['2026-09-01' => [450, 150]]]);                      // Accra West: 600
        $this->seedReading(['2002' => ['2026-09-01' => [5000, 0]]], $this->ashanti);       // Ashanti: 5,000

        Livewire::actingAs($this->officer())->test(Home::class)->assertSee('600')->assertDontSee('5,600')->assertDontSee('5,000');
        Livewire::actingAs($this->superAdmin())->test(Home::class)->assertSee('5,600');
    }
}
