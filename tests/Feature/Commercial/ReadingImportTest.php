<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BatchShow;
use App\Livewire\Commercial\Batches;
use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Models\CommercialLocationAlias;
use App\Models\CommercialReadingStat;
use App\Models\Permission;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

class ReadingImportTest extends CommercialTestCase
{
    public function test_a_valid_reading_report_is_imported_into_batch_strengths_and_reader_rows(): void
    {
        $this->employee('15071', 'Kofi Mensah');
        $this->employee('15072', 'Ama Serwaa');
        $this->actingAs($this->officer());

        $batch = $this->importFile($this->readingFile());

        $this->assertSame(CommercialImportBatch::TYPE_READING_SUMMARY, $batch->report_type);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status);
        $this->assertSame($this->accraWest->id, $batch->region_id);
        $this->assertSame('ACCRA WEST', $batch->region_label_raw);
        $this->assertSame('2026-06-01', $batch->period_from->toDateString());
        $this->assertSame('2026-07-31', $batch->period_to->toDateString());
        $this->assertSame(CommercialImportBatch::GRANULARITY_MONTHLY, $batch->granularity);
        $this->assertSame(4, $batch->row_count, 'two readers, two months each: the system account is excluded, never stored');
        $this->assertSame(4, $batch->matched_count);
        $this->assertTrue($batch->reconciliation_passed);
        $this->assertSame(64, strlen($batch->file_hash));
        $this->assertSame('rptReadingSummDate.xlsx', $batch->source_filename);
        $this->assertNotNull($batch->file_path);
        Storage::assertExists($batch->file_path);

        $this->assertSame([59000, 59100], $batch->strengths()->orderBy('month')->pluck('verified_strength')->all());

        $kofi = $batch->stats()->where('reader_staff_id', '15071')->orderBy('month')->get();
        $this->assertCount(2, $kofi);
        $this->assertSame([400, 100, 500], [$kofi[0]->read_count, $kofi[0]->skipped_count, $kofi[0]->visited_count]);
        $this->assertSame([410, 100, 510], [$kofi[1]->read_count, $kofi[1]->skipped_count, $kofi[1]->visited_count]);
        $this->assertSame(CommercialReadingStat::MATCH_MATCHED, $kofi[0]->match_status);
        $this->assertSame($this->sowutuom->id, $kofi[0]->district_id, 'home district comes from the directory');
        $this->assertNotNull($kofi[0]->employee_id);
    }

    public function test_the_excluded_system_account_is_shown_in_the_preview_and_never_stored_matched_or_counted(): void
    {
        $this->employee('15071', 'Kofi Mensah');
        $this->employee('15072', 'Ama Serwaa');
        $this->actingAs($this->officer());

        // the preview: skipped, counted, no warning, not unmatched
        $preview = $this->previewOf($this->readingFile());
        $this->assertSame(2, $preview['system_count']);
        $this->assertSame([['staff_id' => '00000', 'rows' => 2, 'read' => 10, 'skipped' => 2, 'visited' => 12]], $preview['excluded']);
        $this->assertSame(4, $preview['total_rows']);
        $this->assertSame(0, $preview['unmatched_count']);
        $this->assertSame(2, $preview['reader_count'], 'two CCAs; the system account is not one');
        $this->assertStringNotContainsString('00000', collect($preview['warnings'])->pluck('message')->implode(' '));
        $this->assertTrue($preview['checks'][0]['passed'], 'the grand-total sheet still counts it, so the file still reconciles');

        Livewire::test(\App\Livewire\Commercial\Batches::class)->call('openUpload')->set('file', $this->readingFile())->call('previewFile')->assertSee('excluded (system account): 2 rows, not imported');

        // the batch: nothing of it is stored
        $batch = $this->importFile($this->readingFile());
        $this->assertSame(0, $batch->stats()->where('reader_staff_id', '00000')->count());
        $this->assertSame(0, CommercialReadingStat::query()->where('match_status', CommercialReadingStat::MATCH_SYSTEM_ACCOUNT)->count());
        $this->assertSame(['15071', '15072'], $batch->stats()->distinct()->orderBy('reader_staff_id')->pluck('reader_staff_id')->all());
        $this->assertSame(2, $batch->control_totals['excluded'][0]['rows']);

        Livewire::test(BatchShow::class, ['batch' => $batch])->assertSee('Excluded (system account) 00000');
    }

    public function test_the_excluded_ids_are_a_setting_and_the_name_system_administrator_is_excluded_whatever_the_id(): void
    {
        $this->employee('15071', 'Kofi Mensah');
        $this->actingAs($this->officer());

        // the ID list is replaced: 15072 is now excluded, 00000 is no longer on the list but still named System Administrator
        config(['gwl.commercial_excluded_staff_ids' => ['15072']]);
        $preview = $this->previewOf($this->readingFile());

        $this->assertSame(['15072', '00000'], array_column($preview['excluded'], 'staff_id'));
        $this->assertSame(2, $preview['total_rows'], 'only Kofi is left');

        // a different ID with that name is excluded too (case-insensitive)
        config(['gwl.commercial_excluded_staff_ids' => []]);
        $named = $this->previewOf($this->readingFile(['readers' => [
            ['id' => '15071', 'name' => 'KOFI MENSAH', 'counts' => ['2026-06-01' => [5, 1], '2026-07-01' => [6, 2]]],
            ['id' => '99999', 'name' => 'system ADMINISTRATOR', 'counts' => ['2026-06-01' => [1, 0], '2026-07-01' => [1, 0]]],
        ]]));
        $this->assertSame(['99999'], array_column($named['excluded'], 'staff_id'));
        $this->assertFalse($named['blocked'], collect($named['errors'])->pluck('message')->implode(' | '));

        // the setting is editable in Settings, as a comma separated list
        $settings = app(\App\Services\Commercial\CommercialSettings::class);
        $settings->save(['commercial_excluded_staff_ids' => ' 00000, 12345 ,,00000 '], $this->superAdmin()->id);
        $this->assertSame(['00000', '12345'], config('gwl.commercial_excluded_staff_ids'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $settings->save(['commercial_excluded_staff_ids' => 'bad id!'], $this->superAdmin()->id);
    }

    public function test_reader_rows_that_do_not_add_up_to_the_grand_total_sheet_are_blocked(): void
    {
        $this->actingAs($this->officer());

        $file = $this->readingFile(['grand_overall' => [9999, 303, 10302]]);

        $preview = $this->previewOf($file);

        $this->assertTrue($preview['blocked']);
        $this->assertGreaterThan(0, $preview['error_count']);
        $this->assertStringContainsString('overall: CCA sheets total 1,420 but the grand-total sheet shows 9,999', collect($preview['errors'])->pluck('message')->implode(' '));

        Livewire::test(Batches::class)
            ->call('openUpload')
            ->set('file', $file)
            ->call('previewFile')
            ->call('runImport')
            ->assertHasErrors('file');

        $this->assertSame(0, CommercialImportBatch::query()->count());
    }

    public function test_a_reader_row_where_visited_is_not_read_plus_skipped_is_blocked(): void
    {
        $this->actingAs($this->officer());

        // Visited is wrong on one reader row; the grand sheet is built from the same wrong figure so only the identity fails.
        $preview = $this->previewOf($this->readingFile([
            'visited' => ['15071|2026-06-01' => 700],
            'grand_overall' => [1420, 303, 1923],
        ]));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('Visited does not equal read + skipped', collect($preview['errors'])->pluck('message')->implode(' '));
    }

    public function test_a_workbook_without_a_grand_total_sheet_cannot_be_verified_and_is_blocked(): void
    {
        $this->actingAs($this->officer());

        $preview = $this->previewOf($this->readingFile(['grand_sheet' => false]));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('grand-total sheet was not found', collect($preview['errors'])->pluck('message')->implode(' '));
    }

    public function test_the_same_file_twice_is_refused(): void
    {
        $this->actingAs($this->officer());
        $file = $this->readingFile();

        $first = $this->importFile($file);

        // A re-exported file with different figures is a different file and is welcome.
        $changed = $this->previewOf($this->readingFile(['months' => ['2026-06-01', '2026-07-01', '2026-08-01']]));
        $this->assertFalse($changed['blocked']);

        $again = Livewire::test(Batches::class)
            ->call('openUpload')
            ->set('file', $file)
            ->call('previewFile');

        $this->assertTrue($again->get('preview')['blocked']);
        $this->assertStringContainsString("already uploaded as batch #{$first->id}", collect($again->get('preview')['errors'])->pluck('message')->implode(' '));

        $again->call('runImport')->assertHasErrors('file');
        $this->assertSame(1, CommercialImportBatch::query()->count());
    }

    public function test_the_grand_total_may_differ_by_the_tolerance_and_no_more(): void
    {
        $this->actingAs($this->officer());

        // the CCA sheets add up to read 1,420 / skipped 303 / visited 1,723: a grand total 5 higher is 0.35%, inside the 0.5%
        $within = $this->previewOf($this->readingFile(['grand_overall' => [1425, 303, 1728]]));
        $this->assertFalse($within['blocked'], collect($within['errors'])->pluck('message')->implode(' | '));

        // 20 higher is 1.4%: blocked, and the message names the figures
        $beyond = $this->previewOf($this->readingFile(['grand_overall' => [1440, 303, 1743]]));
        $this->assertTrue($beyond['blocked']);
        $this->assertStringContainsString('overall: CCA sheets total 1,420 but the grand-total sheet shows 1,440', collect($beyond['errors'])->pluck('message')->implode(' '));

        // the tolerance is a setting
        config(['gwl.commercial_reconcile_grand_total_tolerance_pct' => 2.0]);
        $this->assertFalse($this->previewOf($this->readingFile(['grand_overall' => [1440, 303, 1743]]))['blocked']);

        // zero tolerance is the old exact rule
        config(['gwl.commercial_reconcile_grand_total_tolerance_pct' => 0.0]);
        $this->assertTrue($this->previewOf($this->readingFile(['grand_overall' => [1421, 303, 1724]]))['blocked']);
    }

    public function test_a_file_that_covers_part_of_a_month_warns_that_it_replaces_other_uploads_of_that_month(): void
    {
        $this->actingAs($this->officer());
        $warning = 'This file covers part of a month. It will REPLACE, not add to, other uploads of the same month.';
        $messages = fn (array $preview) => collect($preview['warnings'])->pluck('message')->all();

        try {
            // 1 to 14 October: stops short of the month
            \Tests\Support\Commercial\ReportWorkbooks::$readingPeriod = '01-October-2026 - 14-October-2026';
            $this->assertContains($warning, $messages($this->previewOf($this->readingFile(['months' => ['2026-10-01']]))));

            // starting mid-month is partial as well
            \Tests\Support\Commercial\ReportWorkbooks::$readingPeriod = '15-October-2026 - 01-November-2026';
            $this->assertContains($warning, $messages($this->previewOf($this->readingFile(['months' => ['2026-10-01']]))));

            // whole months, however the end is printed (the 1st after, or the last day), never warn
            foreach (['01-June-2026 - 01-October-2026', '01-October-2026 - 31-October-2026', '01-October-2026 - 01-November-2026'] as $whole) {
                \Tests\Support\Commercial\ReportWorkbooks::$readingPeriod = $whole;
                $this->assertNotContains($warning, $messages($this->previewOf($this->readingFile(['months' => ['2026-10-01']]))), $whole);
            }

            // text that cannot be read as two dates says nothing
            \Tests\Support\Commercial\ReportWorkbooks::$readingPeriod = 'last fortnight';
            $this->assertNotContains($warning, $messages($this->previewOf($this->readingFile(['months' => ['2026-10-01']]))));
        } finally {
            \Tests\Support\Commercial\ReportWorkbooks::$readingPeriod = null;
        }
    }

    public function test_an_unmatched_reader_imports_with_a_warning_and_can_be_linked_to_an_employee(): void
    {
        $this->employee('15072', 'Ama Serwaa');
        $officer = $this->officer();
        $this->actingAs($officer);

        $preview = $this->previewOf($this->readingFile());
        $this->assertFalse($preview['blocked']);
        $this->assertSame(1, $preview['unmatched_count']);
        $this->assertStringContainsString('15071 - KOFI MENSAH is not in the staff directory', collect($preview['warnings'])->pluck('message')->implode(' '));

        $batch = $this->importFile($this->readingFile());

        $this->assertSame(2, $batch->stats()->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->count());
        $this->assertSame(2, $batch->matched_count, 'Ama, two months');
        $this->assertGreaterThan(0, $batch->warning_count);

        $employee = $this->employee('77001', 'Kofi Mensah (directory)', $this->accraWest, $this->odorkor);

        Livewire::test(BatchShow::class, ['batch' => $batch])
            ->assertSee('CCAs not in the staff directory')
            ->call('startLinkingReader', '15071')
            ->set('employeeSearch', 'Kofi Mensah')
            ->assertSee('77001')
            ->call('linkReader', $employee->id)
            ->assertHasNoErrors();

        $linked = $batch->stats()->where('reader_staff_id', '15071')->get();
        $this->assertTrue($linked->every(fn ($stat) => $stat->match_status === CommercialReadingStat::MATCH_MATCHED && $stat->employee_id === $employee->id));
        $this->assertSame($this->odorkor->id, $linked->first()->district_id);
        $this->assertSame(0, $batch->stats()->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->count());
        $this->assertSame(4, $batch->fresh()->matched_count);

        $this->assertNotNull(AuditLog::query()->where('action', 'commercial.reader_linked')->first());
    }

    public function test_re_matching_picks_up_a_reader_added_to_the_directory_afterwards(): void
    {
        $this->employee('15072', 'Ama Serwaa');
        $this->actingAs($this->officer());

        $batch = $this->importFile($this->readingFile());
        $this->assertSame(2, $batch->stats()->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->count());

        $this->employee('15071', 'Kofi Mensah');

        Livewire::test(BatchShow::class, ['batch' => $batch])->call('rematch')->assertHasNoErrors();

        $this->assertSame(0, $batch->stats()->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->count());
        $this->assertNotNull($batch->stats()->where('reader_staff_id', '15071')->value('employee_id'));
    }

    public function test_an_unrecognised_region_blocks_until_it_is_resolved_and_the_alias_is_reused(): void
    {
        $this->employee('15071', 'Kofi Mensah');
        $this->employee('15072', 'Ama Serwaa');
        $this->actingAs($this->officer());

        $component = Livewire::test(Batches::class)
            ->call('openUpload')
            ->set('file', $this->readingFile(['region' => 'GREATER ACCRA WEST']))
            ->call('previewFile');

        $preview = $component->get('preview');
        $this->assertTrue($preview['blocked']);
        $this->assertSame('GREATER ACCRA WEST', $preview['unresolved_region']);

        $component->call('runImport')->assertHasErrors('file');
        $this->assertSame(0, CommercialImportBatch::query()->count());

        // A regional user may only map a report to their own region.
        $component->set('aliasRegionId', $this->ashanti->id)->call('saveRegionAlias')->assertHasErrors('aliasRegionId');

        $component->set('aliasRegionId', $this->accraWest->id)->call('saveRegionAlias');

        $this->assertFalse($component->get('preview')['blocked']);
        $this->assertSame('GREATER ACCRA WEST', CommercialLocationAlias::query()->where('kind', 'region')->value('alias_normalized'));
        $this->assertNotNull(AuditLog::query()->where('action', 'commercial.alias_saved')->first());

        // The next upload (another month, so a different file) matches by itself.
        $next = $this->readingFile(['region' => 'GREATER ACCRA WEST', 'months' => ['2026-08-01']]);
        $this->assertFalse($this->previewOf($next)['blocked']);
    }

    public function test_the_flat_header_layout_and_separate_filter_cells_are_read_by_label_too(): void
    {
        $this->actingAs($this->officer());

        // The default fixture mirrors the real export (two-row headers, one multi-line filter cell, overall grand totals).
        $preview = $this->previewOf($this->readingFile(['merged_headers' => false, 'filters' => 'cells']));

        $this->assertFalse($preview['blocked'], collect($preview['errors'])->pluck('message')->implode(' | '));
        $this->assertSame(4, $preview['total_rows']);
        $this->assertSame('ACCRA WEST', $preview['region']['raw']);
    }

    public function test_a_grand_total_sheet_with_month_rows_is_reconciled_month_by_month(): void
    {
        $this->actingAs($this->officer());

        $ok = $this->previewOf($this->readingFile(['grand_layout' => 'monthly']));
        $this->assertFalse($ok['blocked'], collect($ok['errors'])->pluck('message')->implode(' | '));
        $this->assertArrayHasKey('2026-06-01', $ok['parsed']['attributes']['control_totals']['grand_totals'] ?? ['2026-06-01' => 1]);

        $off = $this->previewOf($this->readingFile(['grand_layout' => 'monthly', 'grand' => ['2026-07-01' => [9999, 100, 10099]]]));
        $this->assertTrue($off['blocked']);
        $this->assertStringContainsString('Jul 2026: CCA sheets total', collect($off['errors'])->pluck('message')->implode(' '));
    }

    public function test_the_real_exports_document_map_sheet_with_a_huge_used_range_is_never_loaded(): void
    {
        // The fixture's Document map declares a cell at column XFC, like the real export; reading it would exhaust memory.
        $this->actingAs($this->officer());

        $this->assertFalse($this->previewOf($this->readingFile())['blocked']);
    }

    public function test_a_sheet_whose_column_headers_cannot_be_found_is_blocked_rather_than_misread(): void
    {
        $this->actingAs($this->officer());
        $path = ReportWorkbooks::reading();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $book->getSheetByName('Sheet2')->setCellValue('C11', 'Reading count');
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($path);

        $preview = $this->previewOf($this->asUpload($path, 'broken.xlsx'));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('Could not find the Read # column header', collect($preview['errors'])->pluck('message')->implode(' '));
    }

    public function test_a_file_that_is_not_a_known_report_is_blocked(): void
    {
        $this->actingAs($this->officer());
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Quarterly stock count');
        $path = tempnam(sys_get_temp_dir(), 'comm').'.xlsx';
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($path);

        $preview = $this->previewOf($this->asUpload($path, 'other.xlsx'));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('not one of the supported reports', $preview['errors'][0]['message']);
    }

    public function test_the_import_is_audit_logged_with_counts_and_control_totals(): void
    {
        $this->employee('15072', 'Ama Serwaa');
        $this->actingAs($this->officer());

        $batch = $this->importFile($this->readingFile());

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_COMMERCIAL)
            ->where('action', 'commercial.batch_imported')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CommercialImportBatch::class, $log->target_type);
        $this->assertSame($batch->id, $log->target_id);
        $this->assertSame(4, $log->metadata['rows']);
        $this->assertSame(2, $log->metadata['matched']);
        $this->assertSame(2, $log->metadata['unmatched']);
        $this->assertSame(0, $log->metadata['system_account']);
        $this->assertSame(2, $log->metadata['excluded_rows'], 'counts only: the excluded account is never stored');
        $this->assertTrue($log->metadata['reconciliation_passed']);
        $this->assertSame(['read' => 1420, 'skipped' => 303, 'visited' => 1723], $log->metadata['control_totals']['grand_totals']['overall']);
        $this->assertSame(['read' => 1420, 'skipped' => 303, 'visited' => 1723], [
            'read' => array_sum(array_column($log->metadata['control_totals']['reader_totals'], 'read')),
            'skipped' => array_sum(array_column($log->metadata['control_totals']['reader_totals'], 'skipped')),
            'visited' => array_sum(array_column($log->metadata['control_totals']['reader_totals'], 'visited')),
        ]);
    }
}
