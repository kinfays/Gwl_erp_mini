<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\EquipmentImport;
use App\Models\AuditLog;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\HsSite;
use App\Services\HealthSafety\EquipmentImportService;
use App\Services\HealthSafety\FireExtinguisherService;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class EquipmentImportTest extends HealthSafetyTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private const EXT = ['asset_code', 'serial_number', 'type', 'capacity', 'manufacturer', 'manufactured_on', 'site', 'district', 'location_detail', 'expiry_date', 'last_serviced_on', 'next_service_due', 'last_hydro_test_on', 'next_hydro_test_due', 'responsible_staff_id', 'notes'];

    /**
     * A small synthetic workbook: the heading row, then rows keyed by heading. DateTime values become real Excel dates;
     * strings stay text (so "08/10/2026" is a dd/MM/yyyy text cell), numbers stay numbers.
     *
     * @param  list<string>  $headings
     * @param  list<array<string, mixed>>  $rows
     */
    private function workbook(array $headings, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headings as $column => $heading) {
            $sheet->setCellValueExplicit([$column + 1, 1], $heading, DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($headings as $column => $heading) {
                $value = $row[$heading] ?? null;
                $coordinate = [$column + 1, $rowIndex + 2];

                if ($value === null) {
                    continue;
                }

                if ($value instanceof DateTimeInterface) {
                    $sheet->setCellValue($coordinate, Date::PHPToExcel($value));
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'hs_import_').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $this->files[] = $path;

        // A fake upload, so Livewire's test helpers accept it too.
        return UploadedFile::fake()->createWithContent('register.xlsx', file_get_contents($path));
    }

    private function extRow(array $overrides = []): array
    {
        return ['type' => 'water', 'site' => 'Sowutuom District Office', 'expiry_date' => '01/03/2030', ...$overrides];
    }

    private function service(): EquipmentImportService
    {
        return app(EquipmentImportService::class);
    }

    // ---------------------------------------------------------------- the happy path

    public function test_a_valid_file_is_read_and_imported_with_region_and_district_from_the_site(): void
    {
        $officer = $this->officer();
        $site = $this->site('Sowutuom District Office');
        $staff = $this->userWithRoles('300001', ['employee']);

        $file = $this->workbook(self::EXT, [
            $this->extRow(['asset_code' => 'OLD-001', 'serial_number' => 'SN1', 'type' => 'Dry Powder', 'capacity' => '9 kg', 'manufacturer' => 'Acme', 'location_detail' => 'Corridor', 'responsible_staff_id' => '300001', 'notes' => 'From the old register']),
            $this->extRow(['type' => 'CO2', 'expiry_date' => '15/06/2029', 'next_service_due' => '01/01/2027']),
            $this->extRow(['type' => 'foam']),
        ]);

        $preview = $this->service()->preview('extinguishers', $file, $officer);

        $this->assertSame(3, $preview['total_rows']);
        $this->assertSame(3, $preview['valid_count']);
        $this->assertSame(0, $preview['error_count']);
        $this->assertFalse($preview['blocked']);
        $this->assertSame(0, HsFireExtinguisher::query()->count(), 'a preview writes nothing');

        $result = $this->service()->import('extinguishers', $file, $officer);

        $this->assertSame(3, $result['created']);
        $this->assertSame(3, HsFireExtinguisher::query()->count());

        $first = HsFireExtinguisher::query()->where('asset_code', 'OLD-001')->firstOrFail();
        $this->assertSame('dry_powder', $first->extinguisher_type);
        $this->assertSame('SN1', $first->serial_number);
        $this->assertSame($site->id, $first->site_id);
        $this->assertSame($this->accraWest->id, $first->region_id);
        $this->assertSame($this->sowutuom->id, $first->district_id);
        $this->assertSame($staff->employee->id, $first->responsible_employee_id);
        $this->assertSame('2030-03-01', $first->expiry_date->toDateString());

        $co2 = HsFireExtinguisher::query()->where('extinguisher_type', 'co2')->firstOrFail();
        $this->assertSame('2029-06-15', $co2->expiry_date->toDateString());
        $this->assertMatchesRegularExpression('/^FE-/', $co2->asset_code, 'a blank code is generated');

        // One audit row for the whole import, with the counts; no per-row noise.
        $audit = AuditLog::query()->where('action', 'health_safety.equipment_imported')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(3, $audit->first()->metadata['created']);
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.extinguisher_saved')->count());
    }

    public function test_real_excel_dates_and_ddmmyyyy_text_both_read_and_the_two_agree(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $file = $this->workbook(self::EXT, [
            $this->extRow(['asset_code' => 'A', 'expiry_date' => new \DateTimeImmutable('2030-03-01')]),
            $this->extRow(['asset_code' => 'B', 'expiry_date' => '01/03/2030']),
            $this->extRow(['asset_code' => 'C', 'expiry_date' => '1/3/2030']),
            $this->extRow(['asset_code' => 'D', 'expiry_date' => '2030-03-01']),
            $this->extRow(['asset_code' => 'E', 'expiry_date' => '01-03-2030']),
        ]);

        $preview = $this->service()->preview('extinguishers', $file, $officer);

        $this->assertSame([], $preview['errors']);
        $this->assertSame(['2030-03-01'], collect($preview['valid_rows'])->pluck('expiry_date')->unique()->values()->all(), 'real dates and text dates give the same day');
    }

    public function test_an_ambiguous_or_unreadable_date_is_a_row_error(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $bad = ['13/13/2030', '03/04/30', 'October 8', '31/02/2030', 'next year', 5, '2030/03/01'];
        $rows = collect($bad)->map(fn ($value, $index) => $this->extRow(['asset_code' => 'X'.$index, 'expiry_date' => $value]))->all();

        $preview = $this->service()->preview('extinguishers', $this->workbook(self::EXT, $rows), $officer);

        $this->assertSame(count($bad), $preview['error_rows'], 'every one of them is rejected');
        $this->assertSame(0, $preview['valid_count']);
        $this->assertStringContainsString('expiry date', $preview['errors'][0]['message']);
    }

    public function test_a_future_last_service_or_check_date_is_rejected(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $preview = $this->service()->preview('extinguishers', $this->workbook(self::EXT, [
            $this->extRow(['last_serviced_on' => today()->addDays(3)->format('d/m/Y')]),
        ]), $officer);

        $this->assertSame(1, $preview['error_rows']);
        $this->assertStringContainsString('cannot be in the future', $preview['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- the threshold

    public function test_the_run_is_blocked_above_the_failure_threshold_and_nothing_is_written(): void
    {
        config(['gwl.max_import_failure_percent' => 20]);
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $file = $this->workbook(self::EXT, [
            $this->extRow(), $this->extRow(), $this->extRow(),
            $this->extRow(['type' => 'bogus']), $this->extRow(['site' => 'Nowhere']),
        ]);

        $preview = $this->service()->preview('extinguishers', $file, $officer);

        $this->assertSame(2, $preview['error_rows']);
        $this->assertSame(40.0, $preview['failure_percent']);
        $this->assertTrue($preview['blocked']);

        try {
            $this->service()->import('extinguishers', $file, $officer);
            $this->fail('A blocked import must not run.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('40', $exception->errors()['file'][0]);
        }

        $this->assertSame(0, HsFireExtinguisher::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.equipment_imported')->count());
    }

    public function test_below_the_threshold_the_usable_rows_are_imported_and_the_bad_ones_left_out(): void
    {
        config(['gwl.max_import_failure_percent' => 20]);
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $rows = array_fill(0, 9, $this->extRow());
        $rows[] = $this->extRow(['type' => 'bogus']);

        $result = $this->service()->import('extinguishers', $this->workbook(self::EXT, $rows), $officer);

        $this->assertSame(9, $result['created']);
        $this->assertSame(1, $result['error_rows']);
        $this->assertSame(9, HsFireExtinguisher::query()->count());
    }

    public function test_the_threshold_comes_from_config(): void
    {
        config(['gwl.max_import_failure_percent' => 50]);
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $preview = $this->service()->preview('extinguishers', $this->workbook(self::EXT, [$this->extRow(), $this->extRow(['type' => 'bogus'])]), $officer);

        $this->assertSame(50.0, $preview['failure_percent']);
        $this->assertFalse($preview['blocked'], '50% is not above a 50% limit');
    }

    // ---------------------------------------------------------------- create-only and sites

    public function test_an_existing_asset_code_is_skipped_with_a_warning_and_never_updated(): void
    {
        $officer = $this->officer();
        $site = $this->site('Sowutuom District Office');
        $existing = $this->unit(['asset_code' => 'KEEP-1', 'capacity' => 'original'], $site);

        $file = $this->workbook(self::EXT, [
            $this->extRow(['asset_code' => 'keep-1', 'capacity' => 'changed by import']),
            $this->extRow(['asset_code' => 'NEW-1']),
        ]);

        $preview = $this->service()->preview('extinguishers', $file, $officer);

        $this->assertSame(1, $preview['skipped_count']);
        $this->assertSame(0, $preview['error_rows'], 'a skip is not a failure');
        $this->assertStringContainsString('already exists', collect($preview['warnings'])->pluck('message')->implode(' '));

        $result = $this->service()->import('extinguishers', $file, $officer);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('original', $existing->fresh()->capacity, 'the existing record is untouched');
        $this->assertSame(2, HsFireExtinguisher::query()->count());
    }

    public function test_a_file_that_is_all_existing_codes_imports_nothing_but_is_not_blocked(): void
    {
        $officer = $this->officer();
        $site = $this->site('Sowutuom District Office');
        $this->unit(['asset_code' => 'A'], $site);

        $file = $this->workbook(self::EXT, [$this->extRow(['asset_code' => 'A'])]);

        $this->assertFalse($this->service()->preview('extinguishers', $file, $officer)['blocked']);

        $this->expectException(ValidationException::class);
        $this->service()->import('extinguishers', $file, $officer);
    }

    public function test_unmatched_sites_are_reported_once_each_and_no_site_is_ever_created(): void
    {
        config(['gwl.max_import_failure_percent' => 100]);
        $officer = $this->officer();
        $this->site('Sowutuom District Office');
        $sitesBefore = HsSite::query()->count();

        $file = $this->workbook(self::EXT, [
            $this->extRow(['site' => 'Kaneshie Market PP']),
            $this->extRow(['site' => 'kaneshie  market pp']),
            $this->extRow(['site' => 'Madina Pay Point']),
            $this->extRow(),
        ]);

        $preview = $this->service()->preview('extinguishers', $file, $officer);

        $this->assertSame(3, $preview['error_rows']);
        $this->assertCount(2, $preview['unmatched_sites'], 'each distinct unmatched name is listed once');
        $this->assertEqualsCanonicalizing(['Kaneshie Market PP', 'Madina Pay Point'], $preview['unmatched_sites']);

        $this->service()->import('extinguishers', $file, $officer);

        $this->assertSame($sitesBefore, HsSite::query()->count(), 'the import never creates a site');
        $this->assertSame(1, HsFireExtinguisher::query()->count());
    }

    public function test_a_deactivated_or_ambiguous_site_is_an_error(): void
    {
        config(['gwl.max_import_failure_percent' => 100]);
        $officer = $this->officer();
        $this->site('Closed Office')->update(['is_active' => false]);
        $this->site('Twin Office', $this->sowutuom);
        $this->site('Twin Office', $this->odorkor, 'pay_point');

        $preview = $this->service()->preview('extinguishers', $this->workbook(self::EXT, [
            $this->extRow(['site' => 'Closed Office']),
            $this->extRow(['site' => 'Twin Office']),
            $this->extRow(['site' => 'Twin Office', 'district' => 'Odorkor']),
        ]), $officer);

        $messages = collect($preview['errors'])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('deactivated', $messages);
        $this->assertStringContainsString('more than one site', $messages);
        $this->assertSame(1, $preview['valid_count'], 'naming the district resolves the twin');
    }

    public function test_an_officer_can_only_import_into_sites_of_their_own_region(): void
    {
        config(['gwl.max_import_failure_percent' => 100]);
        $officer = $this->officer();
        $this->site('Kumasi Central Office', $this->kumasi);
        $this->site('Sowutuom District Office');

        $file = $this->workbook(self::EXT, [$this->extRow(['site' => 'Kumasi Central Office']), $this->extRow()]);

        $preview = $this->service()->preview('extinguishers', $file, $officer);
        $this->assertSame(1, $preview['error_rows']);
        $this->assertSame(['Kumasi Central Office'], $preview['unmatched_sites'], 'to this officer it does not exist');

        // The same file for someone who sees every region.
        $all = $this->service()->preview('extinguishers', $file, $this->hsManager());
        $this->assertSame(0, $all['error_rows']);
        $this->assertSame(2, $all['valid_count']);

        $this->service()->import('extinguishers', $file, $officer);
        $this->assertSame([$this->accraWest->id], HsFireExtinguisher::query()->pluck('region_id')->unique()->all());
    }

    // ---------------------------------------------------------------- the other row errors and tolerance

    public function test_each_kind_of_row_error_is_reported_with_its_row_number(): void
    {
        config(['gwl.max_import_failure_percent' => 100]);
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $preview = $this->service()->preview('extinguishers', $this->workbook(self::EXT, [
            $this->extRow(['asset_code' => 'DUP']),
            $this->extRow(['asset_code' => 'dup']),
            $this->extRow(['type' => 'sparkler']),
            $this->extRow(['responsible_staff_id' => '999999']),
            $this->extRow(['site' => '']),
        ]), $officer);

        $byRow = collect($preview['errors'])->groupBy('row')->map(fn ($errors) => $errors->pluck('message')->implode(' '));

        $this->assertStringContainsString('more than once', $byRow[3]);
        $this->assertStringContainsString('Unknown extinguisher type', $byRow[4]);
        $this->assertStringContainsString('Staff ID 999999 was not found', $byRow[5]);
        $this->assertStringContainsString('No site given', $byRow[6]);
        $this->assertArrayNotHasKey(2, $byRow->all(), 'the first use of a code is fine');
    }

    public function test_headings_are_matched_tolerantly_and_the_extinguisher_type_has_obvious_spellings(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $headings = ['Asset Code', ' TYPE ', 'Site', 'Expiry-Date'];
        $types = ['Water', 'FOAM', 'dry powder', 'Dry-Powder', 'dry_powder', 'CO2', 'co 2', 'Carbon Dioxide', 'wet chemical'];
        $rows = collect($types)->map(fn ($type, $i) => ['Asset Code' => 'T'.$i, ' TYPE ' => $type, 'Site' => 'sowutuom district office', 'Expiry-Date' => '01/03/2030'])->all();

        // Headings that need trimming still need to be found: the tolerant ones are the plain heading names, spaces or hyphens
        // turned into underscores, and the case ignored.
        $preview = $this->service()->preview('extinguishers', $this->workbook($headings, $rows), $officer);

        $this->assertSame([], $preview['errors']);
        $this->assertSame(
            ['water', 'foam', 'dry_powder', 'dry_powder', 'dry_powder', 'co2', 'co2', 'co2', 'wet_chemical'],
            collect($preview['valid_rows'])->pluck('extinguisher_type')->all()
        );
    }

    public function test_a_file_missing_a_required_column_is_blocked(): void
    {
        $officer = $this->officer();

        $preview = $this->service()->preview('extinguishers', $this->workbook(['asset_code', 'site'], [['asset_code' => 'A', 'site' => 'X']]), $officer);

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('Missing required columns: type', $preview['errors'][0]['message']);

        $this->expectException(ValidationException::class);
        $this->service()->import('extinguishers', $this->workbook(['asset_code', 'site'], [['asset_code' => 'A', 'site' => 'X']]), $officer);
    }

    public function test_an_empty_file_and_blank_rows_are_handled(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $empty = $this->service()->preview('extinguishers', $this->workbook([], []), $officer);
        $this->assertTrue($empty['blocked']);

        $withBlanks = $this->service()->preview('extinguishers', $this->workbook(self::EXT, [$this->extRow(), [], $this->extRow()]), $officer);
        $this->assertSame(2, $withBlanks['total_rows'], 'blank rows are skipped');
    }

    public function test_a_missing_expiry_date_is_a_warning_not_an_error(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $preview = $this->service()->preview('extinguishers', $this->workbook(self::EXT, [$this->extRow(['expiry_date' => null])]), $officer);

        $this->assertSame(0, $preview['error_rows']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertStringContainsString('No expiry date', $preview['warnings'][0]['message']);
    }

    // ---------------------------------------------------------------- kits

    public function test_kits_are_imported_with_their_contents_from_the_template(): void
    {
        $officer = $this->officer();
        $site = $this->site('Sowutuom District Office');
        HsFirstAidItemTemplate::query()->create(['kit_type' => 'medium', 'item_name' => 'Gauze', 'required_qty' => 6, 'has_expiry' => true, 'sort_order' => 0]);

        $file = $this->workbook(['asset_code', 'kit_type', 'site', 'location_detail', 'responsible_staff_id', 'last_checked_on', 'notes'], [
            ['asset_code' => 'KIT-1', 'kit_type' => 'Medium', 'site' => 'Sowutuom District Office', 'location_detail' => 'Reception', 'last_checked_on' => '01/10/2026', 'notes' => 'Old kit'],
            ['kit_type' => 'small', 'site' => 'Sowutuom District Office'],
        ]);

        $result = $this->service()->import('kits', $file, $officer);

        $this->assertSame(2, $result['created']);
        $kit = HsFirstAidKit::query()->where('asset_code', 'KIT-1')->firstOrFail();
        $this->assertSame('medium', $kit->kit_type);
        $this->assertSame($site->id, $kit->site_id);
        $this->assertSame('2026-10-01', $kit->last_checked_on->toDateString());
        $this->assertSame(['Gauze'], $kit->items->pluck('item_name')->all(), 'contents come from the template, not the file');
        $this->assertCount(0, HsFirstAidKit::query()->where('kit_type', 'small')->firstOrFail()->items, 'no template for small: empty kit');
        $this->assertMatchesRegularExpression('/^FAK-/', HsFirstAidKit::query()->where('kit_type', 'small')->value('asset_code'));
    }

    public function test_a_kit_row_with_an_unknown_type_is_an_error(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $preview = $this->service()->preview('kits', $this->workbook(['kit_type', 'site'], [['kit_type' => 'giant', 'site' => 'Sowutuom District Office']]), $officer);

        $this->assertSame(1, $preview['error_rows']);
        $this->assertStringContainsString('Unknown kit type', $preview['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- one transaction, permissions, screen

    public function test_the_insert_is_one_transaction(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        // A service that fails on the second row, as a database error half way through would.
        $this->app->bind(FireExtinguisherService::class, fn ($app) => new class($app->make(\App\Services\HealthSafety\EquipmentScope::class), $app->make(\App\Services\HealthSafety\EquipmentLocation::class), $app->make(\App\Services\HealthSafety\EquipmentAssetCodeGenerator::class)) extends FireExtinguisherService
        {
            public int $calls = 0;

            public function create(\App\Models\User $actor, array $data, bool $audit = true): HsFireExtinguisher
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Simulated database failure.');
                }

                return parent::create($actor, $data, $audit);
            }
        });

        try {
            $this->service()->import('extinguishers', $this->workbook(self::EXT, [$this->extRow(), $this->extRow(), $this->extRow()]), $officer);
            $this->fail('The failure should surface.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, HsFireExtinguisher::query()->count(), 'nothing from the file survived the failure');
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.equipment_imported')->count());
    }

    public function test_only_someone_who_manages_equipment_can_import_or_download_the_templates(): void
    {
        $this->site('Sowutuom District Office');
        $file = $this->workbook(self::EXT, [$this->extRow()]);

        foreach ([$this->reporter(), $this->districtManager('200003', $this->sowutuom)] as $user) {
            $this->actingAs($user)->get(route('health_safety.equipment-import'))->assertForbidden();
            $this->actingAs($user)->get(route('health_safety.equipment-import.template', 'extinguishers'))->assertForbidden();
            Livewire::actingAs($user)->test(EquipmentImport::class)->assertForbidden();
        }

        try {
            $this->service()->preview('extinguishers', $file, $this->districtManager('200003', $this->sowutuom));
            $this->fail('The service refuses too.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($this->officer())->get(route('health_safety.equipment-import'))->assertOk();
        $this->actingAs($this->officer())->get(route('health_safety.equipment-import.template', 'extinguishers'))->assertOk();
        $this->actingAs($this->officer())->get(route('health_safety.equipment-import.template', 'kits'))->assertOk();
        $this->actingAs($this->officer())->get('/health-safety/equipment-import/template/other')->assertNotFound();
    }

    public function test_the_templates_carry_the_expected_headings(): void
    {
        $export = $this->service()->templateExport('extinguishers');
        $this->assertSame(self::EXT, $export->headings());
        $this->assertSame(['asset_code', 'kit_type', 'site', 'district', 'location_detail', 'responsible_staff_id', 'last_checked_on', 'notes'], $this->service()->templateExport('kits')->headings());
    }

    public function test_the_screen_previews_then_imports_and_redirects_to_the_list(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');
        $file = $this->workbook(self::EXT, [$this->extRow(['asset_code' => 'SCREEN-1']), $this->extRow(['site' => 'Missing Site'])]);

        config(['gwl.max_import_failure_percent' => 100]);

        $component = Livewire::actingAs($officer)->test(EquipmentImport::class)
            ->set('file', $file)
            ->call('previewFile')
            ->assertHasNoErrors()
            ->assertSee('Missing Site')
            ->assertSee('Ready to import');

        $this->assertSame(1, $component->get('preview')['valid_count']);
        $this->assertArrayNotHasKey('valid_rows', $component->get('preview'), 'the rows stay on the server');
        $this->assertSame(0, HsFireExtinguisher::query()->count());

        $component->call('runImport')->assertRedirect(route('health_safety.extinguishers.index'));
        $this->assertSame(['SCREEN-1'], HsFireExtinguisher::query()->pluck('asset_code')->all());
    }

    public function test_the_preview_the_browser_holds_cannot_be_changed_to_import_other_rows(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');
        $kumasi = $this->site('Kumasi Central Office', $this->kumasi);

        $component = Livewire::actingAs($officer)->test(EquipmentImport::class)
            ->set('file', $this->workbook(self::EXT, [$this->extRow(['asset_code' => 'REAL-1'])]))
            ->call('previewFile');

        // The preview property is locked: tampering with it is refused...
        try {
            $component->set('preview', ['valid_count' => 1, 'blocked' => false, 'valid_rows' => [['site_id' => $kumasi->id]]]);
            $this->fail('The preview must be locked.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsStringIgnoringCase('locked', $exception->getMessage());
        }

        // ...and confirming reads the file again, so only what is in it can ever be imported.
        $component->call('runImport');
        $this->assertSame(['REAL-1'], HsFireExtinguisher::query()->pluck('asset_code')->all());
        $this->assertSame($this->accraWest->id, HsFireExtinguisher::query()->value('region_id'));
    }

    public function test_the_row_limit_protects_against_an_enormous_file(): void
    {
        $officer = $this->officer();
        $this->site('Sowutuom District Office');

        $rows = array_fill(0, EquipmentImportService::MAX_ROWS + 1, ['type' => 'water', 'site' => 'Sowutuom District Office']);
        $preview = $this->service()->preview('extinguishers', $this->workbook(['type', 'site'], $rows), $officer);

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('more than '.EquipmentImportService::MAX_ROWS.' rows', $preview['errors'][0]['message']);
    }
}
