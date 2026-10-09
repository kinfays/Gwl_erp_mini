<?php

namespace Tests\Feature\Commercial\Customers;

use App\Jobs\Commercial\ProcessCustomerListBatch;
use App\Jobs\Commercial\VoidCustomerListBatch;
use App\Models\AuditLog;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerDistrictBusy;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Commercial\ReportWorkbooks;

/** Upload, queue, resume and memory behaviour of the customer-list import. */
class CustomerPipelineTest extends CustomerTestCase
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function upload(string $path, array $extra = [], string $name = 'customers.xlsx'): \Illuminate\Testing\TestResponse
    {
        $file = new UploadedFile($path, $name, self::XLSX, null, true);

        return $this->post(route('commercial.customers.upload'), $extra + ['file' => $file, 'period_type' => 'monthly', 'as_of_date' => '2026-10-05']);
    }

    protected function workbook(array $spec): string
    {
        $path = ReportWorkbooks::customerList($spec);
        $this->workbooks[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------- the upload form

    public function test_an_officer_uploads_a_file_and_it_is_read_in_the_background(): void
    {
        $response = $this->actingAs($this->officer())->upload($this->workbook($this->simpleSpec(1, 6)));

        $batch = CommercialCustomerBatch::query()->firstOrFail();
        $response->assertRedirect(route('commercial.customers.batch', $batch));

        // the test queue runs jobs inline, so by now the batch is done
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->fresh()->status);
        $this->assertSame(6, $this->customerCount());
        $this->assertNull($batch->fresh()->file_path, 'the uploaded workbook (personal data) is deleted once the import is finished');
        $this->assertSame([], Storage::disk('local')->allFiles('commercial/customer-imports'));
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_batch_uploaded')->count());
    }

    public function test_the_job_carries_only_the_batch_id(): void
    {
        Bus::fake([ProcessCustomerListBatch::class]);

        $this->actingAs($this->officer())->upload($this->workbook($this->simpleSpec(1, 3)), [], 'Sowutuom customers June.xlsx');

        $batch = CommercialCustomerBatch::query()->firstOrFail();
        Bus::assertDispatched(ProcessCustomerListBatch::class, function (ProcessCustomerListBatch $job) use ($batch): bool {
            $this->assertSame(['batchId' => $batch->id], get_object_vars($job) === [] ? [] : array_intersect_key(get_object_vars($job), ['batchId' => 1]));
            $this->assertStringNotContainsString('Sowutuom', serialize($job));

            return $job->batchId === $batch->id;
        });
        $this->assertSame(CommercialCustomerBatch::STATUS_QUEUED, $batch->status);
    }

    public function test_the_same_file_twice_is_refused_politely_and_does_nothing_twice(): void
    {
        $officer = $this->officer();
        $path = $this->workbook($this->simpleSpec(1, 4));

        $this->actingAs($officer)->upload($path);
        $second = $this->actingAs($officer)->upload($path);

        $second->assertSessionHas('status');
        $this->assertStringContainsString('already uploaded', session('status'));
        $this->assertSame(1, CommercialCustomerBatch::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_batch_uploaded')->count());
    }

    public function test_bad_uploads_are_refused(): void
    {
        $officer = $this->officer();
        $path = $this->workbook($this->simpleSpec(1, 3));

        $this->actingAs($officer)->upload($path, ['period_type' => 'daily'])->assertSessionHasErrors('period_type');
        $this->actingAs($officer)->upload($path, ['as_of_date' => now()->addDays(5)->toDateString()])->assertSessionHasErrors('as_of_date');

        $text = tempnam(sys_get_temp_dir(), 'txt').'.xlsx';
        file_put_contents($text, 'not a workbook');
        $this->workbooks[] = $text;
        $response = $this->actingAs($officer)->upload($text);
        $this->assertTrue($response->getSession()->has('errors') || session()->has('error'), 'a text file named .xlsx is refused');

        $this->assertSame(0, CommercialCustomerBatch::query()->count());
    }

    public function test_only_people_who_may_upload_can_post_and_a_user_without_a_region_cannot(): void
    {
        $path = $this->workbook($this->simpleSpec(1, 3));

        $this->actingAs($this->analyst())->upload($path)->assertForbidden();
        $this->actingAs($this->userWith('900070', ['commercial.view_customer_analytics']))->upload($path)->assertForbidden();

        $loner = $this->userWithoutEmployee('900071', []);
        $role = \App\Models\Role::query()->create(['name' => 'uploader_no_region', 'display_name' => 'x', 'is_system' => false]);
        $role->permissions()->attach(\App\Models\Permission::query()->where('name', 'commercial.upload_reports')->pluck('id'));
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'commercial', 'can_access' => true]);
        $loner->roles()->attach($role);

        $this->actingAs($loner->fresh())->upload($path)->assertSessionHas('error');
        $this->assertSame(0, CommercialCustomerBatch::query()->count());
    }

    public function test_a_regional_officer_cannot_load_another_regions_file(): void
    {
        $officer = $this->officer('900072', $this->accraWest, $this->sowutuom);

        $this->actingAs($officer)->upload($this->workbook($this->simpleSpec(101, 103, ['region' => 'ASHANTI', 'district' => 'KUMASI'])));

        $batch = CommercialCustomerBatch::query()->firstOrFail();
        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $batch->status);
        $this->assertStringContainsString('may not load', $batch->errors[0]['message']);
        $this->assertSame(0, $this->customerCount());
    }

    public function test_the_older_report_uploader_points_a_customer_list_to_the_right_screen_without_loading_it(): void
    {
        $path = $this->workbook($this->simpleSpec(1, 3));
        $preview = app(\App\Services\Commercial\CommercialImportService::class)->preview(UploadedFile::fake()->createWithContent('customers.xlsx', (string) file_get_contents($path)));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('Customer uploads', $preview['errors'][0]['message']);

        config(['gwl.commercial_customer_list_enabled' => false]);
        $off = app(\App\Services\Commercial\CommercialImportService::class)->preview(UploadedFile::fake()->createWithContent('customers.xlsx', (string) file_get_contents($path)));
        $this->assertStringContainsString('not one of the supported reports', $off['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- queue behaviour

    public function test_a_second_batch_for_a_district_being_processed_waits_its_turn(): void
    {
        $path = $this->workbook($this->simpleSpec(1, 3));
        $imports = app(CustomerImportService::class);
        ['batch' => $batch] = $imports->register($path, 'a.xlsx', 'monthly', Carbon::parse('2026-10-05'), null);

        $lock = Cache::lock('commercial-customers:district:'.$this->sowutuom->id, 60);
        $this->assertTrue($lock->get());

        try {
            $imports->process($batch);
            $this->fail('the district is busy: process() must say so');
        } catch (CustomerDistrictBusy) {
            $this->assertSame(CommercialCustomerBatch::STATUS_QUEUED, $batch->fresh()->status);
            $this->assertSame(0, $this->customerCount());
        } finally {
            $lock->release();
        }

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $imports->process($batch->fresh())->status);
    }

    public function test_a_job_killed_while_reading_the_file_carries_on_where_it_stopped(): void
    {
        $path = ReportWorkbooks::customerListStreamed(4500);
        $this->workbooks[] = $path;
        $imports = app(CustomerImportService::class);
        ['batch' => $batch] = $imports->register($path, 'big.xlsx', 'monthly', Carbon::parse('2026-10-05'), null);

        // The second staging insert dies (as a worker killed mid-chunk would): its chunk is rolled back, the first stays.
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (stripos($query->sql, 'INSERT INTO commercial_customer_staging') === 0) {
                if (++$inserts === 4) {
                    throw new \RuntimeException('worker killed');
                }
            }
        });

        try {
            $imports->process($batch);
            $this->fail('the simulated crash should have stopped the import');
        } catch (\Throwable $e) {
            $this->assertSame(CommercialCustomerBatch::STATUS_FAILED, $batch->fresh()->status);
        }

        $partial = $batch->fresh();
        $this->assertGreaterThan(0, $partial->staged_through_row, 'the first chunk was committed');
        $this->assertLessThan(4500 + 40, $partial->staged_through_row);
        $stagedBefore = (int) DB::table('commercial_customer_staging')->count();
        $this->assertGreaterThan(0, $stagedBefore);
        $this->assertLessThan(4500, $stagedBefore);

        $inserts = -1000;   // the crash is over
        $done = $imports->process($batch->fresh());

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $done->status, json_encode([$done->errors, $done->error_message]));
        $this->assertSame(4500, $done->rows_read);
        $this->assertSame(4500, $done->rows_new);
        $this->assertSame(4500, $this->customerCount());
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());
        $this->assertSame(4500, DB::table('commercial_customers')->distinct()->count('account_no'), 'no account twice');
    }

    public function test_a_job_killed_while_merging_does_not_repeat_a_chunk(): void
    {
        config(['gwl.commercial_customer_merge_chunk' => 1000]);
        $path = ReportWorkbooks::customerListStreamed(3500);
        $this->workbooks[] = $path;
        $imports = app(CustomerImportService::class);
        ['batch' => $batch] = $imports->register($path, 'merge.xlsx', 'monthly', Carbon::parse('2026-10-05'), null);

        $diffs = 0;
        DB::listen(function ($query) use (&$diffs): void {
            if (str_contains($query->sql, 'insert into "commercial_customer_diff"') || str_contains($query->sql, 'INSERT INTO commercial_customer_diff')) {
                if (++$diffs === 3) {
                    throw new \RuntimeException('worker killed in the third chunk');
                }
            }
        });

        try {
            $imports->process($batch);
            $this->fail('the simulated crash should have stopped the import');
        } catch (\Throwable) {
        }

        $mid = $batch->fresh();
        $this->assertSame(CommercialCustomerBatch::STATUS_FAILED, $mid->status);
        $this->assertSame(CommercialCustomerBatch::PHASE_MERGE, $mid->phase, 'it knows it stopped in the merge');
        $this->assertSame(2000, $this->customerCount(), 'two whole chunks were committed, the third rolled back');
        $this->assertSame(2000, $mid->rows_new);

        $diffs = -1000;
        $done = $imports->process($batch->fresh());

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $done->status);
        $this->assertSame(3500, $done->rows_new, 'each account counted once');
        $this->assertSame(3500, $this->customerCount());
        $this->assertSame(3500, DB::table('commercial_customer_contacts')->count());
    }

    public function test_voiding_runs_as_a_job_and_restores_the_customers(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $before = DB::table('commercial_customers')->orderBy('id')->get()->toJson();
        $second = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [...$this->customers(1, 4), ReportWorkbooks::customer(5, ['balance' => 5000.0]), ReportWorkbooks::customer(6)]]]], '2026-11-05');

        dispatch_sync(new VoidCustomerListBatch($second->id, $this->superAdmin()->id, 'loaded the wrong file'));

        $this->assertSame(CommercialCustomerBatch::STATUS_VOIDED, $second->fresh()->status);
        $this->assertSame($before, DB::table('commercial_customers')->orderBy('id')->get()->toJson());
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_batch_voided')->count());
    }

    public function test_a_file_that_moves_the_count_a_lot_carries_a_warning(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 100));
        $batch = $this->loadCustomers($this->simpleSpec(1, 40), '2026-11-05');

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status);
        $this->assertSame(100, $batch->previous_count);
        $this->assertEquals(-60.0, $batch->count_change_pct);
        $this->assertStringContainsString('moved by -60', collect($batch->warnings)->pluck('message')->implode(' '));
        $this->assertSame(60, $batch->rows_missing);
    }

    // ---------------------------------------------------------------- memory

    public function test_memory_does_not_grow_with_the_size_of_the_file(): void
    {
        $measure = function (int $rows, string $district): float {
            $path = ReportWorkbooks::customerListStreamed($rows, $district, 'ACCRA WEST', 400, $rows * 10);
            $this->workbooks[] = $path;
            $imports = app(CustomerImportService::class);
            ['batch' => $batch] = $imports->register($path, "{$rows}.xlsx", 'monthly', Carbon::parse('2026-10-05'), null);

            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $done = $imports->process($batch);
            $growth = (memory_get_peak_usage() - $before) / 1048576;

            $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $done->status, json_encode($done->errors));
            $this->assertSame($rows, $done->rows_read);

            return $growth;
        };

        $small = $measure(3000, 'SOWUTUOM');
        $large = $measure(12000, 'ODORKOR');

        $this->assertLessThan(48, $large, "a 12,000-row file grew memory by {$large} MB");
        $this->assertLessThan(max(12, $small * 2.0), $large, "memory must not follow the file size: {$small} MB for 3,000 rows, {$large} MB for 12,000");
    }
}
