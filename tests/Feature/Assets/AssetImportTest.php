<?php

namespace Tests\Feature\Assets;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAssignment;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\User;
use App\Services\Assets\AssetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/**
 * Assets > Upload assets: create-only, preview first, confirm re-reads the stored file, the importer's own region only,
 * one audit row per import, and the Excel safety rules (xlsx only, no formulas, size and row caps).
 */
class AssetImportTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    protected District $accra;

    protected District $kumasi;

    protected User $ict;

    protected Employee $holder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedCoreAssetsAccess();

        $this->accra = $this->makeDistrict('Accra Central', 'Greater Accra');
        $this->kumasi = $this->makeDistrict('Kumasi Central', 'Ashanti');
        $this->makeDistrict('Tema', 'Greater Accra');

        $dell = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        IctAssetModel::query()->create(['name' => 'Latitude 5420', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $dell->id, 'is_active' => true]);
        IctAssetModel::query()->create(['name' => 'OptiPlex 7090', 'category' => 'PC', 'ict_asset_manufacturer_id' => $dell->id, 'is_active' => true]);
        $hp = IctAssetManufacturer::query()->create(['name' => 'HP']);
        IctAssetModel::query()->create(['name' => 'LaserJet M404', 'category' => 'PRT', 'ict_asset_manufacturer_id' => $hp->id, 'is_active' => true]);

        Department::query()->firstOrCreate(['department_name' => 'Finance']);

        $this->ict = $this->ictUser($this->accra);
        $this->holder = $this->makeEmployee('GW1001', 'Kofi Holder', $this->accra);
    }

    /** One valid row, overridable by key (the template's column keys). @return array<string, string> */
    protected function row(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'asset_name' => 'Imported Laptop '.$n,
            'asset_type' => 'Laptop',
            'manufacturer' => 'Dell',
            'model' => 'Latitude 5420',
            'serial_number' => 'IMP-SN-'.$n,
            'asset_tag' => 'GWCL/FOE/2026/'.$n,
            'department' => 'Finance',
            'location' => 'Accra Central',
            'actual_location' => 'Room '.$n,
            'status' => 'Active',
            'status_reason' => '',
            'assigned_to' => 'GW1001',
            'general_use' => 'No',
            'purchase_date' => '2025-03-14',
            'warranty_expires' => '2028-03-14',
            ...$overrides,
        ];
    }

    /**
     * Writes a real .xlsx (every cell explicit text unless it starts with "=", which becomes a formula) and returns the upload.
     *
     * @param  list<array<string, string>>  $rows
     */
    protected function workbook(array $rows, ?array $keys = null, string $name = 'assets.xlsx'): UploadedFile
    {
        $keys ??= array_keys(AssetImportService::COLUMNS);
        $sheet = new Spreadsheet;
        $active = $sheet->getActiveSheet();

        foreach ($keys as $c => $key) {
            $active->setCellValueExplicit([$c + 1, 1], AssetImportService::COLUMNS[$key][0], DataType::TYPE_STRING);
        }

        foreach ($rows as $r => $row) {
            foreach ($keys as $c => $key) {
                $value = (string) ($row[$key] ?? '');
                $type = str_starts_with($value, '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING;
                $active->setCellValueExplicit([$c + 1, $r + 2], $value, $type);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ai').'.xlsx';
        IOFactory::createWriter($sheet, 'Xlsx')->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function preview(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->from(route('assets.import'))->post(route('assets.import.preview'), ['file' => $file]);
    }

    protected function confirmImport(User $user, array $data = [])
    {
        return $this->actingAs($user)->from(route('assets.import'))->post(route('assets.import.run'), $data);
    }

    // ---------------------------------------------------------------- The happy path

    public function test_a_valid_file_previews_without_writing_then_creates_the_assets_and_one_audit_row(): void
    {
        $rows = [$this->row(), $this->row(['assigned_to' => '', 'asset_type' => 'PC', 'model' => 'OptiPlex 7090', 'location' => 'tema'])];

        $this->preview($this->ict, $this->workbook($rows))->assertSessionHasNoErrors();

        // The preview wrote nothing.
        $this->assertSame(0, IctAsset::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'import_assets')->count());

        $preview = session('asset_import_preview');
        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(2, $preview['valid_count']);
        $this->assertTrue($preview['can_confirm']);
        // The second row is a desktop with nobody assigned: a warning, not an error.
        $this->assertSame(1, $preview['warning_count']);
        $this->assertStringContainsString('Unassigned', $preview['warnings'][0]['message']);

        $this->get(route('assets.import'))->assertOk()->assertSee('Preview Results')->assertSee('2 valid rows');

        $this->confirmImport($this->ict)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(2, IctAsset::query()->count());
        $first = IctAsset::query()->where('asset_name', $rows[0]['asset_name'])->firstOrFail();
        $this->assertSame('Laptop', $first->asset_type);
        $this->assertSame('asset', $first->device_category);
        $this->assertSame($this->accra->id, $first->district_id);
        $this->assertSame($this->accra->region_id, $first->region_id);
        $this->assertSame($rows[0]['asset_tag'], $first->tag_no);
        $this->assertSame('Room '.substr($rows[0]['actual_location'], 5), $first->actual_location);
        $this->assertSame('2025-03-14', $first->purchased_at->format('Y-m-d'));
        $this->assertSame('2028-03-14', $first->warranty_expires_at->format('Y-m-d'));
        $this->assertNotNull($first->ict_asset_model_id);
        $this->assertSame('Finance', $first->department->department_name);

        // The holder went through the assignment service (history row), as a plain assign.
        $this->assertSame($this->holder->id, $first->assigned_to_employee_id);
        $this->assertSame(1, IctAssetAssignment::query()->where('ict_asset_id', $first->id)->where('employee_id', $this->holder->id)->current()->count());
        $this->assertNull(IctAsset::query()->where('asset_name', $rows[1]['asset_name'])->value('assigned_to_employee_id'));

        // One summary audit row: counts and a batch id, no names, serials or file names, and no per-asset create rows.
        $audit = AuditLog::query()->where('action', 'import_assets')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(2, $audit[0]->metadata['created']);
        $this->assertSame(1, $audit[0]->metadata['assigned']);
        $this->assertArrayHasKey('batch', $audit[0]->metadata);
        $this->assertSame(0, AuditLog::query()->where('action', 'create_asset')->count());
        $this->assertStringNotContainsString($rows[0]['asset_name'], json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString($rows[0]['serial_number'], json_encode(AuditLog::query()->get()->toArray()));

        // The staged file is gone and the preview is cleared.
        $this->assertSame([], Storage::disk('local')->files(AssetImportService::DIRECTORY));
        $this->assertNull(session('asset_import_preview'));
    }

    public function test_an_excel_serial_date_and_other_text_dates_are_read(): void
    {
        $file = $this->workbook([$this->row(['purchase_date' => '14/03/2025', 'warranty_expires' => '45000'])]);

        $this->preview($this->ict, $file);
        $this->confirmImport($this->ict)->assertSessionHasNoErrors();

        $asset = IctAsset::query()->firstOrFail();
        $this->assertSame('2025-03-14', $asset->purchased_at->format('Y-m-d'));
        $this->assertSame('2023-03-15', $asset->warranty_expires_at->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- Create-only and uniqueness

    public function test_it_never_updates_an_existing_asset_and_reports_duplicate_names_and_serials(): void
    {
        $existing = $this->makeAsset($this->accra, ['asset_name' => 'Boardroom Laptop', 'serial_number' => 'EXIST-1', 'status' => 'Active']);

        $rows = [
            $this->row(['asset_name' => '  boardroom LAPTOP ']),
            $this->row(['serial_number' => 'exist-1']),
            $this->row(['asset_name' => 'Twin', 'serial_number' => 'T-1']),
            $this->row(['asset_name' => 'TWIN', 'serial_number' => 'T-2']),
            $this->row(['asset_name' => 'Other', 'serial_number' => 'T-1']),
            $this->row(['asset_name' => 'Fine One']),
        ];

        $this->preview($this->ict, $this->workbook($rows));
        $preview = session('asset_import_preview');

        $messages = collect($preview['errors'])->mapWithKeys(fn ($e) => [$e['row'] => $e['message']]);
        $this->assertStringContainsString('already exists', $messages[2]);
        $this->assertStringContainsString('serial number already exists', $messages[3]);
        $this->assertStringContainsString('also used on row 4', $messages[5]);
        $this->assertStringContainsString('also used on row 4', $messages[6]);
        $this->assertSame(4, $preview['invalid_rows']);
        $this->assertSame(2, $preview['valid_count']);

        // All or nothing by default.
        $this->confirmImport($this->ict)->assertSessionHasErrors('import');
        $this->assertSame(1, IctAsset::query()->count());

        // "Valid rows only" skips the bad rows; the existing asset is untouched.
        $this->confirmImport($this->ict, ['valid_only' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(3, IctAsset::query()->count());
        $this->assertTrue(IctAsset::query()->where('asset_name', 'Fine One')->exists());
        $this->assertTrue(IctAsset::query()->where('asset_name', 'Twin')->exists());
        $this->assertSame('Boardroom Laptop', $existing->fresh()->asset_name);
        $this->assertSame(1, AuditLog::query()->where('action', 'import_assets')->count());
        $this->assertSame(4, AuditLog::query()->where('action', 'import_assets')->first()->metadata['skipped']);
    }

    // ---------------------------------------------------------------- Per-row rules

    public function test_type_manufacturer_model_department_status_and_dates_are_checked(): void
    {
        $rows = [
            $this->row(['asset_type' => 'Hoverboard']),
            $this->row(['manufacturer' => 'Acme']),
            $this->row(['model' => 'Latitude 9999']),
            $this->row(['asset_type' => 'Desktop', 'model' => 'Latitude 5420']),
            $this->row(['department' => 'Nowhere']),
            $this->row(['status' => 'Broken']),
            $this->row(['status' => 'Damaged', 'status_reason' => '']),
            $this->row(['purchase_date' => 'last week']),
            $this->row(['general_use' => 'maybe']),
            $this->row(['asset_name' => '', 'serial_number' => '']),
        ];

        $this->preview($this->ict, $this->workbook($rows));
        $preview = session('asset_import_preview');
        $byRow = collect($preview['errors'])->groupBy('row')->map(fn ($g) => $g->pluck('message')->implode(' | '));

        $this->assertStringContainsString('"Hoverboard" is not recognised', $byRow[2]);
        $this->assertStringContainsString('Manufacturer "Acme" does not exist', $byRow[3]);
        $this->assertStringContainsString('Model "Latitude 9999" does not exist for Dell', $byRow[4]);
        $this->assertStringContainsString('does not exist for Dell as a Desktop', $byRow[5]);
        $this->assertStringContainsString('Department "Nowhere" does not exist', $byRow[6]);
        $this->assertStringContainsString('Status "Broken"', $byRow[7]);
        $this->assertStringContainsString('Status Reason is required', $byRow[8]);
        $this->assertStringContainsString('Purchase Date "last week" is not a date', $byRow[9]);
        $this->assertStringContainsString('General Use must be Yes or No', $byRow[10]);
        $this->assertStringContainsString('Asset Name is required', $byRow[11]);
        $this->assertSame(0, $preview['valid_count']);
        $this->assertFalse($preview['can_confirm']);

        // A missing model is reported, never created.
        $this->assertSame(3, IctAssetModel::query()->count());
        $this->confirmImport($this->ict)->assertSessionHasErrors('import');
        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_a_row_for_another_region_is_an_error_and_the_region_is_never_read_from_the_file(): void
    {
        $rows = [
            $this->row(['location' => 'Kumasi Central']),
            $this->row(['location' => 'Atlantis']),
            $this->row(),
        ];

        $this->preview($this->ict, $this->workbook($rows));
        $preview = session('asset_import_preview');
        $byRow = collect($preview['errors'])->mapWithKeys(fn ($e) => [$e['row'] => $e['message']]);

        $this->assertStringContainsString('not in your region', $byRow[2]);
        $this->assertStringContainsString('does not exist', $byRow[3]);
        $this->assertArrayNotHasKey(4, $byRow->all());

        $this->confirmImport($this->ict, ['valid_only' => '1', 'region_id' => $this->kumasi->region_id, 'rows' => [['asset_name' => 'Smuggled']]])->assertSessionHasNoErrors();

        // Only the valid row was created, in the importer's region, and nothing the request carried was used.
        $this->assertSame(1, IctAsset::query()->count());
        $this->assertSame($this->accra->region_id, IctAsset::query()->value('region_id'));
        $this->assertFalse(IctAsset::query()->where('asset_name', 'Smuggled')->exists());

        // super_admin is held to their own region too (the same rule as the Add Asset form).
        $super = $this->makeUser('SA002', 'super_admin', $this->accra);
        $this->preview($super, $this->workbook([$this->row(['location' => 'Kumasi Central'])]));
        $this->assertStringContainsString('not in your region', session('asset_import_preview')['errors'][0]['message']);
    }

    public function test_the_holder_must_be_an_active_person_in_the_importers_region_and_not_with_general_use(): void
    {
        $this->makeEmployee('GW2001', 'Kumasi Person', $this->kumasi);
        $gone = $this->makeEmployee('GW3001', 'Left Staff', $this->accra);
        $gone->forceFill(['is_active' => false])->save();

        $rows = [
            $this->row(['assigned_to' => 'GW2001']),
            $this->row(['assigned_to' => 'GW3001']),
            $this->row(['assigned_to' => 'NOBODY']),
            $this->row(['assigned_to' => 'GW1001', 'general_use' => 'Yes']),
            $this->row(['assigned_to' => '', 'general_use' => 'Yes']),
        ];

        $this->preview($this->ict, $this->workbook($rows));
        $preview = session('asset_import_preview');
        $byRow = collect($preview['errors'])->mapWithKeys(fn ($e) => [$e['row'] => $e['message']]);

        $this->assertStringContainsString('not found in your region', $byRow[2]);
        $this->assertStringContainsString('no longer active', $byRow[3]);
        $this->assertStringContainsString('not found in your region', $byRow[4]);
        $this->assertStringContainsString('no holder', $byRow[5]);
        $this->assertArrayNotHasKey(6, $byRow->all());
        $this->assertSame(1, $preview['valid_count']);
        // A general-use laptop needs no holder, so no "Unassigned" warning either.
        $this->assertSame(0, $preview['warning_count']);
    }

    public function test_a_tag_already_in_use_is_only_a_warning(): void
    {
        $this->makeAsset($this->accra, ['tag_no' => 'GWCL/FOE/2026/777']);

        $this->preview($this->ict, $this->workbook([$this->row(['asset_tag' => 'gwcl/foe/2026/777'])]));
        $preview = session('asset_import_preview');

        $this->assertSame(1, $preview['valid_count']);
        $this->assertStringContainsString('already used by another asset', collect($preview['warnings'])->pluck('message')->implode(' '));
    }

    // ---------------------------------------------------------------- Excel safety

    public function test_a_formula_cell_is_rejected_and_nothing_is_calculated(): void
    {
        $this->preview($this->ict, $this->workbook([
            $this->row(['asset_name' => '=HYPERLINK("http://evil","x")']),
            $this->row(['serial_number' => '=1+1']),
            $this->row(),
        ]));

        $preview = session('asset_import_preview');
        $byRow = collect($preview['errors'])->mapWithKeys(fn ($e) => [$e['row'] => $e['message']]);

        $this->assertStringContainsString('contains a formula', $byRow[2]);
        $this->assertStringContainsString('contains a formula', $byRow[3]);
        $this->assertSame(1, $preview['valid_count']);

        $this->confirmImport($this->ict)->assertSessionHasErrors('import');
        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_only_xlsx_within_the_size_and_row_caps_is_accepted(): void
    {
        // Not an xlsx by name.
        $csv = UploadedFile::fake()->createWithContent('assets.csv', "Asset Name,Asset Type\nA,Laptop\n");
        $this->preview($this->ict, $csv)->assertSessionHasErrors('file');

        // An .xlsx name over something that is not a workbook.
        $fake = UploadedFile::fake()->createWithContent('assets.xlsx', 'not a zip file at all');
        $this->preview($this->ict, $fake)->assertSessionHasErrors('file');

        // Too big.
        config(['gwl.assets_import_max_mb' => 0]);
        $this->preview($this->ict, $this->workbook([$this->row()]))->assertSessionHasErrors('file');
        config(['gwl.assets_import_max_mb' => 5]);

        // Row cap: the file is refused as a whole.
        config(['gwl.assets_import_max_rows' => 2]);
        $this->preview($this->ict, $this->workbook([$this->row(), $this->row(), $this->row()]));
        $preview = session('asset_import_preview');
        $this->assertFalse($preview['can_confirm']);
        $this->assertStringContainsString('at most 2', $preview['errors'][0]['message']);
        $this->confirmImport($this->ict)->assertSessionHasErrors('import');
        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_missing_required_columns_are_named(): void
    {
        $keys = array_values(array_diff(array_keys(AssetImportService::COLUMNS), ['serial_number', 'department']));

        $this->preview($this->ict, $this->workbook([$this->row()], $keys));
        $preview = session('asset_import_preview');

        $this->assertFalse($preview['can_confirm']);
        $this->assertStringContainsString('Serial Number, Department', $preview['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- Confirm re-reads the file

    public function test_confirm_reads_the_file_again_and_judges_it_against_the_database_as_it_is_now(): void
    {
        $this->preview($this->ict, $this->workbook([$this->row(['asset_name' => 'Race Laptop', 'serial_number' => 'RACE-1'])]));
        $this->assertTrue(session('asset_import_preview')['can_confirm']);

        // Somebody else creates the same asset between the preview and the run.
        $this->makeAsset($this->accra, ['asset_name' => 'Race Laptop', 'serial_number' => 'OTHER']);

        $this->confirmImport($this->ict)->assertSessionHasErrors('import');
        $this->assertSame(1, IctAsset::query()->count());

        // And what the browser says is not used: the session's token is, and the stored file is the only source of rows.
        $this->assertSame(0, IctAsset::query()->where('serial_number', 'RACE-1')->count());
    }

    public function test_the_stored_file_is_what_is_imported_not_the_preview_rows(): void
    {
        $this->preview($this->ict, $this->workbook([$this->row(['asset_name' => 'Previewed Laptop'])]));

        // Swap the stored workbook for another one (the preview in the session still describes the first).
        $token = session('asset_import_preview')['token'];
        $other = $this->workbook([$this->row(['asset_name' => 'Replaced Laptop'])]);
        Storage::disk('local')->put(AssetImportService::DIRECTORY.'/'.$token.'.xlsx', file_get_contents($other->getPathname()));

        $this->confirmImport($this->ict)->assertSessionHasNoErrors();

        $this->assertTrue(IctAsset::query()->where('asset_name', 'Replaced Laptop')->exists());
        $this->assertFalse(IctAsset::query()->where('asset_name', 'Previewed Laptop')->exists());
    }

    public function test_the_import_makes_no_mdm_call(): void
    {
        config(['gwl.mdm_enabled' => false]);
        \Illuminate\Support\Facades\Queue::fake();

        $this->preview($this->ict, $this->workbook([$this->row()]));
        $this->confirmImport($this->ict)->assertSessionHasNoErrors();

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- Who

    public function test_it_needs_the_assets_import_permission_and_a_region(): void
    {
        $viewer = $this->makeUser('NOIMP', 'ict_team', $this->accra);
        $viewer->roles()->first()->permissions()->detach(\App\Models\Permission::query()->where('name', 'assets.import')->value('id'));

        $this->actingAs($viewer)->get(route('assets.import'))->assertForbidden();
        $this->actingAs($viewer)->get(route('assets.import.template'))->assertForbidden();
        $this->preview($viewer, $this->workbook([$this->row()]))->assertForbidden();

        // Someone who may import but has no region on their employee record cannot create anything.
        $homeless = $this->makeUser('NOREGION', 'super_admin');
        $this->preview($homeless, $this->workbook([$this->row()]));
        $this->assertStringContainsString('no region assigned', session('asset_import_preview')['errors'][0]['message']);
    }

    public function test_the_ict_manager_and_the_ict_team_can_import_and_the_button_shows(): void
    {
        $manager = $this->makeUser('ICM1', 'ict_manager', $this->accra);

        $this->actingAs($manager)->get(route('assets.import'))->assertOk();
        $this->actingAs($manager)->get(route('assets.assets'))->assertOk()->assertSee('Upload assets');

        $this->preview($manager, $this->workbook([$this->row()]));
        $this->confirmImport($manager)->assertSessionHasNoErrors();
        $this->assertSame(1, IctAsset::query()->count());
    }

    public function test_a_regional_ict_user_cannot_hold_assets_for_people_in_other_regions_but_super_admin_can_pick_any_person(): void
    {
        $other = $this->makeEmployee('GW5001', 'Kumasi Holder', $this->kumasi);
        $super = $this->makeUser('SA009', 'super_admin', $this->accra);

        $this->preview($this->ict, $this->workbook([$this->row(['assigned_to' => $other->staff_id])]));
        $this->assertSame(0, session('asset_import_preview')['valid_count']);

        // The same pool as the Assigned To picker: not region-limited for an unscoped user.
        $this->preview($super, $this->workbook([$this->row(['assigned_to' => $other->staff_id])]));
        $this->assertSame(1, session('asset_import_preview')['valid_count']);
    }

    // ---------------------------------------------------------------- Template

    public function test_the_template_has_the_columns_and_an_instructions_sheet(): void
    {
        $response = $this->actingAs($this->ict)->get(route('assets.import.template'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tp').'.xlsx';
        file_put_contents($path, $response->baseResponse->getFile()->getContent());

        $book = IOFactory::load($path);
        $this->assertSame(['Assets', 'Instructions'], $book->getSheetNames());

        $headings = array_values(array_filter($book->getSheet(0)->toArray()[0]));
        $this->assertSame(array_map(fn ($c) => $c[0], array_values(AssetImportService::COLUMNS)), $headings);
        $this->assertContains('Asset Tag', $headings);
        $this->assertContains('Manufacturer', $headings);
        $this->assertContains('Warranty Expires', $headings);

        $text = json_encode($book->getSheet(1)->toArray());
        $this->assertStringContainsString('Thermal Printer', $text);
        $this->assertStringContainsString('only CREATES assets', $text);
    }
}
