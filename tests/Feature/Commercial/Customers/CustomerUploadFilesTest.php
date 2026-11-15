<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\BatchDetail;
use App\Livewire\Commercial\Customers\UploadShow;
use App\Livewire\Commercial\Customers\Uploads;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialCustomerUpload;
use App\Models\District;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\LocationMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8b screens: the check before a file is loaded (untick a district), the history with one row per upload and its districts
 * expandable, the names that need a match, and undo per district and for the whole upload. Invented data only.
 */
class CustomerUploadFilesTest extends CustomerTestCase
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected District $kaneshie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kaneshie = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Kaneshie']);
    }

    protected function wide(array $blocks): array
    {
        return ['district' => null, 'routes' => array_map(fn (array $b) => ['name' => $b[1], 'district' => $b[0], 'customers' => $this->customers($b[2], $b[3])], $blocks)];
    }

    protected function uploadFile(string $path, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $file = new UploadedFile($path, 'customers.xlsx', self::XLSX, null, true);

        return $this->post(route('commercial.customers.upload'), $extra + ['file' => $file, 'period_type' => 'monthly', 'as_of_date' => '2026-10-05']);
    }

    protected function file(array $spec): string
    {
        $path = ReportWorkbooks::customerList($spec);
        $this->workbooks[] = $path;

        return $path;
    }

    protected function register(array $spec, string $asOf = '2026-10-05', bool $review = false): array
    {
        $imports = app(CustomerImportService::class);
        ['batch' => $batch, 'upload' => $upload] = $imports->register($this->file($spec), 'f-'.uniqid().'.xlsx', 'monthly', Carbon::parse($asOf), null, null, $review);

        return [$imports, $upload, $batch];
    }

    // ---------------------------------------------------------------- the upload form and the check

    public function test_the_form_offers_the_check_ticked_by_default_and_says_a_file_may_hold_every_district(): void
    {
        $page = Livewire::actingAs($this->officer())->test(Uploads::class);

        $page->assertSee('one district, several or every district')->assertSee('Show me the districts in this file before anything is loaded');
        $this->assertStringContainsString('name="review" value="1" checked', $page->html());
    }

    public function test_a_file_posted_with_the_check_stops_after_the_first_pass_and_waits_for_the_person(): void
    {
        $response = $this->actingAs($this->officer())->uploadFile($this->file($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9]])), ['review' => '1']);

        $upload = CommercialCustomerUpload::query()->firstOrFail();
        $response->assertRedirect(route('commercial.customers.file', $upload));

        // the (inline) queue has run the first pass: the districts are known, nothing is loaded
        $this->assertSame(CommercialCustomerUpload::STATUS_AWAITING_REVIEW, $upload->status);
        $this->assertTrue($upload->review);
        $this->assertSame(0, $this->customerCount());
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());
        $this->assertSame(['Odorkor', 'Sowutuom'], collect($upload->districts)->pluck('name')->sort()->values()->all());
        $this->actingAs($this->officer())->get(route('commercial.customers.file', $upload))->assertOk()->assertSee('This file will update these districts');
    }

    public function test_a_file_posted_without_the_check_is_loaded_straight_away_whatever_it_holds(): void
    {
        $this->actingAs($this->officer())->uploadFile($this->file($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 11]])));

        $upload = CommercialCustomerUpload::query()->firstOrFail();
        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status);
        $this->assertSame(11, $this->customerCount());
        $this->assertSame(3, $upload->batches()->count());
    }

    public function test_the_person_sees_the_districts_with_their_counts_unticks_one_and_confirms(): void
    {
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]), review: true);
        $imports->process($batch);

        $page = Livewire::actingAs($this->officer())->test(UploadShow::class, ['upload' => $upload->fresh()]);
        $page->assertSee('Sowutuom')->assertSee('Odorkor')->assertSee('Kaneshie')->assertSee('5 customers in 1 route')->assertSee('Update the ticked districts');
        $this->assertEqualsCanonicalizing([$this->sowutuom->id, $this->odorkor->id, $this->kaneshie->id], $page->get('selected'), 'everything found is ticked');

        $page->set('selected', [$this->sowutuom->id, $this->kaneshie->id])->call('confirmDistricts');

        $upload->refresh();
        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->status, 'the inline queue ran the job');
        $this->assertSame(8, $this->customerCount());
        $this->assertSame(0, $this->customerCount($this->odorkor->id), 'the unticked district was never read');
        $this->assertSame(2, $upload->batches()->count());
    }

    public function test_confirming_needs_the_upload_permission_and_a_file_that_is_not_waiting_cannot_be_confirmed_again(): void
    {
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 3]]), review: true);
        $imports->process($batch);

        $resolver = $this->userWith('910950', ['commercial.resolve_matches'], $this->accraWest, $this->sowutuom);
        Livewire::actingAs($resolver)->test(UploadShow::class, ['upload' => $upload->fresh()])->call('confirmDistricts')->assertForbidden();
        $this->assertSame(0, $this->customerCount());

        Livewire::actingAs($this->officer())->test(UploadShow::class, ['upload' => $upload->fresh()])->call('confirmDistricts');
        $this->assertSame(3, $this->customerCount());

        Livewire::actingAs($this->officer())->test(UploadShow::class, ['upload' => $upload->fresh()])->call('confirmDistricts');   // nothing happens twice
        $this->assertSame(3, $this->customerCount());
    }

    public function test_cancelling_a_file_that_is_waiting_deletes_it_and_loads_nothing(): void
    {
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 3]]), review: true);
        $imports->process($batch);

        Livewire::actingAs($this->officer())->test(UploadShow::class, ['upload' => $upload->fresh()])->call('cancelUpload');

        $this->assertSame(CommercialCustomerUpload::STATUS_BLOCKED, $upload->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('commercial/customer-imports'));
        $this->assertSame(0, $this->customerCount());
    }

    // ---------------------------------------------------------------- names that need a match

    public function test_the_names_that_need_a_match_are_listed_and_matching_them_all_resumes_the_file(): void
    {
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 3], ['LOST ONE', '8001', 4, 5], ['LOST TWO', '8002', 6, 8]]));
        $imports->process($batch);

        $page = Livewire::actingAs($this->userWith('910951', ['commercial.upload_reports', 'commercial.resolve_matches'], $this->accraWest, $this->sowutuom))->test(UploadShow::class, ['upload' => $upload->fresh()]);
        $page->assertSee('This file needs a match')->assertSee('LOST ONE')->assertSee('LOST TWO')->assertSee('2 customers')->assertSee('3 customers');

        $page->set('matches.0', $this->kaneshie->id)->call('matchDistricts');   // only one answered: nothing happens yet
        $this->assertSame(CommercialCustomerUpload::STATUS_NEEDS_MATCH, $upload->fresh()->status);

        $page->set('matches.1', $this->odorkor->id)->call('matchDistricts');

        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $upload->fresh()->status);
        $this->assertSame(2, $this->customerCount($this->kaneshie->id));
        $this->assertSame(3, $this->customerCount($this->odorkor->id));
        $this->assertNotNull(app(LocationMatcher::class)->resolveDistrict('LOST ONE', $this->accraWest->id), 'the answer is remembered');
    }

    // ---------------------------------------------------------------- the history

    public function test_the_history_has_one_row_per_upload_with_its_districts_expandable(): void
    {
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]));
        $imports->process($batch);
        $this->loadCustomers($this->simpleSpec(31, 33, ['district' => 'SOWUTUOM'], '4001'), '2026-11-05');

        $page = Livewire::actingAs($this->officer())->test(Uploads::class);

        $this->assertSame(2, $page->viewData('uploads')->count(), 'one row per upload, not per district');
        $this->assertSame(4, $page->viewData('batches')->count(), 'every district is still a batch of its own');
        $page->assertSee('Sowutuom #'.$batch->id)->assertSee('3 of 3 districts loaded')->assertSee('Show all 3');

        $page->call('toggle', $upload->id)->assertSee('Hide all 3');
        $this->assertTrue($page->get('open')[$upload->id]);
        $page->assertSeeInOrder(['Sowutuom', 'Odorkor', 'Kaneshie']);
    }

    public function test_the_history_filters_by_the_status_of_the_upload(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 3));
        [$imports, $parked] = $this->register($this->wide([['NOWHERE', '9001', 4, 6]]));
        $imports->process($parked->batches()->first());

        $page = Livewire::actingAs($this->officer())->test(Uploads::class)->set('status', 'needs_match');

        $this->assertSame([$parked->id], $page->viewData('uploads')->pluck('id')->all());
        $page->assertSee('Needs a match');
    }

    public function test_a_district_user_sees_their_districts_batch_and_never_another_districts_name_in_the_history_or_on_the_page(): void
    {
        $uploader = $this->userWith('910952', ['commercial.view_customer_analytics', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom, districtLimited: true);
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]));
        $imports->process($batch);
        $upload->forceFill(['imported_by' => $uploader->id])->save();

        $page = Livewire::actingAs($uploader)->test(Uploads::class);
        $this->assertSame([$batch->id], $page->viewData('batches')->pluck('id')->all());
        $page->assertSee('Sowutuom')->assertDontSee('Odorkor')->assertDontSee('Kaneshie');

        Livewire::actingAs($uploader)->test(UploadShow::class, ['upload' => $upload->fresh()])->assertSee('Sowutuom')->assertDontSee('Odorkor')->assertDontSee('Kaneshie');
    }

    public function test_a_district_user_cannot_open_an_upload_that_has_none_of_their_district(): void
    {
        $uploader = $this->userWith('910953', ['commercial.view_customer_analytics', 'commercial.upload_reports'], $this->accraWest, $this->kaneshie, districtLimited: true);
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 3]]));
        $imports->process($batch);

        $this->actingAs($uploader)->get(route('commercial.customers.file', $upload))->assertNotFound();
    }

    public function test_another_regions_upload_is_not_found_and_a_batch_links_to_its_upload(): void
    {
        $this->loadTwoRegions();
        $ashantiUpload = CommercialCustomerBatch::query()->where('district_id', $this->kumasi->id)->firstOrFail()->upload;
        $westBatch = CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->firstOrFail();
        $west = $this->officer('910954', $this->accraWest, $this->sowutuom);

        $this->actingAs($west)->get(route('commercial.customers.file', $ashantiUpload))->assertNotFound();
        $this->actingAs($west)->get(route('commercial.customers.file', $westBatch->upload))->assertOk();

        Livewire::actingAs($west)->test(BatchDetail::class, ['batch' => $westBatch])->assertSee('upload #'.$westBatch->upload_id);
    }

    // ---------------------------------------------------------------- undo

    public function test_undo_this_upload_undoes_every_district_that_is_still_the_newest_and_each_district_can_be_undone_alone(): void
    {
        [$imports, $first, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9], ['KANESHIE', '3001', 10, 12]]));
        $imports->process($batch);
        $this->assertSame(12, $this->customerCount());
        $admin = $this->superAdmin();

        // one district alone, from its own batch page
        $kaneshie = $first->batches()->where('district_id', $this->kaneshie->id)->firstOrFail();
        Livewire::actingAs($admin)->test(BatchDetail::class, ['batch' => $kaneshie])->call('startVoid')->set('voidReason', 'wrong district')->call('voidBatch');
        $this->assertSame(CommercialCustomerBatch::STATUS_VOIDED, $kaneshie->fresh()->status);
        $this->assertSame(0, $this->customerCount($this->kaneshie->id));
        $this->assertSame(9, $this->customerCount());

        // now the whole upload: Kaneshie is already undone, the other two are put back
        Livewire::actingAs($admin)->test(UploadShow::class, ['upload' => $first->fresh()])->call('startVoid')->set('voidReason', 'loaded the wrong file')->call('voidUpload');

        $first->refresh();
        $this->assertSame(CommercialCustomerUpload::STATUS_VOIDED, $first->status);
        $this->assertSame(0, $this->customerCount());
        $this->assertSame([CommercialCustomerBatch::STATUS_VOIDED, CommercialCustomerBatch::STATUS_VOIDED, CommercialCustomerBatch::STATUS_VOIDED], $first->batches()->pluck('status')->all());
    }

    public function test_a_district_that_has_had_a_newer_file_is_left_when_the_upload_is_undone(): void
    {
        [$imports, $first, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 5], ['ODORKOR', '2001', 6, 9]]));
        $imports->process($batch);
        $this->loadCustomers($this->simpleSpec(6, 10, ['district' => 'ODORKOR'], '2001'), '2026-11-05');   // a newer Odorkor file

        $lifecycle = app(CustomerBatchLifecycle::class);
        $result = $lifecycle->voidUpload($first->fresh(), $this->superAdmin(), 'undo');

        $this->assertCount(1, $result['voided']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('newest upload of a district', array_values($result['skipped'])[0]);
        $this->assertSame(0, $this->customerCount($this->sowutuom->id), 'Sowutuom was still the newest of its district: undone');
        $this->assertSame(5, $this->customerCount($this->odorkor->id), 'Odorkor has newer data: left as it is');
        // the upload's status follows its batches that still stand: the undone district no longer counts, the other is loaded
        $this->assertSame(CommercialCustomerUpload::STATUS_IMPORTED, $first->fresh()->status);
        $this->assertSame([CommercialCustomerBatch::STATUS_VOIDED, CommercialCustomerBatch::STATUS_IMPORTED], $first->batches()->pluck('status')->all());
    }

    public function test_undoing_needs_the_void_permission(): void
    {
        [$imports, $upload, $batch] = $this->register($this->wide([['SOWUTUOM', '1001', 1, 3]]));
        $imports->process($batch);

        $uploader = $this->userWith('910955', ['commercial.upload_reports'], $this->accraWest, $this->sowutuom);
        Livewire::actingAs($uploader)->test(UploadShow::class, ['upload' => $upload->fresh()])->call('startVoid')->assertForbidden();
        $this->assertSame(3, $this->customerCount());
    }
}
