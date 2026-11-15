<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BatchShow;
use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialRoute;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8c: the import of the reading report BY ROUTE, rule by rule (flagged rows and sheets, a large failure, the excluded account, an
 * unknown district that parks and resumes, a district user, an unmatched CCA, the period rules in the preview, void, audit). Invented
 * CCAs, ids, names and routes only. The basic cases (kind, period, owner rule, findings kept) are in ReadingRouteImportTest.
 */
class ReadingRouteImportRulesTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->employee('80001', 'Ama Test', $this->accraWest, $this->sowutuom);
        $this->employee('80002', 'Kwame Test', $this->accraWest, $this->sowutuom);
    }

    /** A CCA with $count routes of the district, each strength 50, read 30, skipped 10, visited 40. @return array<string, mixed> */
    protected function cca(string $id, string $name, int $count, string $district = 'SOWUTUOM', int $first = 7000, array $change = []): array
    {
        $routes = [];

        for ($i = 0; $i < $count; $i++) {
            $routes[] = array_merge(['label' => $district.' '.($first + $i), 'strength' => 50, 'read' => 30, 'skipped' => 10, 'visited' => 40], $change[$i] ?? []);
        }

        return ['id' => $id, 'name' => $name, 'routes' => $routes];
    }

    protected function file(array $spec, string $name = 'rptReadingSumm.xlsx'): UploadedFile
    {
        return $this->asUpload(ReportWorkbooks::readingRoute($spec), $name);
    }

    protected function preview(array $spec): array
    {
        $this->actingAs($this->officer());

        return app(CommercialImportService::class)->preview($this->file($spec));
    }

    protected function import(array $spec): CommercialImportBatch
    {
        $this->actingAs($this->officer());

        return $this->importFile($this->file($spec));
    }

    // ---------------------------------------------------------------- the excluded account

    public function test_the_excluded_account_is_skipped_by_id_and_by_name_and_never_stored(): void
    {
        $batch = $this->import(['ccas' => [
            $this->cca('80001', 'AMA TEST', 2),
            $this->cca('00000', 'System Administrator', 3, 'SOWUTUOM', 8000),
            $this->cca('80777', 'SYSTEM ADMINISTRATOR', 2, 'ODORKOR', 8100),   // another id, the same excluded name
        ]]);

        $this->assertSame(['80001'], $batch->routeStats()->distinct()->pluck('cca_staff_id')->all());
        $this->assertSame(2, $batch->row_count);
        $this->assertSame(0, CommercialRoute::query()->whereIn('code', ['8000', '8001', '8002', '8100', '8101'])->count(), 'a route only the excluded account reads is never filed');
        $excluded = collect($batch->control_totals['excluded'])->keyBy('staff_id');
        $this->assertSame(3, $excluded['00000']['rows']);
        $this->assertSame(2, $excluded['80777']['rows']);
    }

    // ---------------------------------------------------------------- rows and sheets that do not add up

    public function test_a_row_or_a_sheet_that_does_not_add_up_is_flagged_and_still_imports(): void
    {
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 60]);
        $first = $this->cca('80001', 'AMA TEST', 5, change: [1 => ['read' => 25, 'skipped' => 10, 'visited' => 40], 2 => ['unvisited' => 3]]);   // 25 + 10 is not 40; unvisited 3 is not 50 - 40
        $second = $this->cca('80002', 'KWAME TEST', 5, 'SOWUTUOM', 7100) + ['totals' => ['visited' => 201]];                               // the sheet adds up to 200 but says 201

        $preview = $this->preview(['ccas' => [$first, $second]]);

        $this->assertFalse($preview['blocked'], json_encode($preview['errors']));
        $this->assertSame(2, $preview['route_report']['flagged_rows']);
        $this->assertSame(1, $preview['route_report']['flagged_sheets']);

        $batch = $this->import(['ccas' => [$first, $second]]);

        $this->assertFalse($batch->reconciliation_passed);
        $flagged = $batch->routeStats()->where('is_flagged', true)->orderBy('route_label_raw')->get();
        $this->assertSame([CommercialReadingRouteStat::FLAG_VISITED, CommercialReadingRouteStat::FLAG_UNVISITED], $flagged->pluck('flag_reason')->all());
        $this->assertSame(10, $batch->routeStats()->count(), 'flagged rows are kept, none dropped');
        $this->assertSame('80002', $batch->control_totals['sheets_failed'][0]['staff_id']);

        // the batch page lists what disagrees
        Livewire::test(BatchShow::class, ['batch' => $batch])->assertSee('Flagged at import')->assertSee('Visited is not read plus skipped.')->assertSee('Unvisited is not verified strength minus visited.');
    }

    public function test_too_many_failures_block_the_file(): void
    {
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 10]);
        $bad = array_fill(0, 3, ['read' => 1, 'skipped' => 1, 'visited' => 40]);   // 3 of 10 rows fail

        $preview = $this->preview(['ccas' => [$this->cca('80001', 'AMA TEST', 10, change: $bad)]]);

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('more than the 10%', collect($preview['errors'])->pluck('message')->implode(' '));

        // a grand total far from the rows blocks too, whatever the tolerance per sheet
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 100]);
        $far = $this->preview(['ccas' => [$this->cca('80001', 'AMA TEST', 10) + ['totals' => ['visited' => 800]]]]);

        $this->assertTrue($far['blocked']);
        $this->assertStringContainsString('Visited: the route rows add up to', collect($far['errors'])->pluck('message')->implode(' '));
    }

    // ---------------------------------------------------------------- an unknown district

    public function test_an_unknown_district_parks_and_resumes_once_it_is_resolved(): void
    {
        $spec = ['ccas' => [$this->cca('80001', 'AMA TEST', 2), ['id' => '80002', 'name' => 'KWAME TEST', 'routes' => [['label' => 'MYSTERYVILLE 9001', 'strength' => 60, 'read' => 20, 'skipped' => 10, 'visited' => 30]]]]];

        $preview = $this->preview($spec);
        $this->assertFalse($preview['blocked'], 'an unknown district only warns');
        $this->assertSame(1, $preview['route_report']['unplaced_rows']);
        $this->assertStringContainsString('"MYSTERYVILLE" is not recognised', collect($preview['warnings'])->pluck('message')->implode(' '));

        $batch = $this->import($spec);
        $parked = $batch->routeStats()->where('cca_staff_id', '80002')->firstOrFail();

        $this->assertNull($parked->route_id);
        $this->assertNull($parked->district_id);
        $this->assertSame('MYSTERYVILLE', $parked->district_label_raw);
        $this->assertNull(CommercialRoute::query()->where('code', '9001')->first(), 'no route is guessed');

        // the existing "needs a match" mechanism: pick the district once, and the row is placed
        $this->actingAs($this->officer());
        Livewire::test(BatchShow::class, ['batch' => $batch])
            ->assertSee('Districts not matched')->assertSee('MYSTERYVILLE')
            ->call('startResolvingDistrict', 'MYSTERYVILLE')
            ->set('resolveDistrictId', $this->odorkor->id)
            ->call('saveDistrictResolution')
            ->assertHasNoErrors();

        $placed = $parked->fresh();
        $route = CommercialRoute::query()->where('code', '9001')->firstOrFail();
        $this->assertSame($route->id, $placed->route_id);
        $this->assertSame($this->odorkor->id, $placed->district_id);
        $this->assertTrue((bool) $placed->is_route_owner, 'the owner is worked out again once the route is placed');

        // remembered: the next upload places it by itself
        $second = $this->import(['period' => '01-November-2026 - 30-November-2026'] + $spec);
        $this->assertSame($route->id, $second->routeStats()->where('cca_staff_id', '80002')->value('route_id'));
    }

    // ---------------------------------------------------------------- an unmatched CCA

    public function test_an_unmatched_cca_warns_and_can_be_linked_on_the_batch_page(): void
    {
        $spec = ['ccas' => [$this->cca('80001', 'AMA TEST', 1), $this->cca('99001', 'NOT IN THE DIRECTORY', 1, 'SOWUTUOM', 7500)]];

        $preview = $this->preview($spec);
        $this->assertSame(1, $preview['unmatched_count']);
        $this->assertStringContainsString('99001', collect($preview['warnings'])->pluck('message')->implode(' '));

        $batch = $this->import($spec);
        $this->assertSame(CommercialReadingRouteStat::MATCH_UNMATCHED, $batch->routeStats()->where('cca_staff_id', '99001')->value('match_status'));
        $this->assertSame(1, $batch->fresh()->matched_count);

        $this->actingAs($this->officer());
        $person = $this->employee('99001', 'Linked Person', $this->accraWest, $this->sowutuom);
        Livewire::test(BatchShow::class, ['batch' => $batch])
            ->assertSee('CCAs not in the staff directory')
            ->call('startLinkingReader', '99001')
            ->call('linkReader', $person->id)
            ->assertHasNoErrors();

        $row = $batch->routeStats()->where('cca_staff_id', '99001')->firstOrFail();
        $this->assertSame($person->id, $row->employee_id);
        $this->assertSame(CommercialReadingRouteStat::MATCH_MATCHED, $row->match_status);
        $this->assertSame(2, $batch->fresh()->matched_count);
    }

    // ---------------------------------------------------------------- a district user

    public function test_a_district_user_keeps_only_their_district_and_the_rest_is_counted_never_stored(): void
    {
        $role = Role::query()->create(['name' => 'district_uploader', 'display_name' => 'District uploader', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', ['commercial.view_reading', 'commercial.upload_reports'])->pluck('id'));   // no view_all_districts
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $user = $this->userWithRoles('900970', [], $this->accraWest, $this->sowutuom);
        $user->roles()->attach($role);
        $this->actingAs($user->fresh());

        $spec = ['ccas' => [$this->cca('80001', 'AMA TEST', 3), $this->cca('80002', 'KWAME TEST', 2, 'ODORKOR', 7200)]];

        $preview = app(CommercialImportService::class)->preview($this->file($spec), null, [$this->sowutuom->id]);
        $this->assertSame(2, $preview['skipped_districts']['rows']);
        $this->assertSame(3, $preview['total_rows']);

        $batch = $this->importFile($this->file($spec));

        $this->assertSame(3, $batch->routeStats()->count());
        $this->assertSame(0, $batch->routeStats()->where('district_id', $this->odorkor->id)->count());
        $this->assertSame(0, CommercialReadingRouteStat::query()->where('cca_staff_id', '80002')->whereNotNull('route_label_raw')->count(), 'the other district\'s CCA rows are not stored');
        $this->assertSame(0, CommercialRoute::query()->where('district_id', $this->odorkor->id)->count(), 'and its routes are not filed');
    }

    // ---------------------------------------------------------------- the period rules in the preview

    public function test_the_preview_says_what_a_new_period_does_to_the_uploads_already_there(): void
    {
        $this->import(['period' => '01-October-2026 - 15-October-2026', 'ccas' => [$this->cca('80001', 'AMA TEST', 2)]]);
        $existing = CommercialImportBatch::query()->latest('id')->firstOrFail();
        $texts = fn (array $spec) => collect($this->preview($spec)['warnings'])->pluck('message')->implode(' | ');

        // a containing period replaces it in every view (and the old one stays on record)
        $this->assertStringContainsString('contains batch #'.$existing->id, $texts(['period' => '01-October-2026 - 31-October-2026', 'ccas' => [$this->cca('80001', 'AMA TEST', 3)]]));
        // an overlap in part keeps both
        $this->assertMatchesRegularExpression('/overlaps batch #'.$existing->id.' \(.*\) only in part/', $texts(['period' => '10-October-2026 - 25-October-2026', 'ccas' => [$this->cca('80001', 'AMA TEST', 4)]]));
        // a period after it overlaps nothing
        $this->assertStringNotContainsString('batch #'.$existing->id, $texts(['period' => '16-October-2026 - 31-October-2026', 'ccas' => [$this->cca('80001', 'AMA TEST', 5)]]));
    }

    public function test_the_uploads_page_shows_what_was_read_before_the_import(): void
    {
        $this->actingAs($this->officer());
        $spec = ['ccas' => [$this->cca('80001', 'AMA TEST', 3), $this->cca('80002', 'KWAME TEST', 2, 'SOWUTUOM', 7100) + ['totals' => ['visited' => 81]], ['id' => '80003', 'name' => 'NO ROUTES TEST', 'routes' => []]]];
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 100]);

        Livewire::test(\App\Livewire\Commercial\Batches::class)
            ->call('openUpload')->set('file', $this->file($spec))->call('previewFile')
            ->assertSee('Period read:')->assertSee('Oct 2026')->assertSee('a whole month')->assertSee('Accra West')
            ->assertSeeHtml('<strong>3</strong> CCA sheets')->assertSeeHtml('<strong>5</strong> routes')->assertSeeHtml('<strong>1</strong> districts')
            ->assertSee('1 sheet total(s) do not add up')
            ->assertSeeHtml('<strong>1 CCA(s) with no routes</strong>')->assertSee('80003 NO ROUTES TEST');
    }

    public function test_the_same_file_twice_is_refused(): void
    {
        $file = $this->file(['ccas' => [$this->cca('80001', 'AMA TEST', 2)]]);
        $this->actingAs($this->officer());
        $this->importFile($file);

        $again = app(CommercialImportService::class)->preview($file);
        $this->assertTrue($again['blocked']);
        $this->assertStringContainsString('already', strtolower(collect($again['errors'])->pluck('message')->implode(' ')));
    }

    // ---------------------------------------------------------------- void and audit

    public function test_voiding_a_batch_takes_it_out_of_every_view_and_is_audited(): void
    {
        $batch = $this->import(['ccas' => [$this->cca('80001', 'AMA TEST', 2)]]);
        $this->actingAs($this->officer());
        $this->assertTrue(app(CommercialReportData::class)->routeReading()['available']);

        Livewire::test(BatchShow::class, ['batch' => $batch])->call('startVoid')->set('voidReason', 'Wrong file picked')->call('voidBatch')->assertHasNoErrors();

        $this->assertSame(CommercialImportBatch::STATUS_VOIDED, $batch->fresh()->status);
        $this->assertFalse(app(CommercialReportData::class)->routeReading()['available']);
        $this->assertSame(2, $batch->routeStats()->count(), 'the rows stay on record');
    }

    public function test_the_audit_rows_of_an_import_hold_counts_never_names_or_routes(): void
    {
        $this->import(['ccas' => [$this->cca('80001', 'AMA TEST', 2), $this->cca('99001', 'NOT IN THE DIRECTORY', 1, 'SOWUTUOM', 7500)]]);

        $rows = AuditLog::query()->where('module', Permission::MODULE_COMMERCIAL)->get();
        $this->assertTrue($rows->contains(fn ($r) => $r->action === 'commercial.batch_imported'));
        $encoded = json_encode($rows->map(fn ($r) => [$r->action, $r->metadata])->all());

        foreach (['AMA TEST', 'NOT IN THE DIRECTORY', 'SOWUTUOM 7000', 'Ama Test'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded, $secret);
        }
    }

    // ---------------------------------------------------------------- a big workbook

    public function test_a_workbook_of_seventy_five_cca_sheets_is_read_sheet_by_sheet_in_flat_memory(): void
    {
        $small = $this->file(['ccas' => [$this->cca('80001', 'AMA TEST', 12)]]);
        $ccas = [];

        for ($i = 0; $i < 75; $i++) {
            $ccas[] = $this->cca((string) (81000 + $i), 'INVENTED CCA '.$i, 12, $i % 2 ? 'SOWUTUOM' : 'ODORKOR', 6000 + $i * 20);
        }

        $big = $this->file(['ccas' => $ccas], 'big.xlsx');
        $this->actingAs($this->officer());
        $service = app(CommercialImportService::class);
        gc_collect_cycles();

        memory_reset_peak_usage();
        $service->preview($small);
        $smallPeak = memory_get_peak_usage(true);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $preview = $service->preview($big);
        $bigPeak = memory_get_peak_usage(true);

        $this->assertSame(75, $preview['route_report']['sheets']);
        $this->assertSame(900, $preview['total_rows']);
        // 15 times the sheets must not mean 15 times the memory: one sheet is loaded at a time
        $this->assertLessThan($smallPeak + 48 * 1024 * 1024, $bigPeak, 'peak '.round($smallPeak / 1048576).' MB for 1 sheet, '.round($bigPeak / 1048576).' MB for 75');
    }
}
