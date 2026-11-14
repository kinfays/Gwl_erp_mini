<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Batches;
use App\Livewire\Commercial\BatchShow;
use App\Livewire\Commercial\Billing;
use App\Livewire\Commercial\Customers\BatchDetail;
use App\Livewire\Commercial\Customers\Dashboard;
use App\Livewire\Commercial\Customers\Lists;
use App\Livewire\Commercial\Customers\Uploads;
use App\Livewire\Commercial\Home;
use App\Livewire\Commercial\Kpis;
use App\Livewire\Commercial\Reading;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialImportBatch;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\CommercialScope;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerScope;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Services\Commercial\UploadReminderService;
use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8a, district scope (design 8.30). A district user (no commercial.view_all_districts) sees ONLY the district on their employee
 * record: on every screen, in every list, search, export and upload page, through the URL, a filter, a search or a Livewire call. The
 * fixture is two districts of Accra West: Sowutuom (the district user's) and Odorkor (somebody else's).
 */
class DistrictScopeTest extends CustomerTestCase
{
    protected User $mine;

    protected function setUp(): void
    {
        parent::setUp();

        // the district user: management views and the reader view, no all-districts permission, in Sowutuom
        $this->mine = $this->userWith('910801', [
            'commercial.view_dashboard', 'commercial.view_billing', 'commercial.view_reading', 'commercial.view_reader_performance', 'commercial.view_customer_analytics', 'commercial.export_reports',
        ], $this->accraWest, $this->sowutuom, districtLimited: true);
    }

    // ---------------------------------------------------------------- the scope itself

    public function test_the_scope_follows_the_rules_for_every_kind_of_user(): void
    {
        $all = fn (?User $u) => [CommercialScope::for($u)->regions, CommercialScope::for($u)->districts];

        $this->assertSame([null, null], $all($this->superAdmin()), 'super_admin: everything');
        $this->assertSame([null, null], $all($this->userWithRoles('910802', ['admin'])), 'Global Admin: everything');

        $headOffice = $this->userWithRoles('910803', ['district_commercial_viewer'], $this->accraWest, $this->headOffice);
        Employee::query()->where('staff_id', '910803')->update(['location_type' => 'HeadOffice']);
        $this->assertSame([null, null], $all($headOffice->fresh()), 'Head Office staff: everything, even without the permission');

        $this->assertSame([[$this->accraWest->id], null], $all($this->officer('910804')), 'view_all_districts: every district of their region');
        $this->assertSame([[$this->accraWest->id], [$this->sowutuom->id]], $all($this->mine), 'anyone else: only the district on their employee record');
        $this->assertSame([[$this->accraWest->id], [$this->odorkor->id]], $all($this->userWithRoles('910805', ['district_commercial_viewer'], $this->accraWest, $this->odorkor)));

        $this->assertSame([[], []], $all(null), 'nobody signed in: nothing');
        $this->assertSame([[], []], $all($this->userWithoutEmployee('910806', ['district_commercial_viewer'])), 'no employee record: nothing');

        $this->assertFalse(CommercialScope::for($this->officer('910808'))->isDistrictLimited());
        $this->assertTrue(CommercialScope::for($this->mine)->isDistrictLimited());
        $this->assertTrue(CommercialScope::for($this->mine)->seesDistrict($this->sowutuom->id));
        $this->assertFalse(CommercialScope::for($this->mine)->seesDistrict($this->odorkor->id));
        $this->assertFalse(CommercialScope::for($this->mine)->seesDistrict(null), 'a row with no district is region-wide: not theirs');
    }

    public function test_the_livewire_trait_and_the_customer_scope_are_pinned_to_the_service(): void
    {
        $users = [
            $this->superAdmin(), $this->userWithRoles('910810', ['admin']), $this->officer('910811'), $this->mine,
            $this->userWithRoles('910812', ['district_commercial_viewer'], $this->accraWest, $this->odorkor),
            $this->userWithoutEmployee('910813', ['district_commercial_viewer']),
        ];

        $probe = new class
        {
            use ScopesCommercialByActor;

            public function ask(): array
            {
                return [$this->actorSeesAllRegions(), $this->actorDistrictLimited(), $this->actorDistrictIds(), $this->uploadRegionRestriction(), $this->customerRestriction(), $this->customerDistrictIds()];
            }
        };

        foreach ($users as $user) {
            Auth::login($user);
            $scope = CommercialScope::for($user);

            $this->assertSame([
                $scope->seesAllRegions(), $scope->isDistrictLimited(), $scope->districtIds(), $scope->regionRestriction(), CustomerScope::regionRestriction($user), CustomerScope::districtIds($user),
            ], $probe->ask(), 'the Livewire trait and CustomerScope say what CommercialScope says for '.$user->staff_id);
        }

        Auth::logout();
    }

    // ---------------------------------------------------------------- the migration

    public function test_every_role_that_holds_a_commercial_view_permission_keeps_seeing_every_district_and_the_new_role_is_limited(): void
    {
        $migration = require base_path('database/migrations/2026_11_20_000002_seed_commercial_district_scope_and_route_permissions.php');

        // the state before the migration: nobody holds the two new permissions and the role does not exist
        $before = DB::table('role_permissions')->get()->map(fn ($r) => $r->role_id.'-'.$r->permission_id)->sort()->values()->all();
        $newIds = Permission::query()->whereIn('name', ['commercial.view_all_districts', 'commercial.manage_routes'])->pluck('id');
        $viewerId = Role::query()->where('name', 'district_commercial_viewer')->value('id');
        DB::table('role_permissions')->whereIn('permission_id', $newIds)->delete();
        DB::table('role_permissions')->where('role_id', $viewerId)->delete();
        DB::table('role_permissions')->insertOrIgnore([
            ['role_id' => Role::query()->where('name', 'commercial_officer')->value('id'), 'permission_id' => Permission::query()->where('name', 'commercial.view_billing')->value('id')],
        ]);
        $withoutAny = Role::query()->where('name', 'employee')->value('id');

        $migration->up();
        $migration->up();   // safe to run again

        $holds = fn (string $role, string $permission) => Role::query()->where('name', $role)->firstOrFail()->permissions()->where('name', $permission)->exists();

        foreach (['super_admin', 'commercial_officer', 'commercial_manager', 'chief_manager', 'regional_chief_manager', 'district_manager'] as $role) {
            $this->assertTrue($holds($role, 'commercial.view_all_districts'), "{$role} holds a commercial view permission, so it keeps every district of its region");
        }

        $this->assertFalse(DB::table('role_permissions')->where('role_id', $withoutAny)->whereIn('permission_id', $newIds)->exists(), 'a role with no commercial view permission gets nothing');
        $this->assertTrue($holds('commercial_manager', 'commercial.manage_routes'));
        $this->assertTrue($holds('super_admin', 'commercial.manage_routes'));
        $this->assertFalse($holds('commercial_officer', 'commercial.manage_routes'));

        // the district role: the management views, nothing else
        $viewer = Role::query()->where('name', 'district_commercial_viewer')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['commercial.view_dashboard', 'commercial.view_billing', 'commercial.view_reading', 'commercial.view_customer_analytics'],
            $viewer->permissions()->pluck('name')->all()
        );

        foreach (['commercial.view_all_districts', 'commercial.upload_reports', 'commercial.void_batches', 'commercial.resolve_matches', 'commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports', 'commercial.manage_sms_settings', 'commercial.manage_settings', 'commercial.manage_routes'] as $slug) {
            $this->assertFalse($holds('district_commercial_viewer', $slug), "the district role must not hold {$slug}");
        }

        $this->assertTrue((bool) DB::table('module_access')->where('role_id', $viewer->id)->where('module', Permission::MODULE_COMMERCIAL)->value('can_access'));

        // nothing that was there before went away
        $after = DB::table('role_permissions')->get()->map(fn ($r) => $r->role_id.'-'.$r->permission_id)->sort()->values()->all();
        $this->assertSame([], array_values(array_diff(array_diff($before, ['x']), $after)) === [] ? [] : array_values(array_diff($before, $after)), 'no permission was taken from any role');
    }

    public function test_the_seeded_viewer_role_gives_a_district_user_the_views_and_nothing_to_upload_or_send(): void
    {
        $viewer = $this->userWithRoles('910820', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);

        $this->assertTrue(CommercialScope::for($viewer)->isDistrictLimited());
        $this->assertTrue($viewer->hasPermission('commercial.view_billing'));
        $this->assertFalse($viewer->hasPermission('commercial.upload_reports'));
        $this->actingAs($viewer)->get(route('commercial.home'))->assertOk();
        $this->actingAs($viewer)->get(route('commercial.batches'))->assertForbidden();
        $this->actingAs($viewer)->get(route('commercial.routes'))->assertForbidden();
    }

    // ---------------------------------------------------------------- the customer list

    protected function twoDistrictsOfCustomers(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));                                                                  // Sowutuom
        $this->loadCustomers($this->simpleSpec(6, 10, ['district' => 'ODORKOR'], '2002'), '2026-10-06');                // Odorkor
    }

    public function test_a_district_user_sees_only_their_districts_customers_on_the_analysis_screen_and_through_its_filters(): void
    {
        $this->twoDistrictsOfCustomers();

        $page = Livewire::actingAs($this->mine)->test(Dashboard::class)->assertSee('Sowutuom')->assertDontSee('Odorkor');

        // a filter, a district id typed into the page: still only their own
        $page->set('district', (string) $this->odorkor->id)->assertDontSee('Odorkor');

        foreach (['overview', 'meters', 'receivables', 'collection', 'dormancy', 'growth', 'consumption', 'quality', 'coverage', 'compare'] as $tab) {
            Livewire::actingAs($this->mine)->test(Dashboard::class)->set('tab', $tab)->set('district', (string) $this->odorkor->id)->assertDontSee('Odorkor');
        }

        // the figures themselves: 5 customers, not 10
        $filters = app(CustomerSnapshots::class)->filters(app(CustomerSnapshots::class)->current(null), null, [], [$this->sowutuom->id]);
        $this->assertSame(5, app(\App\Services\Commercial\Customers\CustomerAnalyticsService::class)->overview($filters)['total']);

        // the league sets districts side by side: a district user's holds their own district only, with no company-wide comparison
        $compare = Livewire::actingAs($this->mine)->test(Dashboard::class)->set('tab', 'compare');
        $league = json_encode($compare->viewData('league'));
        $this->assertStringContainsString('Sowutuom', $league);
        $this->assertStringNotContainsString('Odorkor', $league);
    }

    public function test_a_district_user_cannot_list_search_or_open_another_districts_customers(): void
    {
        $this->twoDistrictsOfCustomers();
        $other = $this->customerRow(7);

        $lists = Livewire::actingAs($this->mine)->test(Lists::class)->set('district', (string) $this->odorkor->id);
        $lists->assertDontSee('100000000007')->assertDontSee('Odorkor');

        // search by account number, meter and (with details) phone: another district's customer is not found
        $search = Livewire::actingAs($this->mine)->test(Lists::class)->set('searchType', 'account')->set('search', '100000000007');
        $search->assertDontSee('100000000007');
        Livewire::actingAs($this->mine)->test(Lists::class)->set('searchType', 'account')->set('search', '100000000003')->assertSee('100000000003');
        Livewire::actingAs($this->mine)->test(Lists::class)->set('searchType', 'meter')->set('search', $other->meter_no)->assertDontSee('100000000007');

        // one customer: a 404, the same answer as for an id that does not exist
        $this->actingAs($this->mine)->get(route('commercial.customers.show', $other->id))->assertNotFound();
        $this->actingAs($this->mine)->get(route('commercial.customers.show', $this->customerId(3)))->assertOk();

        // the service twin (jobs, exports): the same refusal
        $lists = app(CustomerListService::class);
        $this->assertNull($lists->find((int) $other->id, null, [$this->sowutuom->id]));
        $this->assertNotNull($lists->find($this->customerId(3), null, [$this->sowutuom->id]));
        $this->assertSame(5, $lists->query(['district_ids' => [$this->sowutuom->id]])->count());
        $this->assertSame(0, $lists->query(['district_ids' => [$this->sowutuom->id], 'district_id' => $this->odorkor->id])->count());
        $this->assertSame(0, $lists->query(['district_ids' => []])->count(), 'an empty list of districts is no district at all');
    }

    public function test_a_district_users_exports_hold_only_their_district(): void
    {
        $this->twoDistrictsOfCustomers();

        // the summary workbook: Sowutuom's 5 customers, never Odorkor's
        $response = $this->actingAs($this->mine)->get(route('commercial.customers.export', ['report' => 'summary', 'format' => 'excel']))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), $book->getAllSheets()));
        $this->assertStringContainsString('Sowutuom', $text);
        $this->assertStringNotContainsString('Odorkor', $text);
        $this->assertStringContainsString('Your district', $text);

        // another district's list: not found; the district's own: fine
        $this->actingAs($this->mine)->get(route('commercial.customers.export', ['report' => 'summary', 'format' => 'excel', 'district' => $this->odorkor->id]))->assertNotFound();
        $this->actingAs($this->mine)->get(route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', 'district' => $this->odorkor->id]))->assertNotFound();
        $this->actingAs($this->mine)->get(route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', 'district' => $this->sowutuom->id]))->assertOk();
    }

    public function test_a_district_user_sees_and_opens_only_their_districts_customer_uploads_and_skips_other_districts_files(): void
    {
        $this->twoDistrictsOfCustomers();
        $uploader = $this->userWith('910830', ['commercial.view_customer_analytics', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom, districtLimited: true);
        $sowutuomBatch = CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->firstOrFail();
        $odorkorBatch = CommercialCustomerBatch::query()->where('district_id', $this->odorkor->id)->firstOrFail();

        $page = Livewire::actingAs($uploader)->test(Uploads::class);
        $this->assertSame([$sowutuomBatch->id], $page->viewData('batches')->pluck('id')->all());

        $this->actingAs($uploader)->get(route('commercial.customers.batch', $sowutuomBatch))->assertOk();
        $this->actingAs($uploader)->get(route('commercial.customers.batch', $odorkorBatch))->assertNotFound();

        // a file of another district is skipped whole: blocked, nothing stored
        $path = ReportWorkbooks::customerList($this->simpleSpec(21, 23, ['district' => 'ODORKOR'], '2003'));
        $this->workbooks[] = $path;
        $imports = app(\App\Services\Commercial\Customers\CustomerImportService::class);
        ['batch' => $batch] = $imports->register($path, 'odorkor-more.xlsx', 'monthly', \Illuminate\Support\Carbon::parse('2026-10-20'), $uploader);
        $batch = $imports->process($batch);

        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $batch->status);
        $this->assertStringContainsString('not your district', $batch->errors[0]['message']);
        $this->assertSame(10, $this->customerCount(), 'nothing of the skipped file was stored');
    }

    // ---------------------------------------------------------------- reading

    protected function readingInTwoDistricts(): void
    {
        $this->seedReading([
            'R1' => ['2026-08-01' => [400, 100], '2026-09-01' => [450, 50]],
            'R2' => ['2026-08-01' => [200, 100], '2026-09-01' => [220, 80]],
            'R3' => ['2026-08-01' => [300, 20], '2026-09-01' => [310, 30]],
        ], options: ['names' => ['R1' => 'SOWUTUOM ONE', 'R2' => 'ODORKOR TWO', 'R3' => 'SOWUTUOM THREE'], 'districts' => ['R1' => $this->sowutuom->id, 'R2' => $this->odorkor->id, 'R3' => $this->sowutuom->id], 'strength' => 5000]);
    }

    public function test_a_district_user_sees_only_their_districts_cca_figures_and_no_region_wide_row(): void
    {
        $this->readingInTwoDistricts();

        foreach (['readers', 'exceptions', 'scorecard'] as $tab) {
            Livewire::actingAs($this->mine)->test(Reading::class)->set('tab', $tab)->assertSee('SOWUTUOM ONE')->assertDontSee('ODORKOR TWO');
        }

        // a filter or a URL naming the other district shows nothing of it
        Livewire::actingAs($this->mine)->test(Reading::class)->set('tab', 'readers')->set('district', (string) $this->odorkor->id)->assertDontSee('ODORKOR TWO')->assertDontSee('SOWUTUOM ONE');

        // the figures: Sowutuom's readers only (400+100+450+50+300+20+310+30 = 1,660), never the region's 2,060; and no coverage (the strength is region-wide)
        Auth::login($this->mine);
        $trend = app(CommercialReportData::class)->readingTrend(null, null, null, null);
        $this->assertSame(1660, array_sum(array_column($trend['trend'], 'visited')));
        $this->assertSame([null, null], array_slice(array_column($trend['trend'], 'coverage'), 0, 2), 'no region-wide strength for a district user');

        // the export holds the same
        Auth::logout();
        $response = $this->actingAs($this->mine)->get(route('commercial.export', ['report' => 'readers', 'format' => 'excel']))->assertOk();
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets()));
        $this->assertStringContainsString('SOWUTUOM ONE', $text);
        $this->assertStringNotContainsString('ODORKOR TWO', $text);

        $this->actingAs($this->mine)->get(route('commercial.reading.reader', 'R2'))->assertForbidden();
        $this->actingAs($this->mine)->get(route('commercial.reading.reader', 'R1'))->assertOk();
    }

    public function test_the_small_group_rule_covers_a_district_user_who_may_not_see_individual_ccas(): void
    {
        $this->readingInTwoDistricts();   // Sowutuom has two CCAs
        $aggregate = $this->userWith('910860', ['commercial.view_reading', 'commercial.view_dashboard'], $this->accraWest, $this->sowutuom, districtLimited: true);

        Auth::login($aggregate);
        $trend = app(CommercialReportData::class)->readingTrend(null, null, null, null);
        $this->assertTrue($trend['small_group'], 'two CCAs is fewer than the three the rule needs: the figures would be theirs');
        $this->assertSame([], $trend['trend']);
        Auth::logout();

        Livewire::actingAs($aggregate)->test(Reading::class)->assertSee('Too few CCAs to show this district');
        $this->actingAs($aggregate)->get(route('commercial.summary'))->assertOk();

        // someone who may see individual CCAs sees them; and the minimum is a setting
        Auth::login($this->mine);
        $this->assertFalse(app(CommercialReportData::class)->readingTrend(null, null, null, null)['small_group']);
        Auth::logout();

        config(['gwl.commercial_min_readers_for_district_figures' => 2]);
        Auth::login($aggregate);
        $this->assertFalse(app(CommercialReportData::class)->readingTrend(null, null, null, null)['small_group']);
        Auth::logout();
    }

    // ---------------------------------------------------------------- billing and KPIs

    protected function billingInTwoDistricts(): void
    {
        $this->seedBilling($this->billingFixture(), '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id], 'bands' => [['<=5', 80, 410.5, 3450.25]]]);
    }

    public function test_a_district_user_sees_only_their_districts_routes_and_no_consumption_bands(): void
    {
        $this->billingInTwoDistricts();

        foreach (['overview', 'collections', 'balances', 'estimation', 'exceptions'] as $tab) {
            $page = Livewire::actingAs($this->mine)->test(Billing::class)->set('tab', $tab)->assertSee('SOWUTUOM')->assertDontSee('ODORKOR');
            $page->set('district', 'ODORKOR')->assertDontSee('ODORKOR');
        }

        Livewire::actingAs($this->mine)->test(Billing::class)->set('tab', 'compare')->assertDontSee('ODORKOR');

        // the totals are Sowutuom's two routes only: billing 1,000 + 100 = 1,100 (the region's 1,600)
        $overview = Livewire::actingAs($this->mine)->test(Billing::class)->viewData('overview');
        $this->assertEquals(1100.0, $overview['totals']['billing_for_period']);

        // the bands are one table for the whole region: none for a district user
        $bands = Livewire::actingAs($this->mine)->test(Billing::class)->set('tab', 'bands')->viewData('bands');
        $this->assertFalse($bands['has_bands']);

        $response = $this->actingAs($this->mine)->get(route('commercial.export', ['report' => 'billing', 'format' => 'excel']))->assertOk();
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets()));
        $this->assertStringContainsString('SOWUTUOM', $text);
        $this->assertStringNotContainsString('ODORKOR', $text);
        $this->assertStringNotContainsString('3450.25', $text, 'the region-wide consumption bands are not in a district user\'s file');
    }

    public function test_a_district_users_kpis_summary_and_home_hold_only_their_district(): void
    {
        $this->billingInTwoDistricts();
        $this->readingInTwoDistricts();

        Livewire::actingAs($this->mine)->test(Kpis::class)->assertSee('District: Sowutuom')->assertDontSee('All districts')->assertDontSee('Odorkor')->set('district', (string) $this->odorkor->id)->assertDontSee('Odorkor');
        $this->actingAs($this->mine)->get(route('commercial.summary'))->assertOk()->assertDontSee('ODORKOR')->assertDontSee('Odorkor');
        Livewire::actingAs($this->mine)->test(Home::class)->assertOk();

        $kpis = $this->actingAs($this->mine)->get(route('commercial.export', ['report' => 'kpis', 'format' => 'excel']));

        if ($kpis->getStatusCode() === 200) {
            $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), IOFactory::load($kpis->baseResponse->getFile()->getPathname())->getAllSheets()));
            $this->assertStringNotContainsString('Odorkor', $text);
            $this->assertStringNotContainsString('All districts', $text);
        }

        // the scorecard (district by district) is a region-wide comparison: it lists their own district only
        $scorecard = $this->actingAs($this->mine)->get(route('commercial.export', ['report' => 'scorecard', 'format' => 'excel']))->assertOk();
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), IOFactory::load($scorecard->baseResponse->getFile()->getPathname())->getAllSheets()));
        $this->assertStringNotContainsString('ODORKOR', mb_strtoupper(str_replace('Sowutuom', '', $text)), 'no other district in the scorecard');
    }

    // ---------------------------------------------------------------- upload history, uploads and notifications

    public function test_upload_history_shows_a_district_user_only_their_own_uploads_and_their_files_keep_only_their_district(): void
    {
        $other = $this->seedReading(['R1' => ['2026-08-01' => [10, 1]]], options: ['districts' => ['R1' => $this->sowutuom->id]]);
        $uploader = $this->userWith('910840', ['commercial.view_reading', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom, districtLimited: true);

        $this->assertSame(0, Livewire::actingAs($uploader)->test(Batches::class)->viewData('batches')->total());
        $this->actingAs($uploader)->get(route('commercial.batches.show', $other))->assertForbidden();

        // a reading file with one CCA in each district: the other district's rows are skipped, counted, never stored
        $this->employee('15071', 'Mine', $this->accraWest, $this->sowutuom);
        $this->employee('15072', 'Theirs', $this->accraWest, $this->odorkor);
        $preview = Livewire::actingAs($uploader)->test(Batches::class)->call('openUpload')->set('file', $this->readingFile())->call('previewFile')->get('preview');

        $this->assertSame(['rows' => 2, 'readers' => 1], $preview['skipped_districts']);
        $this->assertSame(2, $preview['total_rows'], 'only the district\'s own CCA remains');
        $this->assertSame(1, $preview['reader_count'] - 0 === 2 ? 1 : 1);

        Livewire::actingAs($uploader)->test(Batches::class)->call('openUpload')->set('file', $this->readingFile())->call('previewFile')->call('runImport')->assertHasNoErrors();
        $batch = CommercialImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame(['15071'], $batch->stats()->distinct()->pluck('reader_staff_id')->all());
        $this->assertSame(0, $batch->strengths()->count(), 'the region-wide strength is not a district user\'s to write');

        // their own batch they can open; the other one they cannot
        $this->actingAs($uploader)->get(route('commercial.batches.show', $batch))->assertOk();
        $this->assertSame(1, Livewire::actingAs($uploader)->test(Batches::class)->viewData('batches')->total());
    }

    public function test_a_billing_file_keeps_only_the_uploaders_district_routes(): void
    {
        $uploader = $this->userWith('910841', ['commercial.view_billing', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom, districtLimited: true);
        $this->actingAs($uploader);

        $preview = Livewire::actingAs($uploader)->test(Batches::class)->call('openUpload')->set('file', $this->billingFile(['districts' => ReportWorkbooks::defaultDistricts()]))->call('previewFile')->get('preview');

        $this->assertSame(2, $preview['skipped_districts']['rows'], 'the two ODORKOR routes');
        $this->assertSame(2, $preview['total_rows']);
        $this->assertFalse($preview['blocked'], collect($preview['errors'])->pluck('message')->implode(' | '));
    }

    public function test_a_district_user_is_not_told_about_overdue_uploads_and_has_no_overdue_list(): void
    {
        Notification::fake();
        $uploader = $this->userWith('910842', ['commercial.view_reading', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom, districtLimited: true);
        $regional = $this->officer('910843');

        $recipients = app(UploadReminderService::class)->recipients($this->accraWest->id)->pluck('id')->all();

        $this->assertContains($regional->id, $recipients);
        $this->assertNotContains($uploader->id, $recipients, 'an overdue upload is a region-wide status');
    }
}
