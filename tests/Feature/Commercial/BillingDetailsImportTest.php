<?php

namespace Tests\Feature\Commercial;

use App\Http\Controllers\Commercial\CommercialBillingDetailsController;
use App\Jobs\Commercial\ProcessBillingDetailsBatch;
use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Services\Commercial\BatchLifecycleService;
use App\Services\Commercial\BatchResolutionService;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\BillingDetails\BillingDetailsParser;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use App\Services\Commercial\BillingDetails\BillingDetailsScan;
use App\Services\Commercial\CommercialImportException;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\ReadingRouteSampleFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8d: the Detailed Billing Report importer (design 8.39): recognised from the top of the sheet, the bill period read as any dates, a
 * streamed pass that keeps rollups and capped lists (never a customer), the reconciliation tolerance, duplicate accounts, a district user's
 * file, the review step, failures with a safe message, and the upload form that hands the file to the queue. THE SAMPLE (see
 * BillingDetailsSampleFile::sampleSpec) is the fixture; the arithmetic is done by hand in the comments. Invented data only.
 */
class BillingDetailsImportTest extends CommercialTestCase
{
    use BuildsBillingDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBillingDetails();
        config(['gwl.commercial_customer_list_enabled' => false]);
    }

    // ---------------------------------------------------------------- detection and the period

    public function test_the_report_is_recognised_from_the_top_of_its_sheet_and_the_other_reports_are_not_mistaken_for_it(): void
    {
        $parser = app(BillingDetailsParser::class);
        $path = ReportWorkbooks::billingDetails();
        $this->workbooks[] = $path;

        $found = $parser->detect($path);
        $this->assertNotNull($found);
        $this->assertSame('rptBillingDetails_ExP', $found['sheet']);
        $this->assertSame('01-September-2026 - 21-September-2026', $found['period']);
        $this->assertSame('ACCRA WEST', $found['region']);
        $this->assertSame([], $found['missing']);
        $this->assertContains('bill_status', $found['fields']);
        // the columns that hold a person (name, meter number) are never mapped, so they can never be read
        $this->assertNotContains('name', $found['fields']);
        $this->assertNotContains('meter_no', $found['fields']);

        foreach ([ReportWorkbooks::reading(), ReportWorkbooks::billing(), ReportWorkbooks::readingRoute(ReadingRouteSampleFile::sampleSpec())] as $other) {
            $this->workbooks[] = $other;
            $this->assertNull($parser->detect($other), 'another report is not a Detailed Billing Report');
        }
    }

    public function test_the_uploads_page_says_what_the_file_is_and_sends_it_to_the_background_form(): void
    {
        $this->actingAs($this->officer());
        $file = $this->asUpload(ReportWorkbooks::billingDetails(), 'rptBillingDetails_ExP.xlsx');
        $preview = $this->previewOf($file);

        $this->assertSame(CommercialImportBatch::TYPE_BILLING_DETAILS, $preview['report_type']);
        $this->assertTrue($preview['blocked'], 'the generic form never reads a 130 MB sheet');
        $this->assertSame('Accra West', $preview['region']['name']);
        $this->assertSame('2026-09-01', $preview['period']['from']);
        $this->assertSame('2026-09-21', $preview['period']['to']);
        $this->assertStringContainsString('Detailed billing report', $preview['errors'][0]['message']);
        $this->assertSame('range', $preview['details_report']['period_kind']);
        $this->assertSame(0, CommercialImportBatch::query()->count(), 'a preview writes nothing');

        // the other reports are still read by their own importers
        $summary = $this->previewOf($this->billingFile());
        $this->assertSame(CommercialImportBatch::TYPE_BILLING_SUMMARY, $summary['report_type']);
        $this->assertFalse($summary['blocked']);
    }

    public function test_the_bill_period_is_any_dates_and_a_month_name_alone_is_the_whole_month(): void
    {
        $service = app(BillingDetailsImportService::class);

        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $service->readPeriod('September-2026 - September-2026'));
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $service->readPeriod('September-2026'), 'a month name alone is the whole month');
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-14'], $service->readPeriod('01-September-2026 - 14-September-2026'));
        $this->assertSame(['from' => '2026-09-15', 'to' => '2026-09-15'], $service->readPeriod('15-September-2026'), 'a day');
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-09-30'], $service->readPeriod('August-2026 - September-2026'), 'a range of two months');
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $service->readPeriod('2026-09'));
        $this->assertNull($service->readPeriod('not a date'));
        $this->assertNull($service->readPeriod('30-September-2026 - 01-September-2026'), 'backwards');

        // and it is what the batch gets: a day, a part month and a whole month, in either filter layout
        foreach ([
            ['15-September-2026', '2026-09-15', '2026-09-15', 'day', 'rows'],
            ['01-September-2026 - 14-September-2026', '2026-09-01', '2026-09-14', 'range', 'block'],
            ['September-2026 - September-2026', '2026-09-01', '2026-09-30', 'month', 'rows'],
        ] as $i => [$text, $from, $to, $label, $layout]) {
            $batch = $this->registerBilling($this->spec([$this->route('SOWUTUOM 410'.($i + 1), 1 + $i * 10, 5 + $i * 10)], $text, ['filters' => $layout]));

            $this->assertSame([$from, $to], [$batch->period_from->toDateString(), $batch->period_to->toDateString()], $text);
            $this->assertSame($label, CommercialPeriods::label($from, $to));
        }

        // a period that cannot be read, or no region, is refused with a sentence
        foreach ([$this->spec([$this->route('SOWUTUOM 4101', 1, 3)], 'sometime in autumn'), $this->spec([$this->route('SOWUTUOM 4101', 1, 3)], 'September-2026', ['region' => 'NOWHERE LAND'])] as $bad) {
            try {
                $this->registerBilling($bad);
                $this->fail('the file should have been refused');
            } catch (CommercialImportException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------- the whole sample

    public function test_the_sample_imports_and_every_figure_is_what_the_hand_arithmetic_says(): void
    {
        $this->tolerant();
        $batch = $this->importBilling();

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);
        $this->assertSame('done', $batch->phase);
        $this->assertSame('2026-09-01', $batch->period_from->toDateString());
        $this->assertSame('2026-09-21', $batch->period_to->toDateString());
        $scan = $batch->control_totals['scan'];

        // 578 rows: 19 routes of 30 + the repeated account + route 0000 (4) + one route of an unknown district (3); UNBILLED 4, REGULAR 569, ESTIMATED 2, ESTIMATE 3
        $this->assertSame(578, $scan['customers']);
        $this->assertSame(21, $scan['routes']);
        $this->assertSame(4, $scan['districts'], 'three districts and the one nobody can place');
        $this->assertEquals(['REGULAR' => 569, 'ESTIMATED' => 2, 'ESTIMATE' => 3, 'UNBILLED' => 4], $scan['bill_status']);
        $this->assertSame(1, $scan['duplicates']);
        $this->assertSame(1, $scan['integrity']['components_off']);
        $this->assertSame(1, $scan['integrity']['negative_consume']);
        $this->assertSame(1, $scan['integrity']['bill_month_outside']);
        $this->assertSame(1, $scan['integrity']['tariff_mismatch']);

        // money: 565 ordinary rows x 57.20 + 230.80 (five estimated bills of 46.16) + 84.80 + 2.00 + 60.00 - 25.60 = 32,670.00; the rows are
        // paid in full except every fifth account (115 of them) and accounts 17 and 20, which paid nothing: paid 26,128.64, balance due 6,816.36
        $this->assertEquals(32670.0, $scan['month_total']);
        $this->assertEquals(26128.64, $scan['paid_this_month']);
        $this->assertEquals(6816.36, $scan['balance_due']);
        $this->assertSame(32670.0, $this->rollupSum($batch, 'month_total'));
        $this->assertSame(26128.64, $this->rollupSum($batch, 'paid_this_month'));
        $this->assertSame(578, (int) $batch->detailRollups()->sum('customers'));
        $this->assertSame(450.0, $this->rollupSum($batch, 'previous_balance'), '100 + 50 + 500 - 200');

        // the staging table is emptied, the stored workbook deleted, the audit row holds counts only
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count());
        Storage::disk('local')->assertMissing('commercial/billing-details/'.$batch->file_hash.'.xlsx');
        $audit = AuditLog::query()->where('action', 'commercial.batch_imported')->latest('id')->firstOrFail();
        $this->assertSame(578, $audit->metadata['customers']);
        $this->assertStringNotContainsString('Sample Customer', json_encode($audit->metadata));
        $this->assertStringNotContainsString('100000000001', json_encode($audit->metadata));

        // the routes went through the route master as canonical billing routes ("SOWUTUOM 0000" is the unassigned route)
        $this->assertTrue(CommercialRoute::query()->where('district_id', $this->sowutuom->id)->where('code', '4101')->where('source', 'billing')->exists());
        $this->assertTrue(CommercialRoute::query()->where('district_id', $this->sowutuom->id)->where('code', '0000')->exists());
    }

    public function test_names_addresses_phones_and_meter_numbers_are_never_stored_anywhere(): void
    {
        $this->tolerant();
        $batch = $this->importBilling();
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status);

        $found = [];

        foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
            $dump = json_encode(DB::table($table->name)->get()->all());

            foreach (['Sample Customer', 'SMPL-'] as $needle) {
                if (str_contains((string) $dump, $needle)) {
                    $found[] = $table->name.' holds "'.$needle.'"';
                }
            }
        }

        $this->assertSame([], $found, 'a customer name or a meter number reached the database');

        // the account numbers are on the capped lists only
        $this->assertGreaterThan(0, $batch->detailIssues()->count());
        $this->assertSame([], array_values(array_diff(Schema::getColumnListing('commercial_billing_detail_rollups'), array_merge(['id', 'batch_id', 'route_id', 'district_id', 'route_label_raw', 'district_label_raw', 'category_code', 'customer_status', 'meter_status', 'bill_status'], BillingDetailsScan::COLUMNS))), 'the rollup has no column for a person or an account');
    }

    // ---------------------------------------------------------------- reconciliation

    public function test_a_flagged_route_imports_and_a_large_failure_blocks(): void
    {
        // 1 route in 21 is 4.8% of the routes: over the default 2%, so the sample is BLOCKED by default and nothing is written
        $blocked = $this->importBilling();
        $this->assertSame(CommercialImportBatch::STATUS_BLOCKED, $blocked->status);
        $this->assertSame('blocked', $blocked->phase);
        $this->assertStringContainsString('disagree with their own totals row', (string) $blocked->error_message);
        $this->assertSame(0, $blocked->detailRollups()->count());
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->where('batch_id', $blocked->id)->count());

        // the limits are Settings: widened, "run it again" imports the same stored file and lists the route that disagrees
        $this->tolerant();
        $service = app(BillingDetailsImportService::class);
        $service->requeue($blocked);
        $batch = $service->process($blocked);

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);
        $this->assertFalse($batch->reconciliation_passed);
        $this->assertSame(1, $batch->control_totals['routes_failed']);
        $this->assertSame(21, $batch->control_totals['routes_checked']);
        $flagged = $batch->control_totals['flagged_routes'];
        $this->assertCount(1, $flagged);
        $this->assertSame('ODORKOR 4202', $flagged[0]['label']);
        // the totals row printed 31 customers against 30 rows, and a month total 57.20 higher than the rows'
        $this->assertSame([30, 31], $flagged[0]['diffs']['count']);
        $this->assertSame([171600, 177320], $flagged[0]['diffs']['month_total'], 'in pesewas: 30 x 57.20 on the rows, 57.20 more on the totals row');
        $this->assertStringContainsString('ODORKOR 4202', implode(' ', $batch->control_totals['warnings']));

        // a LARGE failure blocks even with the wide limit: five of twenty routes disagree
        $routes = [];

        for ($r = 1; $r <= 20; $r++) {
            $route = $this->route('SOWUTUOM '.(4100 + $r), $r * 100, $r * 100 + 9);
            $route['totals'] = $r <= 5 ? ['count' => 11] : [];
            $routes[] = $route;
        }

        $large = $this->importBilling($this->spec($routes));
        $this->assertSame(CommercialImportBatch::STATUS_BLOCKED, $large->status);
        $this->assertStringContainsString('5 of 20 routes (25.0%)', (string) $large->error_message);
        $this->assertSame(0, $large->detailRollups()->count());
        $this->assertFalse($large->isLive(), 'a blocked file never counts');
        $this->assertSame([$batch->id], CommercialImportBatch::query()->ofType('billing_details')->live()->pluck('id')->all(), 'only the sample that was loaded earlier');
    }

    public function test_a_grand_total_beyond_the_tolerance_blocks_a_file(): void
    {
        $this->tolerant();
        // one route of ten customers whose totals row says a month total of 600 against the rows' 572: 4.9% off, beyond the 1% grand tolerance
        $route = $this->route('SOWUTUOM 4101', 1, 10);
        $route['totals'] = ['month_total' => 600.0];
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 100.0]);

        $batch = $this->importBilling($this->spec([$route]));

        $this->assertSame(CommercialImportBatch::STATUS_BLOCKED, $batch->status);
        $this->assertStringContainsString('Month total', (string) $batch->error_message);
    }

    public function test_a_duplicate_account_is_counted_both_rows_kept_and_listed_once(): void
    {
        $this->tolerant();
        $batch = $this->importBilling();

        $this->assertSame(1, $batch->control_totals['scan']['duplicates']);
        $this->assertSame(578, (int) $batch->detailRollups()->sum('customers'), 'both rows count, as the route\'s own totals row counts them');
        $list = $batch->detailIssues()->where('kind', 'duplicate_account')->get();
        $this->assertCount(1, $list);
        $this->assertSame('100000000021', $list[0]->account_no);
        $this->assertSame('AMASAMAN 4305', $list[0]->route_label_raw, 'the second row sits in the last route of the third district');
        $this->assertSame(57.2, (float) $list[0]->amount);
    }

    // ---------------------------------------------------------------- a district user, the review step

    public function test_a_district_users_file_keeps_only_their_districts_routes_and_the_rest_are_never_stored(): void
    {
        $this->tolerant();
        $batch = $this->importBilling([], ['districts' => [$this->sowutuom->id]]);

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);
        // Sowutuom: eight routes of 30 and route 0000 (4) = 244; the other districts' 334 rows were skipped (the repeated account is Amasaman's)
        $scan = $batch->control_totals['scan'];
        $this->assertSame(244, $scan['customers']);
        $this->assertSame(334, $scan['skipped_rows']);
        $this->assertSame(244, (int) $batch->detailRollups()->sum('customers'));
        $this->assertSame([$this->sowutuom->id], $batch->detailRollups()->distinct()->pluck('district_id')->all(), 'no other district, and no row with no district');
        $this->assertSame(0, $batch->detailRollups()->whereNull('district_id')->count());
        $this->assertSame([], $batch->detailIssues()->where('district_id', '!=', $this->sowutuom->id)->pluck('id')->all());
        $this->assertStringContainsString('not yours', implode(' ', $batch->control_totals['warnings']));
        // routes of another district were not even filed
        $this->assertFalse(CommercialRoute::query()->where('district_id', $this->odorkor->id)->exists());
        $this->assertFalse(CommercialRoute::query()->where('district_id', $this->amasaman->id)->exists());

        // a file with none of their routes is blocked with a sentence
        $none = $this->importBilling($this->spec([$this->route('ODORKOR 4201', 700, 709)]), ['districts' => [$this->sowutuom->id]]);
        $this->assertSame(CommercialImportBatch::STATUS_BLOCKED, $none->status);
        $this->assertStringContainsString('your district', (string) $none->error_message);
    }

    public function test_the_review_step_holds_the_figures_back_until_they_are_loaded_and_a_discard_removes_everything(): void
    {
        $this->tolerant();
        $service = app(BillingDetailsImportService::class);
        $batch = $this->importBilling([], ['review' => true]);

        $this->assertSame(CommercialImportBatch::STATUS_AWAITING_REVIEW, $batch->status);
        $this->assertFalse($batch->isLive());
        $this->assertSame(0, CommercialImportBatch::query()->live()->count());
        $this->assertGreaterThan(0, $batch->detailRollups()->count(), 'the figures are written, but nobody can see them yet');
        $this->assertGreaterThan(0, DB::table('commercial_billing_detail_accounts')->where('batch_id', $batch->id)->count());
        $this->actingAs($this->officer());
        $this->assertFalse(app(CommercialReportData::class)->billingDetails()['available']);

        // it cannot be voided (it never counted); it can be discarded, and the file can be uploaded again
        try {
            app(BatchLifecycleService::class)->void($batch, 'not wanted', 1);
            $this->fail('a run that never went live cannot be voided');
        } catch (CommercialImportException $e) {
            $this->assertStringContainsString('Discard', $e->getMessage());
        }

        $service->discard($batch, 1);
        $this->assertSame(0, CommercialImportBatch::query()->count());
        $this->assertSame(0, DB::table('commercial_billing_detail_rollups')->count());
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.billing_details_discarded')->count());

        $again = $this->importBilling([], ['review' => true]);
        $loaded = $service->confirm($again);

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $loaded->status);
        $this->assertTrue(app(CommercialReportData::class)->billingDetails()['available']);
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count(), 'the staging table is emptied when the batch goes live');

        // a loaded batch is voided, and then it no longer counts in anything
        app(BatchLifecycleService::class)->void($loaded, 'loaded by mistake', 1);
        $this->assertFalse(app(CommercialReportData::class)->billingDetails()['available']);
    }

    // ---------------------------------------------------------------- unknown districts

    public function test_a_district_nobody_can_place_is_imported_unplaced_and_resolving_it_places_every_figure(): void
    {
        $this->tolerant();
        $batch = $this->importBilling();

        $unplaced = $batch->detailRollups()->whereNull('route_id')->get();
        $this->assertSame(['ATLANTIS 9901'], $unplaced->pluck('route_label_raw')->unique()->values()->all());
        $this->assertSame(3, (int) $unplaced->sum('customers'));
        $this->assertStringContainsString('ATLANTIS', implode(' ', $batch->control_totals['warnings']));
        $this->assertSame(3, (int) $batch->detailBands()->where('district_key', 'l:ATLANTIS')->sum('customers'));

        // a district user's analyses leave it out (it is nobody's district yet); resolving it through the existing alias flow places everything
        $placed = app(BatchResolutionService::class)->resolveDistrict($batch, 'ATLANTIS', $this->odorkor);
        $this->assertSame(1, $placed);

        $this->assertSame(0, $batch->detailRollups()->whereNull('route_id')->count());
        $this->assertSame(3, (int) $batch->detailRollups()->where('route_label_raw', 'ATLANTIS 9901')->where('district_id', $this->odorkor->id)->sum('customers'));
        $this->assertSame(0, $batch->detailBands()->where('district_key', 'l:ATLANTIS')->count());
        // Odorkor's own 180 billed customers (n = 241 to 420) and the three that were unplaced, all in the 1-10 band
        $this->assertSame(183, (int) $batch->detailBands()->where('district_id', $this->odorkor->id)->sum('customers'));
        $this->assertTrue(CommercialRoute::query()->where('district_id', $this->odorkor->id)->where('code', '9901')->exists());
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.alias_saved')->count());
    }

    // ---------------------------------------------------------------- failures

    public function test_a_failed_run_leaves_the_batch_failed_with_a_safe_message_and_can_be_run_again(): void
    {
        $service = app(BillingDetailsImportService::class);
        $this->tolerant();
        $batch = $this->registerBilling();
        $content = Storage::disk('local')->get($batch->file_path);
        Storage::disk('local')->delete($batch->file_path);

        try {
            $service->process($batch);
            $this->fail('the run should have failed');
        } catch (CommercialImportException $e) {
            $this->assertStringContainsString('no longer there', $e->getMessage());
        }

        $failed = $batch->fresh();
        $this->assertSame(CommercialImportBatch::STATUS_FAILED, $failed->status);
        $this->assertSame('failed', $failed->phase);
        $this->assertNotEmpty($failed->error_message);
        $this->assertFalse($failed->isLive());

        // the file comes back (an administrator restored it): run again
        Storage::disk('local')->put($failed->file_path, $content);
        $service->requeue($failed);
        $done = $service->process($failed);

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $done->status, (string) $done->error_message);
        $this->assertNull($done->error_message);
    }

    public function test_a_failure_message_never_carries_a_statement_or_an_account_number(): void
    {
        $service = app(BillingDetailsImportService::class);
        $query = new QueryException('mysql', 'insert into commercial_billing_detail_issues (account_no) values (?)', ['100000000001'], new \PDOException('SQLSTATE[23000]: Integrity constraint violation for 100000000001'));
        $query->errorInfo = ['23000', 1062, 'Duplicate entry 100000000001'];

        $message = $service->safeMessage($query);
        $this->assertStringContainsString('23000', $message);
        $this->assertStringNotContainsString('100000000001', $message);
        $this->assertStringNotContainsString('insert', $message);

        $other = $service->safeMessage(new \RuntimeException('secret 100000000001 customer name'));
        $this->assertStringNotContainsString('100000000001', $other);
        $this->assertStringContainsString('RuntimeException', $other);
        $this->assertSame('Our own sentence.', $service->safeMessage(new CommercialImportException('Our own sentence.')));
    }

    // ---------------------------------------------------------------- the form and the queue

    public function test_the_upload_form_stores_the_file_and_hands_it_to_the_queue_without_reading_it(): void
    {
        Queue::fake();
        $this->tolerant();
        $officer = $this->officer();
        $path = ReportWorkbooks::billingDetails();
        $this->workbooks[] = $path;

        $response = $this->actingAs($officer)->post(route('commercial.batches.billing-details'), ['file' => ReportWorkbooks::upload($path, 'rptBillingDetails_ExP.xlsx'), 'review' => '1', 'notes' => 'September']);

        $batch = CommercialImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('commercial.batches.show', $batch));
        $this->assertSame(CommercialImportBatch::STATUS_QUEUED, $batch->status);
        $this->assertTrue($batch->review);
        $this->assertSame($officer->id, (int) $batch->imported_by);
        $this->assertSame('September', $batch->notes);
        $this->assertSame(0, $batch->detailRollups()->count(), 'nothing was read in the request');
        Queue::assertPushed(ProcessBillingDetailsBatch::class, fn ($job) => $job->batchId === $batch->id);

        // the same file again is the same batch, and nothing is queued twice
        $this->actingAs($officer)->post(route('commercial.batches.billing-details'), ['file' => ReportWorkbooks::upload($path, 'again.xlsx')])->assertRedirect(route('commercial.batches.show', $batch));
        $this->assertSame(1, CommercialImportBatch::query()->count());
        Queue::assertPushed(ProcessBillingDetailsBatch::class, 1);
    }

    public function test_the_job_runs_the_whole_import_and_the_batch_page_shows_it(): void
    {
        $this->tolerant();
        $officer = $this->officer();
        $path = ReportWorkbooks::billingDetails();
        $this->workbooks[] = $path;

        // the test queue is synchronous: the job runs inside the request
        $this->actingAs($officer)->post(route('commercial.batches.billing-details'), ['file' => ReportWorkbooks::upload($path, 'rptBillingDetails_ExP.xlsx')])->assertRedirect();

        $batch = CommercialImportBatch::query()->firstOrFail()->fresh();
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);

        $this->get(route('commercial.batches.show', $batch))->assertOk()->assertSee('Bill status of the customers')->assertSee('Districts in the file')->assertSee('ODORKOR 4202')->assertSee('32,670.00');
    }

    public function test_the_form_needs_upload_reports_a_file_and_a_region(): void
    {
        $path = ReportWorkbooks::billingDetails();
        $this->workbooks[] = $path;
        $viewer = $this->userWithRoles('900970', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);

        $this->actingAs($viewer)->post(route('commercial.batches.billing-details'), ['file' => ReportWorkbooks::upload($path, 'x.xlsx')])->assertForbidden();
        $this->actingAs($this->officer())->post(route('commercial.batches.billing-details'), [])->assertSessionHasErrors('file');
        $this->actingAs($this->userWithoutEmployee('900971', ['commercial_officer']))->post(route('commercial.batches.billing-details'), ['file' => ReportWorkbooks::upload($path, 'x.xlsx')])->assertSessionHas('error');
        $this->assertSame(0, CommercialImportBatch::query()->count());

        // a file of another region is refused for a regional officer
        $kumasi = $this->userWithRoles('900972', ['commercial_officer'], $this->ashanti, District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']));
        $this->actingAs($kumasi)->post(route('commercial.batches.billing-details'), ['file' => ReportWorkbooks::upload($path, 'x.xlsx')])->assertSessionHas('error');
        $this->assertSame(0, CommercialImportBatch::query()->count());
        $this->assertInstanceOf(CommercialBillingDetailsController::class, app(CommercialBillingDetailsController::class));
    }

    // ---------------------------------------------------------------- the file's own layout

    public function test_a_file_without_a_needed_column_is_blocked_by_name(): void
    {
        $path = ReportWorkbooks::billingDetails($this->spec([$this->route('SOWUTUOM 4101', 1, 3)]));
        $this->workbooks[] = $path;
        $this->assertNotNull(app(BillingDetailsParser::class)->detect($path));

        // a sheet whose header has no "Balance Due" (the report could not be this one) is not recognised at all
        $writer = new Writer;
        $broken = tempnam(sys_get_temp_dir(), 'bill').'.xlsx';
        $this->workbooks[] = $broken;
        $writer->openToFile($broken);
        $writer->addRow(Row::fromValues([null, "BILL PERIOD: September-2026\nREGION: ACCRA WEST\n"]));
        $writer->addRow(Row::fromValues([null, 'SOWUTUOM 4101']));
        $writer->addRow(Row::fromValues([null, 'Account #', 'Status', 'Bill Status', 'Month Total', 'Consume', 'Paid This Month', 'Previous Balance']));
        $writer->close();

        $this->assertNull(app(BillingDetailsParser::class)->detect($broken));
    }

    public function test_a_row_without_a_usable_account_number_is_counted_not_read(): void
    {
        $this->tolerant();
        $route = $this->route('SOWUTUOM 4101', 1, 4);
        $route['customers'][2]['account'] = 'not-an-account';
        $route['totals'] = ['count' => 4];
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 100.0, 'gwl.commercial_reconcile_grand_total_tolerance_pct' => 100.0]);

        $batch = $this->importBilling($this->spec([$route]));

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);
        $this->assertSame(3, $batch->control_totals['scan']['customers']);
        $this->assertSame(1, $batch->control_totals['scan']['malformed']);
        $this->assertTrue($batch->control_totals['routes_failed'] === 1, 'the route then disagrees with its own totals row (4 against 3): flagged, and the file still imports');
    }
}
