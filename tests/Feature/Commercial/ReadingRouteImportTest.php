<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BatchShow;
use App\Livewire\Commercial\Batches;
use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\LocationMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8c: the reading report BY ROUTE (rptReadingSumm). Invented CCAs, ids, names and routes only.
 *
 * The base file (period 1 to 31 October 2026) has five CCA sheets:
 *   80001 AMA TEST     owns SOWUTUOM 4602 (200 strength, 180 visited) and holds the STALE row of SOWUTUOM 4601 (strength 165, no visits)
 *   80002 KWAME TEST   owns SOWUTUOM 4601 (strength 169, 120 visited), ODORKOR 5101 (100 strength, 90 visited)
 *   80003 EFUA TEST    owns ODORKOR 5102 (80 strength, 0 visited): routes but NO VISITS
 *   80004 YAW TEST     no route rows at all
 *   00000 System Administrator: skipped (the excluded account)
 */
class ReadingRouteImportTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_customer_list_enabled' => false]);
    }

    /** @return array<string, mixed> */
    protected function baseSpec(array $over = []): array
    {
        return $over + [
            'ccas' => [
                ['id' => '80001', 'name' => 'AMA TEST', 'routes' => [
                    ['label' => 'SOWUTUOM 4601', 'strength' => 165, 'read' => 0, 'skipped' => 0, 'visited' => 0],
                    ['label' => 'SOWUTUOM 4602', 'strength' => 200, 'read' => 150, 'skipped' => 30, 'visited' => 180],
                ]],
                ['id' => '80002', 'name' => 'KWAME TEST', 'routes' => [
                    ['label' => 'SOWUTUOM 4601', 'strength' => 169, 'read' => 100, 'skipped' => 20, 'visited' => 120],
                    ['label' => 'ODORKOR 5101', 'strength' => 100, 'read' => 80, 'skipped' => 10, 'visited' => 90],
                ]],
                ['id' => '80003', 'name' => 'EFUA TEST', 'routes' => [
                    ['label' => 'ODORKOR 5102', 'strength' => 80, 'read' => 0, 'skipped' => 0, 'visited' => 0],
                ]],
                ['id' => '80004', 'name' => 'YAW TEST', 'routes' => []],
                ['id' => '00000', 'name' => 'System Administrator', 'routes' => [
                    ['label' => 'SOWUTUOM 9999', 'strength' => 10, 'read' => 5, 'skipped' => 1, 'visited' => 6],
                ]],
            ],
        ];
    }

    protected function staff(): void
    {
        $this->employee('80001', 'Ama Test', $this->accraWest, $this->sowutuom);
        $this->employee('80002', 'Kwame Test', $this->accraWest, $this->sowutuom);
        $this->employee('80003', 'Efua Test', $this->accraWest, $this->odorkor);
    }

    protected function previewOf(\Illuminate\Http\UploadedFile $file): array
    {
        $this->actingAs($this->officer());

        return app(CommercialImportService::class)->preview($file);
    }

    protected function routeFile(array $spec = [], string $name = 'rptReadingSumm.xlsx'): \Illuminate\Http\UploadedFile
    {
        return $this->asUpload(ReportWorkbooks::readingRoute($spec), $name);
    }

    protected function importRoute(array $spec = []): CommercialImportBatch
    {
        $this->actingAs($this->officer());

        return $this->importFile($this->routeFile($spec));
    }

    // ---------------------------------------------------------------- detection and the period

    public function test_the_kind_of_report_is_detected_from_the_sheets_and_the_header_text(): void
    {
        $preview = $this->previewOf($this->routeFile($this->baseSpec()));

        $this->assertSame(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, $preview['report_type']);
        $this->assertFalse($preview['blocked'], json_encode($preview['errors']));

        // the explicit "Read #" header layout is the same report
        $explicit = $this->previewOf($this->routeFile($this->baseSpec(['header' => 'explicit'])));
        $this->assertSame(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, $explicit['report_type']);
        $this->assertSame($preview['total_rows'], $explicit['total_rows']);

        // the by-month report and the billing report are not mistaken for it
        $this->assertSame(CommercialImportBatch::TYPE_READING_SUMMARY, $this->previewOf($this->readingFile())['report_type']);
        $this->assertSame(CommercialImportBatch::TYPE_BILLING_SUMMARY, $this->previewOf($this->billingFile())['report_type']);
    }

    public function test_the_preview_says_what_was_read(): void
    {
        $this->staff();
        $this->actingAs($this->officer());
        $preview = $this->previewOf($this->routeFile($this->baseSpec()));
        $report = $preview['route_report'];

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $preview['period']);
        $this->assertSame('Oct 2026', $report['period_label']);
        $this->assertSame('month', $report['period_kind']);
        $this->assertSame('Accra West', $preview['region']['name']);
        $this->assertSame(4, $report['sheets'], 'four CCA sheets; the system account is excluded');
        $this->assertSame(4, $report['ccas']);
        $this->assertSame(4, $report['routes'], 'SOWUTUOM 4601, 4602, ODORKOR 5101, 5102');
        $this->assertSame(2, $report['districts']);
        $this->assertSame(5, $preview['total_rows'], 'five route rows (4601 is on two sheets)');
        $this->assertSame(1, $preview['system_count'], 'the excluded account had one route row');
    }

    public function test_the_period_is_any_dates_a_day_a_range_or_a_whole_month(): void
    {
        $period = fn (string $text) => $this->previewOf($this->routeFile($this->baseSpec(['period' => $text])))['period'];

        $this->assertSame(['from' => '2026-10-05', 'to' => '2026-10-05'], $period('05-October-2026 - 05-October-2026'), 'a single day');
        $this->assertSame(['from' => '2026-10-04', 'to' => '2026-10-17'], $period('04-October-2026 - 17-October-2026'), 'a range');
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $period('01-October-2026 - 31-October-2026'), 'a whole month');
        $this->assertSame(['from' => '2026-09-14', 'to' => '2026-11-02'], $period('14-September-2026 - 02-November-2026'), 'across months');

        $bad = $this->previewOf($this->routeFile($this->baseSpec(['period' => 'last fortnight'])));
        $this->assertTrue($bad['blocked']);
        $this->assertStringContainsString('could not be read as two dates', collect($bad['errors'])->pluck('message')->implode(' '));

        $granularity = fn (string $text) => $this->previewOf($this->routeFile($this->baseSpec(['period' => $text])));
        $this->assertSame(CommercialImportBatch::GRANULARITY_MONTHLY, $this->importRoute($this->baseSpec())->granularity);
    }

    public function test_the_percentage_columns_are_never_read_and_the_unvisited_column_is_not_stored(): void
    {
        $this->staff();
        $batch = $this->importRoute($this->baseSpec());

        // the file carries 99.9 in every percentage column: nothing of it is anywhere
        $this->assertSame(0, DB::table('commercial_reading_route_stats')->where('read_count', 99)->orWhere('visited_count', 99)->orWhere('skipped_count', 99)->count());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('commercial_reading_route_stats', 'unvisited_count'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('commercial_reading_route_stats', 'read_pct'));

        $kwame = $batch->routeStats()->where('cca_staff_id', '80002')->whereNotNull('route_label_raw')->orderBy('route_label_raw')->get();
        $this->assertSame([90, 80, 10, 100], [$kwame[0]->visited_count, $kwame[0]->read_count, $kwame[0]->skipped_count, $kwame[0]->verified_strength]);
    }

    // ---------------------------------------------------------------- the owner rule

    public function test_the_owner_is_the_cca_with_the_visits_strength_counts_once_and_the_stale_row_is_kept(): void
    {
        $this->staff();
        $batch = $this->importRoute($this->baseSpec());
        $route = CommercialRoute::query()->where('code', '4601')->firstOrFail();
        $rows = $batch->routeStats()->where('route_id', $route->id)->orderBy('cca_staff_id')->get();

        $this->assertCount(2, $rows, 'both rows are kept');
        $this->assertSame(['80001', false, 165, 0], [$rows[0]->cca_staff_id, $rows[0]->is_route_owner, $rows[0]->verified_strength, $rows[0]->visited_count], 'assigned, no visits');
        $this->assertSame(['80002', true, 169, 120], [$rows[1]->cca_staff_id, $rows[1]->is_route_owner, $rows[1]->verified_strength, $rows[1]->visited_count], 'the owner');

        // the strength of the route counts ONCE: 169 + 200 + 100 + 80, not 165 + 169 + ...
        $this->assertSame(169 + 200 + 100 + 80, (int) $batch->routeStats()->where('is_route_owner', true)->sum('verified_strength'));
        $this->assertSame(4, $batch->routeStats()->where('is_route_owner', true)->count(), 'one owner per route');
    }

    public function test_the_owner_ties_go_to_the_larger_strength_then_the_lower_staff_id(): void
    {
        $pair = fn (array $a, array $b) => \App\Services\Commercial\ReadingRouteOwner::ownerIndex([$a, $b]);

        $this->assertSame(1, $pair(['cca' => '80001', 'strength' => 100, 'visited' => 10], ['cca' => '80002', 'strength' => 100, 'visited' => 11]), 'more visits');
        $this->assertSame(0, $pair(['cca' => '80002', 'strength' => 120, 'visited' => 0], ['cca' => '80001', 'strength' => 100, 'visited' => 0]), 'zero visits each: the larger strength');
        $this->assertSame(1, $pair(['cca' => '80009', 'strength' => 100, 'visited' => 0], ['cca' => '80002', 'strength' => 100, 'visited' => 0]), 'all equal: the lower staff id');
        $this->assertSame(0, $pair(['cca' => '9', 'strength' => 1, 'visited' => 0], ['cca' => '10', 'strength' => 1, 'visited' => 0]), 'numeric ids compare as numbers: 9 before 10');
        $this->assertNull(\App\Services\Commercial\ReadingRouteOwner::ownerIndex([]));
    }

    public function test_the_preview_lists_the_owner_decisions_and_the_ccas_with_no_routes_or_no_visits(): void
    {
        $this->staff();
        $preview = $this->previewOf($this->routeFile($this->baseSpec()));
        $report = $preview['route_report'];

        $this->assertSame(1, $report['owner_decision_count']);
        $this->assertSame('SOWUTUOM 4601', $report['owner_decisions'][0]['route']);
        $this->assertSame('80002', $report['owner_decisions'][0]['owner']);
        $this->assertSame(['80001'], $report['owner_decisions'][0]['others']);
        $this->assertSame(1, $report['assigned_no_visits']);
        $this->assertStringContainsString('1 route appears under more than one CCA', collect($preview['warnings'])->pluck('message')->implode(' '));
    }

    // ---------------------------------------------------------------- findings are kept

    public function test_ccas_with_no_routes_or_no_visits_are_kept_not_dropped(): void
    {
        $this->staff();
        $preview = $this->previewOf($this->routeFile($this->baseSpec()));

        $this->assertSame([['staff_id' => '80004', 'name' => 'YAW TEST']], $preview['route_report']['no_routes']);
        $this->assertSame([['staff_id' => '80003', 'name' => 'EFUA TEST', 'routes' => 1]], $preview['route_report']['no_visits'], 'routes but no visits (80001 has visits on 4602, so it is not one)');

        $batch = $this->importRoute($this->baseSpec());
        $kept = $batch->routeStats()->whereIn('cca_staff_id', ['80003', '80004'])->orderBy('cca_staff_id')->get();

        $this->assertSame(['80003', '80004'], $kept->pluck('cca_staff_id')->all(), 'both are rows of the batch');
        $this->assertSame('ODORKOR 5102', $kept[0]->route_label_raw);
        $this->assertNull($kept[1]->route_label_raw, 'a CCA with no routes is one row with no route');
        $this->assertNull($kept[1]->route_id);
    }
}
