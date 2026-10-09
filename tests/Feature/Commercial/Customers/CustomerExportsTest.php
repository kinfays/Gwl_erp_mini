<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\AuditLog;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\ReportWorkbooks;

/** Customer exports: scope, personal data, caps, the background build and the hand-over. */
class CustomerExportsTest extends CustomerTestCase
{
    protected function listUrl(array $extra = []): string
    {
        return route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', 'district' => $this->sowutuom->id, ...$extra]);
    }

    /** @return list<list<mixed>> the first sheet's rows */
    protected function rows(TestResponse $response): array
    {
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());

        return $book->getSheet(0)->toArray(null, false, false, false);
    }

    public function test_the_summary_export_is_aggregates_only(): void
    {
        $this->loadTwoRegions();

        $response = $this->actingAs($this->analyst('900080', $this->accraWest, $this->sowutuom))->get(route('commercial.customers.export', ['report' => 'summary', 'format' => 'excel']));
        $response->assertOk();

        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(null, false, false, false), $book->getAllSheets()));

        $this->assertContains('Customer base', $book->getSheetNames());
        $this->assertContains('Meter health', $book->getSheetNames());
        $this->assertStringNotContainsString('Customer 0000', $text);
        $this->assertStringNotContainsString('example.test', $text);
        $this->assertStringNotContainsString('Kumasi', $text, 'a regional analyst\'s export has only their own region');
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_customer_summary_excel')->count());
    }

    public function test_an_export_needs_the_export_permission_as_well_as_the_view_permission(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));

        $viewOnly = $this->userWith('900081', ['commercial.view_customer_analytics']);
        $this->actingAs($viewOnly)->get($this->listUrl())->assertForbidden();
        $this->actingAs($this->userWith('900082', ['commercial.export_reports']))->get($this->listUrl())->assertForbidden();
    }

    public function test_a_list_export_leaves_out_personal_data_unless_the_user_holds_the_details_permission(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));

        $plain = $this->rows($this->actingAs($this->analyst())->get($this->listUrl())->assertOk());
        $this->assertSame(\App\Services\Commercial\Customers\CustomerExportService::LIST_HEADINGS, $plain[0]);
        $this->assertCount(6, $plain, 'a header and five customers');
        $this->assertStringNotContainsString('Customer 0000', json_encode($plain));
        $this->assertStringNotContainsString('0240000', json_encode($plain));

        $detailed = $this->rows($this->actingAs($this->detailer())->get($this->listUrl())->assertOk());
        $this->assertSame([...\App\Services\Commercial\Customers\CustomerExportService::LIST_HEADINGS, ...\App\Services\Commercial\Customers\CustomerExportService::PII_HEADINGS], $detailed[0]);

        $text = json_encode($detailed);
        $this->assertStringContainsString('Customer 000003', $text, 'names are included for a detailer');
        $this->assertStringContainsString('024****003', $text, 'but phone numbers are masked even then');
        $this->assertStringNotContainsString('0240000003', $text);
        $this->assertStringNotContainsString('c3@example.test', $text, 'and so are e-mail addresses');
        $this->assertStringContainsString('c***@example.test', $text);
    }

    public function test_a_list_export_is_region_scoped(): void
    {
        $this->loadTwoRegions();
        $west = $this->analyst('900083', $this->accraWest, $this->sowutuom);

        $this->actingAs($west)->get(route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', 'district' => $this->kumasi->id]))->assertNotFound();
        $this->actingAs($west)->get($this->listUrl())->assertOk();
    }

    public function test_a_name_that_looks_like_a_formula_is_stored_as_text(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['name' => '=HYPERLINK("http://x.test")', 'address' => '@SUM(1,1)'])]]]]);

        $response = $this->actingAs($this->detailer())->get($this->listUrl())->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);

        foreach (['Q2', 'R2'] as $cell) {
            $this->assertFalse($sheet->getCell($cell)->isFormula(), "{$cell} must not be a formula");
            $this->assertContains($sheet->getCell($cell)->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE], "{$cell} is text");
        }

        $this->assertSame('=HYPERLINK("http://x.test")', (string) $sheet->getCell('Q2')->getValue());
    }

    public function test_a_list_over_the_row_cap_is_refused_and_one_of_unknown_size_is_cut_off_with_a_note(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        config(['gwl.commercial_customer_export_max_rows' => 3]);
        $user = $this->analyst();

        // known size (the rollups say 5): refused, nothing built
        $this->actingAs($user)->get($this->listUrl())->assertSessionHas('error');
        $this->assertStringContainsString('over the export limit of 3', session('error'));
        $this->assertSame(0, AuditLog::query()->where('action', 'commercial.export_customer_list_excel')->count());

        // size not known up front (a bucket filter): built in the background, cut at the cap, with a note on the last row
        Notification::fake();
        $this->actingAs($user)->get($this->listUrl(['bucket' => 3]))->assertSessionHas('status');

        $audit = AuditLog::query()->where('action', 'commercial.export_customer_list_excel')->firstOrFail();
        $this->assertSame(3, $audit->metadata['rows']);
        $this->assertTrue($audit->metadata['truncated']);
        $this->assertTrue($audit->metadata['queued']);
    }

    public function test_a_big_list_is_built_in_the_background_and_handed_only_to_the_person_who_asked(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 8));
        config(['gwl.commercial_customer_export_queue_threshold' => 3]);
        $user = $this->analyst('900084');
        $other = $this->analyst('900085');

        $this->actingAs($user)->get($this->listUrl())->assertSessionHas('status');
        $this->assertStringContainsString('background', session('status'));

        Notification::assertSentTo($user, \App\Notifications\GeneralDatabaseNotification::class, function ($notification) use ($user, $other) {
            $data = $notification->toArray($user);
            $link = $data['url'] ?? $data['link'] ?? collect($data)->first(fn ($v) => is_string($v) && str_contains($v, '/commercial/customers/downloads/'));
            $this->assertNotNull($link, 'the notification carries the download link');

            $path = parse_url($link, PHP_URL_PATH);
            $this->actingAs($other)->get($path)->assertNotFound();
            $file = $this->actingAs($user)->get($path)->assertOk();
            $this->assertCount(9, $this->rowsOf($file), 'a header and eight customers');

            return true;
        });

        Notification::assertNotSentTo($other, \App\Notifications\GeneralDatabaseNotification::class);
    }

    /** @return list<list<mixed>> */
    protected function rowsOf($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $this->workbooks[] = $path;

        return IOFactory::load($path)->getSheet(0)->toArray(null, false, false, false);
    }
}
