<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BillingDetails;
use App\Models\AuditLog;
use App\Models\CommercialBillingDetailRollup;
use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CommercialReportData;
use App\Support\ErpNavigation;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8d: the Billing details screen (B1 to B13), its district scope, the CCA beside each route and the CCA filter, the filters, and the
 * Excel / PDF exports (scoped, audited, row-capped). The data is THE SAMPLE with the test districts (Sowutuom, Odorkor and Amasaman); its
 * arithmetic is in BillingDetailsSampleFixtureTest. Invented data only; "today" is 15 Nov 2026.
 */
class BillingDetailsScreensTest extends CommercialTestCase
{
    use BuildsBillingDetails;
    use BuildsRouteReading;

    protected CommercialImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_min_readers_for_district_figures' => 1]);
        $this->setUpBillingDetails();
        $this->tolerant();
        $this->batch = $this->importBilling();
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $this->batch->status, (string) $this->batch->error_message);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A user holding exactly the given permissions; $allDistricts false = only the district on their employee record (Sowutuom). */
    protected function userWith(string $staffId, array $permissions, bool $allDistricts = true): User
    {
        $role = Role::query()->create(['name' => 'custom_'.$staffId, 'display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', [...$permissions, ...($allDistricts ? ['commercial.view_all_districts'] : [])])->pluck('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, [], $this->accraWest, $this->sowutuom);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    protected function report(?int $district = null, ?string $category = null, ?string $cca = null, ?int $route = null, ?string $from = null, ?string $to = null): array
    {
        return app(CommercialReportData::class)->billingDetails(null, $district, $category, $cca, $route, $from, $to);
    }

    protected function export(User $user, string $format = 'excel', array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('commercial.export', ['report' => 'billing-details', 'format' => $format, ...$query]));
    }

    /** @return array<string, list<list<mixed>>> */
    protected function sheets(TestResponse $response): array
    {
        $out = [];

        foreach (IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets() as $sheet) {
            $out[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
        }

        return $out;
    }

    // ---------------------------------------------------------------- the screen

    public function test_a_permitted_user_sees_the_figures_on_every_tab(): void
    {
        $this->actingAs($this->officer());

        Livewire::test(BillingDetails::class)->assertOk()->assertSee('Billing details')->assertSee('80.0%')->assertSee('0.9%')->assertSee('B1 Bill status by district')->assertSee('Sowutuom')->assertSee('Odorkor')->assertSee('SOWUTUOM 4101')
            ->assertSee('1 Sep to 21 Sep 2026')->assertSee('21 of 21 days covered');
        Livewire::test(BillingDetails::class)->set('tab', 'estimates')->assertSee('B2 Estimation cause by district')->assertSee('Working meter not read')->assertSee('B3 Unbilled customers by account status')->assertSee('100000000001')->assertSee('Billing paused (shown apart)');
        Livewire::test(BillingDetails::class)->set('tab', 'consumption')->assertSee('5,705.0')->assertSee('B4 Consumption bands')->assertSee('B5 Charge breakdown by district')->assertSee('100000000012');
        Livewire::test(BillingDetails::class)->set('tab', 'money')->assertSee('B6 Collections by district')->assertSee('B7 Receivables by district')->assertSee('557.20')->assertSee('17.1%')->assertSee('B8 The largest adjustments')->assertSee('B9 Billed but not paid by route');
        Livewire::test(BillingDetails::class)->set('tab', 'checks')->assertSee('B10 Tariff recompute check by district')->assertSee('B11 Read recency at billing by district')->assertSee('B12 Billing integrity')->assertSee('ODORKOR 4202')->assertSee('100000000011');
        Livewire::test(BillingDetails::class)->set('tab', 'trend')->assertSee('A trend needs at least two uploads');
    }

    public function test_the_screen_and_its_route_need_view_billing_and_the_sidebar_follows(): void
    {
        $reader = $this->userWith('900981', ['commercial.view_reading']);
        $this->actingAs($reader)->get(route('commercial.billing.details'))->assertForbidden();
        Livewire::actingAs($reader)->test(BillingDetails::class)->assertForbidden();
        $this->actingAs($this->officer())->get(route('commercial.billing.details'))->assertOk()->assertSee('Billing details');

        $labels = fn ($user) => collect(app(ErpNavigation::class)->build($user, 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertContains('Billing details', $labels($this->officer()));
        $this->assertNotContains('Billing details', $labels($reader));
    }

    public function test_with_no_upload_the_screen_explains_how_to_load_one(): void
    {
        CommercialImportBatch::query()->update(['status' => CommercialImportBatch::STATUS_VOIDED]);
        $this->actingAs($this->officer());

        Livewire::test(BillingDetails::class)->assertSee('No detailed billing report has been loaded yet.')->assertSee('rptBillingDetails_ExP');
        $this->assertFalse($this->report()['available']);
    }

    public function test_the_filters_narrow_every_figure_and_a_value_that_is_not_theirs_is_ignored(): void
    {
        $this->actingAs($this->officer());

        $this->assertSame(244, $this->report($this->sowutuom->id)['analysis']['totals']['customers']);
        $this->assertSame(180, $this->report($this->odorkor->id)['analysis']['totals']['customers']);
        $this->assertSame(193, $this->report(null, '612')['analysis']['totals']['customers']);
        $this->assertSame(385, $this->report(null, '611')['analysis']['totals']['customers']);
        // Odorkor's 180 customers (n = 241 to 420): every third is category 612 (n = 243 ... 420 = 60), the rest 611
        $this->assertSame(60, $this->report($this->odorkor->id, '612')['analysis']['totals']['customers']);
        $this->assertSame(120, $this->report($this->odorkor->id, '611')['analysis']['totals']['customers']);

        $route = (int) CommercialBillingDetailRollup::query()->where('route_label_raw', 'ODORKOR 4201')->value('route_id');
        $this->assertSame(30, $this->report(null, null, null, $route)['analysis']['totals']['customers']);

        // a district of another region, a category nobody has, a route that is not in the uploads: no filter at all
        $other = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
        $this->assertNull($this->report($other->id)['district_id']);
        $this->assertSame(578, $this->report($other->id, 'ZZZ', 'nobody', 999999)['analysis']['totals']['customers']);

        // the screen carries them in the URL
        Livewire::test(BillingDetails::class)->set('district', (string) $this->odorkor->id)->assertSee('180')->set('category', '612')->assertSet('category', '612');
        Livewire::test(BillingDetails::class)->set('district', (string) $this->sowutuom->id)->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-30')->assertSee('Clear filters')->call('clearFilters')->assertSet('district', '')->assertSet('dateFrom', '');
        Livewire::test(BillingDetails::class)->set('preset', '2026-09')->assertSet('dateFrom', '2026-09-01')->assertSet('dateTo', '2026-09-30');
    }

    // ---------------------------------------------------------------- the CCA of a route

    protected function seedOwners(): void
    {
        // the newest reading report by route names the owner of each route; strength / counts are arbitrary (the owner has the visits)
        $this->seedRouteReading([
            ['80001', 'AMA TEST', '4101', $this->sowutuom, 100, 70, 10, 80],
            ['80001', 'AMA TEST', '4102', $this->sowutuom, 100, 70, 10, 80],
            ['80002', 'KWAME TEST', '4201', $this->odorkor, 100, 70, 10, 80],
        ]);
    }

    public function test_the_cca_is_shown_beside_every_route_and_the_cca_filter_narrows_to_the_routes_they_own(): void
    {
        $this->seedOwners();
        $this->actingAs($this->officer());
        $r = $this->report();

        $this->assertTrue($r['named']);
        $this->assertSame(['80001' => '80001 AMA TEST', '80002' => '80002 KWAME TEST'], $r['ccas']);
        $routes = collect($r['analysis']['routes'])->keyBy('label');
        $this->assertSame('80001', $routes['SOWUTUOM 4101']['cca_staff_id']);
        $this->assertSame('KWAME TEST', $routes['ODORKOR 4201']['cca_name']);
        $this->assertNull($routes['ODORKOR 4202']['cca_staff_id'], 'a route no CCA owns has none');
        $this->assertSame('Oct 2026', $r['cca_source']['label'], 'the reading upload the CCAs come from, named so nobody mistakes its period for the bill\'s');

        Livewire::test(BillingDetails::class)->assertSee('80001 AMA TEST')->assertSee('The CCA beside each route is the owner of that route');

        // the filter: B1 to B11 narrow to the two routes of CCA 80001 (60 customers, all of 4101 and 4102)
        $mine = $this->report(null, null, '80001');
        $this->assertSame('80001', $mine['cca']);
        $this->assertSame(60, $mine['analysis']['totals']['customers']);
        $this->assertSame(['SOWUTUOM 4101', 'SOWUTUOM 4102'], array_column($mine['analysis']['routes'], 'label'));
        $this->assertSame(4, $mine['analysis']['totals']['unbilled'], 'the special customers all sit on route 4101');
        // the lists narrow too, and the distribution of consumption bands (a district and category figure) cannot, and says so
        $this->assertSame(['100000000001', '100000000004'], array_column($mine['lists']['active_unbilled']['rows'], 'account'));
        $this->assertNotNull($mine['analysis']['band_note']);
        $this->assertFalse($mine['concentration']['available']);
    }

    public function test_without_the_cca_performance_permission_no_cca_is_named_and_there_is_no_cca_filter(): void
    {
        $this->seedOwners();
        $viewer = $this->userWith('900982', ['commercial.view_billing', 'commercial.export_reports']);
        $this->assertFalse($viewer->hasPermission('commercial.view_reader_performance'));
        $this->actingAs($viewer);

        $r = $this->report(null, null, '80001');
        $this->assertFalse($r['named']);
        $this->assertSame([], $r['ccas']);
        $this->assertNull($r['cca']);
        $this->assertSame(578, $r['analysis']['totals']['customers'], 'the CCA filter is ignored: it would name whose routes they are');
        $this->assertSame([null], array_unique(array_column($r['analysis']['routes'], 'cca_staff_id')));
        $this->assertSame([null], array_unique(array_column($r['lists']['top_debtor']['rows'], 'cca')));

        Livewire::test(BillingDetails::class)->assertDontSee('AMA TEST')->assertDontSee('80001')->assertSee('No CCA is named on this page');
        $excel = $this->sheets($this->export($viewer, 'excel', ['section' => 'overview']));
        $this->assertStringNotContainsString('AMA TEST', json_encode($excel));
    }

    // ---------------------------------------------------------------- district scope

    public function test_a_district_user_sees_only_their_district_and_no_region_wide_row(): void
    {
        $this->seedOwners();
        $viewer = $this->userWithRoles('900983', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);
        $this->actingAs($viewer);
        $r = $this->report();

        $this->assertTrue($r['district_limited']);
        $this->assertSame([$this->sowutuom->id => 'Sowutuom'], $r['districts']);
        $this->assertSame(['Sowutuom'], array_column($r['analysis']['districts'], 'name'), 'no other district and no "district not known yet" row');
        $this->assertSame(244, $r['analysis']['totals']['customers']);
        $this->assertSame(13565.2, $r['analysis']['totals']['month_total']);
        $this->assertSame(0, count(array_filter($r['analysis']['routes'], fn ($x) => $x['district_id'] !== $this->sowutuom->id)));
        // the district viewer holds the CCA permission since 8d: the CCAs of THEIR routes by name, and no other district's
        $this->assertTrue($r['named']);
        $this->assertSame(['80001' => '80001 AMA TEST'], $r['ccas']);

        // every account on a list is theirs (n = 1 to 240 and 571 to 574)
        foreach ($r['lists'] as $list) {
            foreach ($list['rows'] as $row) {
                $this->assertContains((int) substr($row['account'], 3), [...range(1, 240), ...range(571, 574)], 'an account of another district reached a district user: '.$row['account']);
                $this->assertSame('Sowutuom', $row['district']);
            }
        }

        // a district typed into the URL that is not theirs is ignored; the share held by the top 10% is for their one district
        $this->assertNull($this->report($this->odorkor->id)['district_id']);
        $this->assertSame(244, $this->report($this->odorkor->id)['analysis']['totals']['customers']);
        $this->assertTrue($r['concentration']['available']);
        $this->assertSame(50, $r['concentration']['owing']);

        Livewire::test(BillingDetails::class)->assertSee('Sowutuom')->assertDontSee('Odorkor')->assertDontSee('ODORKOR 4201')->assertDontSee('Amasaman');

        // the trend and the integrity table are theirs too: no region-wide route count
        $this->assertSame([244], array_column($r['trend'], 'customers'));
        $this->assertNull($r['integrity']['batches'][0]['routes_checked']);
        $this->assertNull($r['integrity']['batches'][0]['duplicates']);
    }

    public function test_a_viewer_of_another_region_sees_nothing_of_it(): void
    {
        $kumasi = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
        $ashanti = $this->userWithRoles('900984', ['commercial_officer'], $this->ashanti, $kumasi);
        $this->actingAs($ashanti);

        $this->assertFalse($this->report()['available']);
        Livewire::test(BillingDetails::class)->assertSee('No detailed billing report has been loaded yet.');
        $this->get(route('commercial.batches.show', $this->batch))->assertForbidden();
    }

    // ---------------------------------------------------------------- the exports

    public function test_the_excel_export_is_the_screens_data_scoped_audited_and_one_section_at_a_time(): void
    {
        $officer = $this->officer();
        $response = $this->export($officer, 'excel', ['section' => 'checks']);
        $response->assertOk();
        $sheets = $this->sheets($response);

        $this->assertArrayHasKey('Notes', $sheets);
        $this->assertArrayHasKey('B10 Tariff check by district', $sheets);
        $this->assertArrayHasKey('B12 Integrity by upload', $sheets);
        $this->assertArrayNotHasKey('B1 Status by district', $sheets, 'the section is the checks only');
        $tariff = collect($sheets['B10 Tariff check by district'])->skip(1)->keyBy(0);
        $this->assertEquals(1, $tariff['Sowutuom'][2], 'one mismatch, in the first district');
        $this->assertEquals(572, collect($sheets['B10 Tariff check by district'])->skip(1)->sum(1), '572 bills tested over the districts');
        $this->assertStringContainsString('1 Sep to 21 Sep 2026', json_encode($sheets['Notes']));

        // the whole thing in one file
        $all = $this->sheets($this->export($officer, 'excel'));
        $this->assertArrayHasKey('B1 Status by district', $all);
        $this->assertArrayHasKey('B13 Trend by upload', $all);

        $audit = AuditLog::query()->where('action', 'commercial.export_billing_details_excel')->orderBy('id')->firstOrFail();   // the first download: the checks section
        $this->assertSame('Detailed billing', $audit->metadata['report']);
        $this->assertSame('excel', $audit->metadata['format']);
        $this->assertIsInt($audit->metadata['rows']);
        $this->assertSame('checks', $audit->metadata['filters']['section']);
        $this->assertStringNotContainsString('Sample Customer', json_encode($audit->metadata));
        $this->assertStringNotContainsString('1000000000', json_encode($audit->metadata), 'no account number in an audit row');
    }

    public function test_the_pdf_export_and_the_permissions_and_the_caps(): void
    {
        $officer = $this->officer();
        $pdf = $this->export($officer, 'pdf', ['section' => 'overview']);
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        // each export needs export_reports AND view_billing
        $noExport = $this->userWith('900985', ['commercial.view_billing']);
        $this->export($noExport)->assertForbidden();
        $noBilling = $this->userWith('900986', ['commercial.export_reports', 'commercial.view_reading']);
        $this->export($noBilling)->assertForbidden();

        // the row caps: Excel and PDF each have their own
        config(['gwl.commercial_export_max_rows' => 30]);
        $this->actingAs($officer)->from(route('commercial.billing.details'))->get(route('commercial.export', ['report' => 'billing-details', 'format' => 'excel', 'section' => 'overview']))
            ->assertRedirect(route('commercial.billing.details'))->assertSessionHas('error');
        config(['gwl.commercial_export_max_rows' => 5000, 'gwl.commercial_export_pdf_max_rows' => 20]);
        $this->actingAs($officer)->from(route('commercial.billing.details'))->get(route('commercial.export', ['report' => 'billing-details', 'format' => 'pdf', 'section' => 'overview']))
            ->assertRedirect(route('commercial.billing.details'))->assertSessionHas('error');
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_billing_details_pdf')->count(), 'only the download that was made is audited');
    }

    public function test_a_list_is_whole_in_the_report_and_the_excel_file_and_fifty_on_the_screen(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer);

        $debtors = $this->report()['lists']['top_debtor'];
        $this->assertSame(117, $debtors['total']);
        $this->assertCount(117, $debtors['rows'], 'the report holds every account the upload kept');

        Livewire::test(BillingDetails::class)->set('tab', 'money')->assertSee('50 of 117 accounts are shown')->assertSee('The Excel and PDF files hold up to 200');

        $sheet = $this->sheets($this->export($officer, 'excel', ['section' => 'money']))['B7 Top debtors'];
        $this->assertCount(118, $sheet, 'the header and all 117 accounts (under the cap of 200)');
        $this->assertSame('100000000017', $sheet[1][0]);
    }

    public function test_a_district_users_export_holds_only_their_district(): void
    {
        $viewer = $this->userWith('900987', ['commercial.view_billing', 'commercial.export_reports'], allDistricts: false);
        $sheets = $this->sheets($this->export($viewer, 'excel', ['section' => 'overview']));

        $this->assertStringContainsString('Sowutuom', json_encode($sheets['B1 Status by district']));
        $this->assertStringNotContainsString('Odorkor', json_encode($sheets));
        $this->assertStringNotContainsString('Amasaman', json_encode($sheets));
        $this->assertStringNotContainsString('District not known yet', json_encode($sheets));
        $this->assertStringContainsString('Your district', json_encode($sheets['Notes']));
        // and asking for another district in the link changes nothing
        $asked = $this->sheets($this->export($viewer, 'excel', ['section' => 'overview', 'district' => $this->odorkor->id]));
        $this->assertStringNotContainsString('Odorkor', json_encode($asked));
    }
}
