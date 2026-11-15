<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialCustomerUpload;
use App\Models\District;
use App\Models\User;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\LocationMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8b: a customer list for one, several or every district of a region. A file is a PARENT upload; every district found in it has one
 * CHILD batch that behaves exactly as a one-district file always did. Synthetic data only (invented districts' customers 1..n).
 */
class CustomerRegionWideTest extends CustomerTestCase
{
    protected District $kaneshie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kaneshie = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Kaneshie']);
    }

    /** A region-wide spec (no DISTRICT: line). Each block: [district as printed, route, first customer, last customer, extra route keys]. */
    protected function wide(array $blocks, array $extra = []): array
    {
        return ['district' => null, 'routes' => array_map(fn (array $b) => ['name' => $b[1], 'district' => $b[0], 'customers' => $this->customers($b[2], $b[3])] + ($b[4] ?? []), $blocks)] + $extra;
    }

    /** @return array{0: CustomerImportService, 1: CommercialCustomerUpload, 2: CommercialCustomerBatch} registered, not yet processed */
    protected function registerWide(array $spec, string $asOf = '2026-10-05', ?User $actor = null, bool $review = false): array
    {
        $path = ReportWorkbooks::customerList($spec);
        $this->workbooks[] = $path;
        $imports = app(CustomerImportService::class);
        ['batch' => $batch, 'upload' => $upload] = $imports->register($path, 'wide-'.uniqid().'.xlsx', 'monthly', Carbon::parse($asOf), $actor, null, $review);

        return [$imports, $upload, $batch];
    }

    protected function loadWide(array $spec, string $asOf = '2026-10-05', ?User $actor = null): CommercialCustomerUpload
    {
        [$imports, $upload, $batch] = $this->registerWide($spec, $asOf, $actor);
        $imports->process($batch);

        return $upload->fresh();
    }

    protected function child(CommercialCustomerUpload $upload, District $district): ?CommercialCustomerBatch
    {
        return $upload->batches()->where('district_id', $district->id)->first();
    }

    protected function threeDistricts(string $asOf = '2026-10-05'): CommercialCustomerUpload
    {
        return $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12], ['SOWUTUOM', '1002', 13, 14]]), $asOf);
    }

    // ---------------------------------------------------------------- one child per district

    public function test_a_region_wide_file_makes_one_batch_per_district_and_loads_every_row_under_its_own_district(): void
    {
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12], ['SOWUTUOM', '1002', 13, 14]]));
        $imports->process($batch);
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status, json_encode([$upload->errors, $upload->error_message, $upload->batches()->get(['district_id', 'status', 'phase', 'error_message', 'errors'])->toArray()]));
        $this->assertSame(3, $upload->batches()->count(), 'one child per district found');
        $this->assertSame(3, $upload->districts_found);
        $this->assertSame(3, $upload->districts_selected);
        $this->assertSame(14, $upload->rows_found);
        $this->assertSame(4, $upload->routes_found);

        foreach ([[$this->sowutuom, 7, 2], [$this->odorkor, 4, 1], [$this->kaneshie, 3, 1]] as [$district, $customers, $routes]) {
            $child = $this->child($upload, $district);
            $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $child->status, $district->district_name);
            $this->assertSame($customers, $child->rows_read);
            $this->assertSame($customers, $child->rows_new);
            $this->assertSame($customers, $this->customerCount($district->id));
            $this->assertSame($routes, $child->routes_count);
        }

        // every customer is in the district its heading names
        $this->assertSame($this->sowutuom->id, (int) $this->customerRow(3)->district_id);
        $this->assertSame($this->odorkor->id, (int) $this->customerRow(7)->district_id);
        $this->assertSame($this->kaneshie->id, (int) $this->customerRow(11)->district_id);
        $this->assertSame($this->sowutuom->id, (int) $this->customerRow(13)->district_id, 'a district whose routes are not together in the file is still one batch');

        // the file was read for staging ONCE (plus the cheap first pass), whatever the number of districts
        $this->assertSame(1, $imports->stageReads);
        $this->assertSame(2, $imports->sheetReads);

        $this->assertSame(0, DB::table('commercial_customer_staging')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('commercial/customer-imports'), 'the workbook holds personal data: it is gone');
        $this->assertNull($upload->file_path);
        $this->assertSame(0, DB::table('commercial_customers')->whereNotNull('ops_route_id')->count(), 'ops_route_id stays unused until the billing details phase');
    }

    public function test_the_discovery_lists_every_district_with_its_customer_and_route_counts_before_anything_is_loaded(): void
    {
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['SOWUTUOM', '1002', 10, 11]]), review: true);
        $imports->process($batch);
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_AWAITING_REVIEW, $upload->status);
        $this->assertSame(0, $this->customerCount(), 'nothing is loaded until the person confirms');
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());

        $found = collect($upload->districts)->keyBy('name');
        $this->assertSame([7, 2], [$found['Sowutuom']['rows'], $found['Sowutuom']['routes']]);
        $this->assertSame([4, 1], [$found['Odorkor']['rows'], $found['Odorkor']['routes']]);
        $this->assertSame('load', $found['Sowutuom']['state']);
        $this->assertTrue(Storage::disk('local')->exists($upload->file_path), 'the file waits for the confirmation');
    }

    public function test_a_district_left_out_at_the_check_is_never_read_and_the_others_are_loaded(): void
    {
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]), review: true);
        $imports->process($batch);

        $imports->confirm($upload->fresh(), [$this->sowutuom->id, $this->kaneshie->id]);
        $imports->process($upload->batches()->first());
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status);
        $this->assertSame(2, $upload->batches()->count(), 'the unticked district lost its (empty) batch');
        $this->assertSame(0, $this->customerCount($this->odorkor->id));
        $this->assertSame(8, $this->customerCount());
        $this->assertSame('unticked', collect($upload->districts)->firstWhere('name', 'Odorkor')['state']);
        $this->assertSame(4, $upload->rows_skipped);
    }

    public function test_nothing_is_loaded_when_no_district_is_chosen(): void
    {
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['SOWUTUOM', '1001', 1, 3]]), review: true);
        $imports->process($batch);
        $imports->confirm($upload->fresh(), []);

        $this->assertSame(CommercialCustomerUpload::STATUS_BLOCKED, $upload->fresh()->status);
        $this->assertSame(0, $this->customerCount());
    }

    public function test_the_same_region_wide_file_twice_is_the_same_upload(): void
    {
        $path = ReportWorkbooks::customerList($this->wide([['SOWUTUOM', '1001', 1, 3], ['ODORKOR', '2001', 4, 5]]));
        $this->workbooks[] = $path;
        $imports = app(CustomerImportService::class);

        ['batch' => $batch, 'upload' => $upload, 'duplicate' => $first] = $imports->register($path, 'a.xlsx', 'monthly', Carbon::parse('2026-10-05'), null);
        $imports->process($batch);
        ['upload' => $again, 'duplicate' => $second] = $imports->register($path, 'b.xlsx', 'monthly', Carbon::parse('2026-10-12'), null);

        $this->assertFalse($first);
        $this->assertTrue($second);
        $this->assertSame($upload->id, $again->id);
        $this->assertSame(1, CommercialCustomerUpload::query()->count());
        $this->assertSame(2, CommercialCustomerBatch::query()->count(), 'one per district, nothing done twice');
    }

    // ---------------------------------------------------------------- only the districts in the file are touched

    public function test_a_district_that_is_not_in_the_file_keeps_its_data_and_nothing_in_it_is_marked_missing(): void
    {
        $this->threeDistricts('2026-10-05');
        $sowutuomBefore = DB::table('commercial_customers')->where('district_id', $this->sowutuom->id)->orderBy('id')->get()->toJson();
        $sowutuomBatches = CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->count();

        // a later file with only Odorkor and Kaneshie
        $second = $this->loadWide($this->wide([['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]), '2026-11-05');

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $second->status);
        $this->assertSame(2, $second->batches()->count());
        $this->assertNull($this->child($second, $this->sowutuom), 'Sowutuom is not in the file: no batch, nothing read');
        $this->assertSame($sowutuomBefore, DB::table('commercial_customers')->where('district_id', $this->sowutuom->id)->orderBy('id')->get()->toJson(), 'its rows were not touched');
        $this->assertSame(0, DB::table('commercial_customers')->where('district_id', $this->sowutuom->id)->whereNotNull('missing_since_batch_id')->count(), 'nothing in it is marked missing');
        $this->assertSame($sowutuomBatches, CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->count());
    }

    public function test_a_district_in_the_file_with_fewer_customers_gets_the_warning_and_its_missing_accounts(): void
    {
        $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 20], ['ODORKOR', '2001', 21, 30]]), '2026-10-05');

        // Sowutuom drops from 20 to 8 customers (-60%); Odorkor is unchanged
        $second = $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 8], ['ODORKOR', '2001', 21, 30]]), '2026-11-05');
        $sowutuom = $this->child($second, $this->sowutuom);
        $odorkor = $this->child($second, $this->odorkor);

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $sowutuom->status, 'a warning, not a block');
        $this->assertSame(12, $sowutuom->rows_missing);
        $this->assertEquals(-60.0, $sowutuom->count_change_pct);
        $this->assertStringContainsString('moved by -60', collect($sowutuom->warnings)->pluck('message')->implode(' '));
        $this->assertSame(12, DB::table('commercial_customers')->where('district_id', $this->sowutuom->id)->whereNotNull('missing_since_batch_id')->count());

        $this->assertSame(0, $odorkor->rows_missing);
        $this->assertSame(10, $odorkor->rows_unchanged);
        $this->assertStringNotContainsString('moved by', collect($odorkor->warnings)->pluck('message')->implode(' '));
    }

    public function test_an_older_file_blocks_only_its_own_districts_batch(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 3), '2026-11-05');   // Sowutuom already has data as of 5 November

        $upload = $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 3], ['ODORKOR', '2001', 6, 9]]), '2026-10-05');
        $sowutuom = $this->child($upload, $this->sowutuom);
        $odorkor = $this->child($upload, $this->odorkor);

        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $sowutuom->status);
        $this->assertStringContainsString('newer file', $sowutuom->errors[0]['message']);
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $odorkor->status, 'the other districts carry on');
        $this->assertSame(4, $this->customerCount($this->odorkor->id));
        $this->assertSame(3, $this->customerCount($this->sowutuom->id), 'Sowutuom keeps the newer data');
        $this->assertSame(CommercialCustomerUpload::STATUS_PARTIAL, $upload->status);
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());
    }

    public function test_a_file_whose_districts_are_all_older_than_their_data_loads_nothing(): void
    {
        $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 3], ['ODORKOR', '2001', 6, 9]]), '2026-11-05');
        $older = $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 3], ['ODORKOR', '2001', 6, 8]]), '2026-10-05');

        $this->assertSame(CommercialCustomerUpload::STATUS_BLOCKED, $older->status);
        $this->assertSame([CommercialCustomerBatch::STATUS_BLOCKED, CommercialCustomerBatch::STATUS_BLOCKED], $older->batches()->pluck('status')->all());
    }

    // ---------------------------------------------------------------- unknown districts

    public function test_an_unknown_district_parks_the_file_and_it_resumes_after_a_match(): void
    {
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['SOWUTUOM', '1001', 1, 3], ['NOWHERE', '9001', 4, 8], ['ODORKOR', '2001', 9, 10]]));
        $imports->process($batch);
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_NEEDS_MATCH, $upload->status);
        $this->assertSame([['label' => 'NOWHERE', 'rows' => 5, 'routes' => 1]], $upload->unrecognised, 'the unrecognised names with their row counts');
        $this->assertSame(0, DB::table('commercial_customer_staging')->count(), 'no customer row was staged');
        $this->assertSame(0, $this->customerCount());
        $this->assertSame(CommercialCustomerBatch::STATUS_NEEDS_MATCH, $batch->fresh()->status, 'a one-district view of the file mirrors it');

        app(LocationMatcher::class)->saveDistrictAlias('NOWHERE', $this->kaneshie);
        $imports = app(CustomerImportService::class);   // a new job run: its matcher reads the alias that was just saved
        $imports->resumeAfterMatch($upload->fresh());
        $imports->process($batch->fresh());
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status, json_encode([$upload->errors, $upload->warnings]));
        $this->assertSame(3, $upload->batches()->count());
        $this->assertSame(5, $this->customerCount($this->kaneshie->id), 'the matched district got its rows');
        $this->assertSame(3, $this->customerCount($this->sowutuom->id));
    }

    public function test_several_unknown_names_are_all_listed_and_two_spellings_of_one_district_are_one_batch(): void
    {
        app(LocationMatcher::class)->saveDistrictAlias('KANESHIE CENTRAL', $this->kaneshie);
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['KANESHIE', '3001', 1, 2], ['KANESHIE CENTRAL', '3002', 3, 4], ['LOST ONE', '8001', 5, 6], ['LOST TWO', '8002', 7, 9]]));
        $imports->process($batch);
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_NEEDS_MATCH, $upload->status);
        $this->assertEqualsCanonicalizing([['label' => 'LOST ONE', 'rows' => 2, 'routes' => 1], ['label' => 'LOST TWO', 'rows' => 3, 'routes' => 1]], $upload->unrecognised);

        app(LocationMatcher::class)->saveDistrictAlias('LOST ONE', $this->sowutuom);
        app(LocationMatcher::class)->saveDistrictAlias('LOST TWO', $this->sowutuom);
        $imports = app(CustomerImportService::class);
        $imports->resumeAfterMatch($upload->fresh());
        $imports->process($batch->fresh());

        $this->assertSame(2, $upload->fresh()->batches()->count(), 'Kaneshie and its alias are ONE district; the two lost names are Sowutuom');
        $this->assertSame(4, $this->customerCount($this->kaneshie->id));
        $this->assertSame(5, $this->customerCount($this->sowutuom->id));
    }

    public function test_rows_under_a_heading_with_no_district_in_a_file_with_no_district_line_cannot_be_placed(): void
    {
        $spec = $this->wide([['', '1001', 1, 3]]);
        $upload = $this->loadWide($spec);

        $this->assertSame(CommercialCustomerUpload::STATUS_BLOCKED, $upload->status);
        $this->assertStringContainsString('names no district', $upload->errors[0]['message']);
        $this->assertSame(0, $this->customerCount());
    }

    // ---------------------------------------------------------------- district users

    protected function districtUploader(string $staffId = '910900'): User
    {
        return $this->userWith($staffId, ['commercial.view_customer_analytics', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom, districtLimited: true);
    }

    public function test_a_district_user_keeps_only_their_districts_batch_and_is_told_how_many_were_skipped(): void
    {
        $upload = $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]), actor: $this->districtUploader());

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status);
        $this->assertSame(1, $upload->batches()->count(), 'the other districts\' batches are NOT created');
        $this->assertSame(5, $this->customerCount($this->sowutuom->id));
        $this->assertSame(0, $this->customerCount($this->odorkor->id));
        $this->assertSame(0, $this->customerCount($this->kaneshie->id));
        $this->assertSame('2 other districts skipped (not yours); nothing stored.', $upload->warnings[0]['message']);
        $this->assertSame(7, $upload->rows_skipped);
        $this->assertStringNotContainsString('Odorkor', json_encode($upload->districts), 'another district\'s name is not kept for a district user');
        $this->assertStringNotContainsString('Kaneshie', json_encode($upload->districts));
    }

    public function test_a_region_wide_file_without_the_district_of_a_district_user_is_blocked(): void
    {
        $upload = $this->loadWide($this->wide([['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]), actor: $this->districtUploader('910901'));

        $this->assertSame(CommercialCustomerUpload::STATUS_BLOCKED, $upload->status);
        $this->assertStringContainsString('none of your district', $upload->errors[0]['message']);
        $this->assertStringContainsString('2 other districts skipped (not yours); nothing was stored', $upload->errors[0]['message']);
        $this->assertSame(0, $this->customerCount());
        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $upload->batches()->firstOrFail()->status);
    }

    public function test_a_region_wide_uploader_gets_every_district(): void
    {
        $upload = $this->loadWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9]]), actor: $this->officer('910902', $this->accraWest, $this->sowutuom));

        $this->assertSame(2, $upload->batches()->count());
        $this->assertSame(9, $this->customerCount());
    }

    // ---------------------------------------------------------------- routes and reconciliation

    public function test_a_code_printed_under_two_districts_is_one_canonical_route_and_each_customer_keeps_the_districts_heading(): void
    {
        $this->loadWide($this->wide([['SOWUTUOM', '4001', 1, 3], ['ODORKOR', '4001', 4, 6]]));

        $routes = DB::table('commercial_routes')->where('code', '4001')->get();
        $this->assertCount(2, $routes, 'both (district, code) pairs are known');
        $this->assertSame(1, $routes->whereNull('canonical_route_id')->count(), 'exactly one is canonical');

        $this->assertSame(1, DB::table('commercial_customers')->whereIn('account_no', [$this->customerRow(1)->account_no, $this->customerRow(5)->account_no])->distinct()->count('route_id'), 'both districts\' customers are filed under the canonical route');
        $this->assertSame($this->sowutuom->id, (int) $this->customerRow(2)->district_id, 'the district stays the heading\'s, exactly as the list says');
        $this->assertSame($this->odorkor->id, (int) $this->customerRow(5)->district_id);
    }

    public function test_a_route_picked_under_a_district_is_that_districts_own_route_even_when_its_code_is_an_alias(): void
    {
        $upload = $this->loadWide($this->wide([['SOWUTUOM', '4001', 1, 3], ['ODORKOR', '4001', 4, 6]]));
        $lists = app(\App\Services\Commercial\Customers\CustomerListService::class);
        $routeOf = fn (District $district) => (int) DB::table('commercial_routes')->where('district_id', $district->id)->where('code', '4001')->value('id');
        $alias = DB::table('commercial_routes')->whereNotNull('canonical_route_id')->where('code', '4001')->first();
        $page = fn (District $district, int $route) => collect($lists->page(['district_id' => $district->id, 'route_id' => $route, 'size' => 50, 'batch_id' => $this->child($upload, $district)->id, 'as_of' => '2026-10-05'])['rows'])
            ->pluck('account_no')->map(fn ($a) => (int) ltrim($a, '0') - 100000000000)->sort()->values()->all();

        $this->assertNotNull($alias, 'one of the two (district, code) pairs is the alias');
        $this->assertSame([1, 2, 3], $page($this->sowutuom, $routeOf($this->sowutuom)));
        $this->assertSame([4, 5, 6], $page($this->odorkor, $routeOf($this->odorkor)), 'the alias row finds this district\'s customers, which are filed under the canonical route');
    }

    public function test_a_route_that_fails_its_own_totals_is_flagged_and_still_imports_and_the_upload_lists_every_flagged_route(): void
    {
        $blocks = [];

        for ($r = 0; $r < 100; $r++) {
            $blocks[] = ['SOWUTUOM', (string) (1100 + $r), $r * 2 + 1, $r * 2 + 2, $r === 40 ? ['count' => 3] : []];
        }

        $blocks[] = ['ODORKOR', '2001', 301, 304, ['count' => 5]];   // the only route of its district: 100% would block it
        $upload = $this->loadWide($this->wide($blocks));
        $sowutuom = $this->child($upload, $this->sowutuom);

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $sowutuom->status, json_encode($sowutuom->errors));
        $this->assertSame(200, $this->customerCount($this->sowutuom->id));
        $this->assertFalse($sowutuom->reconciliation_passed);
        $this->assertSame([1140, 2, 3, -1], [(int) $sowutuom->control_totals[0]['route'], $sowutuom->control_totals[0]['read'], $sowutuom->control_totals[0]['expected'], $sowutuom->control_totals[0]['count_difference']]);

        // the other district blocks alone
        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $this->child($upload, $this->odorkor)->status);

        // the parent shows the flagged routes of every child together
        $flagged = app(CustomerImportService::class)->flaggedRoutes($upload);
        $this->assertEqualsCanonicalizing(['Sowutuom', 'Odorkor'], array_column($flagged, 'district'));
        $this->assertSame(CommercialCustomerUpload::STATUS_PARTIAL, $upload->status);
    }

    public function test_an_account_listed_under_two_districts_of_one_file_blocks_those_batches_only(): void
    {
        $spec = $this->wide([['SOWUTUOM', '1001', 1, 3], ['ODORKOR', '2001', 3, 5], ['KANESHIE', '3001', 20, 22]]);   // customer 3 is under both
        $upload = $this->loadWide($spec);

        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $this->child($upload, $this->sowutuom)->status);
        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $this->child($upload, $this->odorkor)->status);
        $this->assertStringContainsString('also appear under another district', $this->child($upload, $this->sowutuom)->errors[0]['message']);
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $this->child($upload, $this->kaneshie)->status);
        $this->assertSame(3, $this->customerCount());
    }

    // ---------------------------------------------------------------- a failure in one district

    public function test_a_failure_in_one_district_does_not_stop_the_others_and_the_failed_one_resumes(): void
    {
        [$imports, $upload, $batch] = $this->registerWide($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]));

        // the rollup of ONE district dies (as a worker killed in that child's last phase would)
        $killed = false;
        DB::listen(function ($query) use (&$killed): void {
            if (! $killed && str_contains($query->sql, 'insert into "commercial_customer_debt_stats"') && ($query->bindings[1] ?? null) === $this->odorkor->id) {
                $killed = true;

                throw new \RuntimeException('worker killed in one district');
            }
        });

        try {
            $imports->process($batch);
            $this->fail('the failure must be reported once the other districts are done');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\App\Services\Commercial\Customers\CustomerImportException::class, $e);
        }

        $upload->refresh();
        $statuses = $upload->batches()->get()->mapWithKeys(fn ($b) => [(int) $b->district_id => $b->status])->all();
        $this->assertSame(CommercialCustomerBatch::STATUS_FAILED, $statuses[$this->odorkor->id]);
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $statuses[$this->sowutuom->id], 'the others were loaded');
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $statuses[$this->kaneshie->id]);
        $this->assertSame(CommercialCustomerUpload::STATUS_PARTIAL, $upload->status);
        $this->assertSame(5, $this->customerCount($this->sowutuom->id));

        // run again: only the failed district is done, and the upload is complete
        $imports->process($batch->fresh());
        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->fresh()->status);
        $this->assertSame(4, $this->customerCount($this->odorkor->id));
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());
    }

    // ---------------------------------------------------------------- the generated fixture

    public function test_the_generated_region_wide_fixture_has_the_real_layout_and_every_awkward_case(): void
    {
        // the file is made by `php artisan commercial:customers:sample --region-wide --edge-cases ...`: invented districts, routes, names and numbers
        foreach (['Sample Alpha', 'Sample Bravo', 'Sample Charlie', 'Sample Delta'] as $name) {
            District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => $name]);
        }

        // a file of a few hundred rows has only a handful of routes, so one route a row out is a large share of them: the tolerances are widened for it
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 40.0, 'gwl.commercial_reconcile_grand_total_tolerance_pct' => 5.0]);

        $imports = app(CustomerImportService::class);
        ['batch' => $batch, 'upload' => $upload] = $imports->register(base_path('tests/Fixtures/region-wide-customers-sample.xlsx'), 'fixture.xlsx', 'monthly', Carbon::parse('2026-03-31'), null);
        $imports->process($batch);
        $upload->refresh();

        // the heading with an unknown district parks the whole file; nothing was read
        $this->assertSame(CommercialCustomerUpload::STATUS_NEEDS_MATCH, $upload->status);
        $this->assertSame([['label' => 'SAMPLE UNKNOWN', 'rows' => 6, 'routes' => 1]], $upload->unrecognised);
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());

        app(LocationMatcher::class)->saveDistrictAlias('SAMPLE UNKNOWN', District::query()->where('district_name', 'Sample Delta')->firstOrFail());
        $imports = app(CustomerImportService::class);
        $imports->resumeAfterMatch($upload->fresh());
        $imports->process($batch->fresh());
        $upload->refresh();

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status, json_encode([$upload->errors, $upload->batches()->get(['status', 'errors'])->toArray()]));
        $this->assertSame(4, $upload->batches()->count());
        $this->assertSame(220, $this->customerCount());
        $this->assertSame([59, 55, 50, 56], $upload->batches()->orderBy('id')->pluck('rows_read')->all(), 'Alpha 50+5+4, Bravo 50+5, Charlie 50, Delta 50+6');

        // the route whose TOTALS row is one row out is flagged and its district still loaded
        $alpha = $upload->batches()->where('district_label_raw', 'SAMPLE ALPHA')->firstOrFail();
        $flagged = collect($alpha->control_totals)->where('ok', false)->values();
        $this->assertCount(1, $flagged);
        $this->assertSame([25, 26, -1], [$flagged[0]['read'], $flagged[0]['expected'], $flagged[0]['count_difference']]);
        $this->assertSame($alpha->control_totals[0]['route'], $flagged[0]['route'], 'flagged routes are listed first');

        // one code under two districts is one canonical route; the 0000 route is kept as printed
        $this->assertSame(1, DB::table('commercial_routes')->where('code', '4001')->whereNull('canonical_route_id')->count());
        $this->assertSame(2, DB::table('commercial_routes')->where('code', '4001')->count());
        $this->assertSame(1, DB::table('commercial_routes')->where('code', '0000')->count());

        // a customer with two mobiles and two e-mails: the first of each is used, the second is kept
        $contact = DB::table('commercial_customer_contacts AS ct')->join('commercial_customers AS c', 'c.id', '=', 'ct.customer_id')->where('c.account_no', '200001000000')->first();
        $this->assertSame('0241234567', $contact->phone_primary);
        $this->assertSame('0207654321', $contact->phone_secondary);
        $this->assertSame('two.mail@example.test', $contact->email);
        $this->assertSame('second.mail@example.test', $contact->email_secondary);
    }

    // ---------------------------------------------------------------- memory

    public function test_memory_does_not_grow_with_the_size_of_a_region_wide_file(): void
    {
        $districts = ['SOWUTUOM', 'ODORKOR', 'KANESHIE'];
        $measure = function (int $rows, int $first) use ($districts): float {
            $path = ReportWorkbooks::customerListRegionStreamed($rows, $districts, 'ACCRA WEST', 400, $first);
            $this->workbooks[] = $path;
            $imports = app(CustomerImportService::class);
            ['batch' => $batch, 'upload' => $upload] = $imports->register($path, "{$rows}.xlsx", 'monthly', Carbon::parse('2026-10-05'), null);

            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $imports->process($batch);
            $growth = (memory_get_peak_usage() - $before) / 1048576;

            $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->fresh()->status, json_encode($upload->fresh()->errors));
            $this->assertSame($rows, (int) $upload->fresh()->batches()->sum('rows_read'));
            $this->assertSame(1, $imports->stageReads);

            return $growth;
        };

        $small = $measure(3000, 1);
        $large = $measure(12000, 100000);

        $this->assertLessThan(48, $large, "a 12,000-row region-wide file grew memory by {$large} MB");
        $this->assertLessThan(max(12, $small * 2.0), $large, "memory must not follow the file size: {$small} MB for 3,000 rows, {$large} MB for 12,000");
    }
}
