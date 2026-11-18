<?php

namespace Tests\Feature\Assets;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assets\AssetImportProfile;
use App\Services\Assets\AssetImportService;
use App\Services\Assets\AssetRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/**
 * Assets > Upload > Phones and Network (and the type fences between the three uploads). The Assets upload itself is
 * covered by AssetImportTest.
 */
class AssetUploadTypesTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    protected District $accra;

    protected District $tema;

    protected District $kumasi;

    protected User $ict;

    protected Employee $holder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedCoreAssetsAccess();

        $this->accra = $this->makeDistrict('Accra Central', 'Greater Accra');
        $this->tema = $this->makeDistrict('Tema', 'Greater Accra');
        $this->kumasi = $this->makeDistrict('Kumasi Central', 'Ashanti');

        $samsung = IctAssetManufacturer::query()->create(['name' => 'Samsung']);
        IctAssetModel::query()->create(['name' => 'Galaxy A15', 'category' => 'Ph', 'ict_asset_manufacturer_id' => $samsung->id, 'is_active' => true]);
        $cisco = IctAssetManufacturer::query()->create(['name' => 'Cisco']);
        IctAssetModel::query()->create(['name' => 'Catalyst 2960', 'category' => 'SW', 'ict_asset_manufacturer_id' => $cisco->id, 'is_active' => true]);
        IctAssetModel::query()->create(['name' => 'ISR 1100', 'category' => 'RT', 'ict_asset_manufacturer_id' => $cisco->id, 'is_active' => true]);

        Department::query()->firstOrCreate(['department_name' => 'Finance']);

        $this->ict = $this->ictUser($this->accra);
        $this->holder = $this->makeEmployee('GW1001', 'Kofi Holder', $this->accra);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array<string, string> */
    protected function phoneRow(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'asset_name' => 'Imported Phone '.$n,
            'asset_type' => 'Phone',
            'manufacturer' => 'Samsung',
            'model' => 'Galaxy A15',
            'serial_number' => 'PH-SER-'.$n,
            'imei' => '35693803564'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'status' => 'Active',
            'assigned_to' => '',
            'general_use' => 'No',
            'location' => 'Accra Central',
            ...$overrides,
        ];
    }

    /** @return array<string, string> */
    protected function networkRow(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'asset_name' => 'Imported Switch '.$n,
            'asset_type' => 'Switch',
            'manufacturer' => 'Cisco',
            'model' => 'Catalyst 2960',
            'serial_number' => 'NET-SER-'.$n,
            'mac_address' => '00:1A:2B:3C:4D:'.str_pad(dechex($n), 2, '0', STR_PAD_LEFT),
            'device_ip' => '10.10.1.'.$n,
            'location' => 'Accra Central',
            'status' => 'Active',
            ...$overrides,
        ];
    }

    /**
     * Writes a real .xlsx. A string stays text (a leading "=" becomes a formula), an int or float is a number cell.
     *
     * @param  list<array<string, string|int|float>>  $rows
     */
    protected function workbook(string $type, array $rows, ?array $keys = null, string $name = 'upload.xlsx'): UploadedFile
    {
        $columns = AssetImportProfile::for($type)->columns;
        $keys ??= array_keys($columns);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();

        foreach ($keys as $c => $key) {
            $sheet->setCellValueExplicit([$c + 1, 1], $columns[$key][0], DataType::TYPE_STRING);
        }

        foreach ($rows as $r => $row) {
            foreach ($keys as $c => $key) {
                $value = $row[$key] ?? '';
                $dataType = is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : (str_starts_with((string) $value, '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, $dataType);
            }
        }

        // A second sheet, as the template has: only the first one may be read.
        $book->createSheet()->setTitle('Allowed values')->setCellValue('A1', 'Not a heading row');

        $path = tempnam(sys_get_temp_dir(), 'up').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function uploadUrl(string $type, string $action): string
    {
        $base = AssetImportProfile::for($type)->routeBase;

        return route($action === 'index' ? $base : $base.'.'.$action);
    }

    protected function key(string $type): string
    {
        return AssetImportProfile::for($type)->sessionKey;
    }

    protected function preview(User $user, string $type, UploadedFile $file)
    {
        return $this->actingAs($user)->from($this->uploadUrl($type, 'preview'))->post($this->uploadUrl($type, 'preview'), ['file' => $file]);
    }

    protected function confirmImport(User $user, string $type, array $data = [])
    {
        return $this->actingAs($user)->from($this->uploadUrl($type, 'run'))->post($this->uploadUrl($type, 'run'), $data);
    }

    protected function messages(string $type): array
    {
        return collect(session($this->key($type))['errors'])->mapWithKeys(fn ($e) => [$e['row'] => $e['message']])->all();
    }

    // ---------------------------------------------------------------- Phones

    public function test_valid_phones_preview_without_writing_then_import_with_one_audit_row_of_counts(): void
    {
        $rows = [
            $this->phoneRow(['serial_number' => 'ONLY-SERIAL-1', 'imei' => '']),
            $this->phoneRow(['serial_number' => '', 'imei' => '356938035643809']),
            $this->phoneRow(['assigned_to' => 'GW1001', 'location' => '']),
        ];

        $this->preview($this->ict, 'phones', $this->workbook('phones', $rows))->assertSessionHasNoErrors();

        $preview = session($this->key('phones'));
        $this->assertSame(3, $preview['valid_count']);
        $this->assertSame('phones', $preview['type']);
        $this->assertTrue($preview['can_confirm']);
        $this->assertSame(0, IctAsset::query()->count(), 'a preview writes nothing');
        $this->assertSame(0, AuditLog::query()->where('action', 'import_phones')->count());

        $this->actingAs($this->ict)->get($this->uploadUrl('phones', 'index'))->assertOk();
    }

    public function test_confirming_creates_phone_records_and_leaves_one_audit_row_of_counts_only(): void
    {
        $rows = [
            $this->phoneRow(['asset_name' => 'Secret Phone Name', 'serial_number' => 'ONLY-SERIAL-1', 'imei' => '']),
            $this->phoneRow(['serial_number' => '', 'imei' => '356938035643809']),
            $this->phoneRow(['assigned_to' => 'GW1001', 'location' => '']),
        ];

        $this->preview($this->ict, 'phones', $this->workbook('phones', $rows));
        $this->confirmImport($this->ict, 'phones')->assertSessionHasNoErrors();

        $this->assertSame(3, IctAsset::query()->where('device_category', 'phone')->count());

        $serialOnly = IctAsset::query()->where('asset_name', 'Secret Phone Name')->firstOrFail();
        $this->assertNull($serialOnly->imei, 'a blank IMEI is NULL, never an empty string');
        $this->assertSame($this->accra->region_id, $serialOnly->region_id);

        $imeiOnly = IctAsset::query()->where('imei', '356938035643809')->firstOrFail();
        $this->assertNull($imeiOnly->serial_number, 'a blank serial is NULL so a second blank cannot clash on the unique index');

        $held = IctAsset::query()->where('assigned_to_employee_id', $this->holder->id)->firstOrFail();
        $this->assertSame($this->accra->id, $held->district_id, 'a blank location takes the holder\'s district');
        $this->assertSame(1, $held->assignments()->where('is_current', 1)->count());

        $audits = AuditLog::query()->where('action', 'import_phones')->get();
        $this->assertCount(1, $audits);
        $this->assertSame(3, $audits[0]->metadata['created']);
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['create_phone', 'create_asset'])->count(), 'no per-phone audit rows');

        $dump = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString('Secret Phone Name', $dump);
        $this->assertStringNotContainsString('ONLY-SERIAL-1', $dump);
        $this->assertStringNotContainsString('356938035643809', $dump);
        $this->assertStringNotContainsString('GW1001', $dump);

        $this->assertSame([], Storage::disk('local')->allFiles(AssetImportService::DIRECTORY), 'the staged file is deleted after confirm');
        $this->assertNull(session($this->key('phones')));
    }

    public function test_a_phone_needs_a_serial_number_or_an_imei_and_blank_or_spaces_count_as_missing(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['serial_number' => '', 'imei' => '']),
            $this->phoneRow(['serial_number' => '   ', 'imei' => '  ']),
            $this->phoneRow(['serial_number' => 'OK-SERIAL', 'imei' => '']),
        ]));

        $messages = $this->messages('phones');
        $this->assertSame(AssetRecordService::PHONE_IDENTITY_MESSAGE, $messages[2]);
        $this->assertSame(AssetRecordService::PHONE_IDENTITY_MESSAGE, $messages[3]);
        $this->assertArrayNotHasKey(4, $messages);
        $this->assertSame(1, session($this->key('phones'))['valid_count']);

        $this->confirmImport($this->ict, 'phones')->assertSessionHasErrors('import');
        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_a_pos_terminal_or_sim_card_needs_neither_serial_nor_imei_nor_a_model(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['asset_type' => 'SIM Card', 'serial_number' => '', 'imei' => '', 'manufacturer' => '', 'model' => '']),
            $this->phoneRow(['asset_type' => 'POS', 'serial_number' => '', 'imei' => '', 'manufacturer' => '', 'model' => '']),
        ]));

        $this->assertSame(2, session($this->key('phones'))['valid_count'], json_encode(session($this->key('phones'))['errors']));
        $this->confirmImport($this->ict, 'phones')->assertSessionHasNoErrors();
        $this->assertSame(2, IctAsset::query()->count());
    }

    public function test_duplicate_serials_and_imeis_in_the_file_and_the_database_are_flagged_naming_the_field(): void
    {
        $this->makeAsset($this->accra, ['device_category' => 'phone', 'asset_type' => 'Ph', 'serial_number' => 'DB-SERIAL', 'imei' => '111111111111111']);

        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['serial_number' => 'FILE-DUP', 'imei' => '222222222222222']),
            $this->phoneRow(['serial_number' => 'file-dup', 'imei' => '333333333333333']),   // row 3: serial repeats row 2
            $this->phoneRow(['serial_number' => 'S-4', 'imei' => '222222222222222']),         // row 4: IMEI repeats row 2
            $this->phoneRow(['serial_number' => 'db-serial', 'imei' => '444444444444444']),   // row 5: serial in the database
            $this->phoneRow(['serial_number' => 'S-6', 'imei' => '111111111111111']),         // row 6: IMEI in the database
        ]));

        $messages = $this->messages('phones');
        $this->assertStringContainsString('serial number is also used on row 2', $messages[3]);
        $this->assertStringContainsString('IMEI is also used on row 2', $messages[4]);
        $this->assertStringContainsString('serial number already exists', $messages[5]);
        $this->assertStringContainsString('IMEI already exists', $messages[6]);
        $this->assertSame(1, session($this->key('phones'))['valid_count']);

        $columns = collect(session($this->key('phones'))['errors'])->pluck('column', 'row')->all();
        $this->assertSame('Serial Number', $columns[3]);
        $this->assertSame('IMEI', $columns[4]);
    }

    public function test_a_brand_new_phone_name_never_reports_that_it_already_exists_even_beside_other_blank_serials(): void
    {
        // Existing phones with NO serial number: a blank must never be compared or stored as an empty string.
        $this->makeAsset($this->accra, ['device_category' => 'phone', 'asset_type' => 'Ph', 'serial_number' => null, 'imei' => '999999999999991']);
        $this->makeAsset($this->accra, ['device_category' => 'phone', 'asset_type' => 'Ph', 'serial_number' => null, 'imei' => '999999999999992']);

        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['asset_name' => 'Brand New Phone A', 'serial_number' => '', 'imei' => '777777777777771']),
            $this->phoneRow(['asset_name' => 'Brand New Phone B', 'serial_number' => '', 'imei' => '777777777777772']),
        ]));

        $this->assertSame(2, session($this->key('phones'))['valid_count']);
        $this->assertStringNotContainsString('already exists', json_encode(session($this->key('phones'))));

        $this->confirmImport($this->ict, 'phones')->assertSessionHasNoErrors();
        $this->assertTrue(IctAsset::query()->where('asset_name', 'Brand New Phone A')->exists());
        $this->assertTrue(IctAsset::query()->where('asset_name', 'Brand New Phone B')->exists());

        // And a name that really exists still says so (case and spaces ignored).
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow(['asset_name' => ' brand new phone a '])]));
        $this->assertSame(AssetRecordService::DUPLICATE_NAME_MESSAGE, $this->messages('phones')[2]);
    }

    public function test_an_unknown_model_manufacturer_or_district_is_refused_and_never_created(): void
    {
        $manufacturers = IctAssetManufacturer::query()->count();
        $models = IctAssetModel::query()->count();
        $districts = District::query()->count();

        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['manufacturer' => 'Nokia']),
            $this->phoneRow(['model' => 'Galaxy Z99']),
            $this->phoneRow(['location' => 'Nowhere Town']),
            $this->phoneRow(['model' => '']),
            $this->phoneRow(['location' => 'Kumasi Central']),
        ]));

        $messages = $this->messages('phones');
        $this->assertStringContainsString('Manufacturer "Nokia" does not exist', $messages[2]);
        $this->assertStringContainsString('Model "Galaxy Z99" does not exist for Samsung as a Phone', $messages[3]);
        $this->assertStringContainsString('Location "Nowhere Town" does not exist', $messages[4]);
        $this->assertSame('Model is required.', $messages[5]);
        $this->assertStringContainsString('not in your region', $messages[6]);

        $this->confirmImport($this->ict, 'phones', ['valid_only' => '1'])->assertSessionHasErrors('import');
        $this->assertSame($manufacturers, IctAssetManufacturer::query()->count());
        $this->assertSame($models, IctAssetModel::query()->count());
        $this->assertSame($districts, District::query()->count());
        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_the_holder_decides_the_location_only_when_it_is_blank_and_an_explicit_location_wins(): void
    {
        $other = $this->makeEmployee('GW5001', 'Kumasi Holder', $this->kumasi);
        $super = $this->makeUser('SA009', 'super_admin', $this->accra);

        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['assigned_to' => 'GW1001', 'location' => '']),
            $this->phoneRow(['assigned_to' => 'GW1001', 'location' => 'Tema']),
            $this->phoneRow(['assigned_to' => 'GW5001', 'location' => '']),       // a person in another region: not found for a regional ICT user
            $this->phoneRow(['assigned_to' => 'GW1001', 'location' => 'Kumasi Central']), // explicit but outside the region
        ]));

        $messages = $this->messages('phones');
        $this->assertArrayNotHasKey(2, $messages);
        $this->assertArrayNotHasKey(3, $messages);
        $this->assertStringContainsString('GW5001', $messages[4]);
        $this->assertStringContainsString('not in your region', $messages[5]);

        $this->confirmImport($this->ict, 'phones', ['valid_only' => '1'])->assertSessionHasNoErrors();
        $districts = IctAsset::query()->orderBy('id')->pluck('district_id')->all();
        $this->assertSame([$this->accra->id, $this->tema->id], $districts);

        // An unscoped user may hold a phone for a person in another region; the holder's district is then not theirs to use.
        $this->preview($super, 'phones', $this->workbook('phones', [$this->phoneRow(['assigned_to' => 'GW5001', 'location' => ''])]));
        $preview = session($this->key('phones'));
        $this->assertSame(1, $preview['valid_count']);
        $this->assertStringContainsString('left blank', $preview['warnings'][0]['message']);
    }

    public function test_identifiers_keep_leading_zeros_and_a_number_cell_is_reported(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(['serial_number' => '00012345', 'imei' => '000123456789012', 'user_phone_number' => '0244123456']),
            $this->phoneRow(['serial_number' => '', 'imei' => 356938035643809]),          // a number that still fits in 15 digits
            $this->phoneRow(['serial_number' => '', 'imei' => 3.56938035643809E+15]),     // 16 digits: Excel has already rounded it
            $this->phoneRow(['serial_number' => 'S-4', 'imei' => '']),
        ], null));

        $preview = session($this->key('phones'));
        $messages = $this->messages('phones');
        $this->assertArrayNotHasKey(2, $messages);
        $this->assertArrayNotHasKey(3, $messages);
        $this->assertStringContainsString('lost digits', $messages[4]);
        $this->assertSame(3, $preview['valid_count']);

        $warnings = collect($preview['warnings'])->where('row', 3)->pluck('message')->implode(' ');
        $this->assertStringContainsString('stored as a number', $warnings);

        $this->confirmImport($this->ict, 'phones', ['valid_only' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(IctAsset::query()->where('serial_number', '00012345')->where('imei', '000123456789012')->where('user_phone_number', '0244123456')->exists());
    }

    // ---------------------------------------------------------------- Network

    public function test_valid_network_devices_import_with_a_normalised_mac_and_one_audit_row(): void
    {
        $this->preview($this->ict, 'network', $this->workbook('network', [
            $this->networkRow(['mac_address' => '00-1a-2b-3c-4d-5e', 'asset_type' => 'Router', 'model' => 'ISR 1100']),
            $this->networkRow(['serial_number' => '', 'mac_address' => '', 'device_ip' => '', 'manufacturer' => '', 'model' => '', 'location' => '']),
        ]));

        $this->assertSame(2, session($this->key('network'))['valid_count'], json_encode(session($this->key('network'))['errors']));
        $this->confirmImport($this->ict, 'network')->assertSessionHasNoErrors();

        $router = IctAsset::query()->where('asset_type', 'RT')->firstOrFail();
        $this->assertSame('network', $router->device_category);
        $this->assertSame('00:1A:2B:3C:4D:5E', $router->mac_address);
        $this->assertNull(IctAsset::query()->where('asset_type', 'SW')->firstOrFail()->serial_number);
        $this->assertCount(1, AuditLog::query()->where('action', 'import_network')->get());
    }

    public function test_network_ip_and_mac_formats_and_duplicates_are_checked(): void
    {
        $this->makeAsset($this->accra, ['device_category' => 'network', 'asset_type' => 'RT', 'serial_number' => 'NET-DB', 'mac_address' => 'aa-bb-cc-dd-ee-ff']);

        $this->preview($this->ict, 'network', $this->workbook('network', [
            $this->networkRow(['device_ip' => '999.1.1.1']),                                  // 2
            $this->networkRow(['mac_address' => 'not-a-mac']),                                // 3
            $this->networkRow(['mac_address' => 'AA:BB:CC:DD:EE']),                           // 4: too short
            $this->networkRow(['mac_address' => 'AABBCCDDEEFF']),                             // 5: in the database (stored with dashes)
            $this->networkRow(['mac_address' => '11:22:33:44:55:66']),                        // 6
            $this->networkRow(['mac_address' => '11-22-33-44-55-66']),                        // 7: repeats row 6
            $this->networkRow(['serial_number' => 'net-db']),                                 // 8: serial in the database
            $this->networkRow(['serial_number' => 'NEW-SER']),                                // 9
            $this->networkRow(['serial_number' => 'new-ser']),                                // 10: repeats row 9
            $this->networkRow(),                                                              // 11: fine
        ]));

        $messages = $this->messages('network');
        $this->assertStringContainsString('not a valid IP', $messages[2]);
        $this->assertStringContainsString('MAC Address "not-a-mac" is not valid', $messages[3]);
        $this->assertStringContainsString('is not valid', $messages[4]);
        $this->assertStringContainsString('MAC address already exists', $messages[5]);
        $this->assertArrayNotHasKey(6, $messages);
        $this->assertStringContainsString('MAC address is also used on row 6', $messages[7]);
        $this->assertStringContainsString('serial number already exists', $messages[8]);
        $this->assertArrayNotHasKey(9, $messages);
        $this->assertStringContainsString('serial number is also used on row 9', $messages[10]);
        $this->assertArrayNotHasKey(11, $messages);
    }

    public function test_the_network_upload_takes_network_types_only_and_models_that_exist(): void
    {
        $this->preview($this->ict, 'network', $this->workbook('network', [
            $this->networkRow(['asset_type' => 'Laptop']),
            $this->networkRow(['asset_type' => 'SW']),                       // the code works as well as the label
            $this->networkRow(['model' => 'Catalyst 9999']),
            $this->networkRow(['manufacturer' => '']),                       // a model needs its manufacturer
            $this->networkRow(['model' => '']),                              // and a manufacturer needs its model
        ]));

        $messages = $this->messages('network');
        $this->assertStringContainsString('Device Type "Laptop" is not recognised', $messages[2]);
        $this->assertArrayNotHasKey(3, $messages);
        $this->assertStringContainsString('Model "Catalyst 9999" does not exist for Cisco as a Switch', $messages[4]);
        $this->assertSame('Manufacturer is required.', $messages[5]);
        $this->assertSame('Model is required.', $messages[6]);
    }

    // ---------------------------------------------------------------- Shared safety rules

    public function test_formulas_are_refused_in_phones_and_network(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow(['asset_name' => '=1+1'])]));
        $this->assertStringContainsString('formula', $this->messages('phones')[2]);
        $this->assertSame(0, session($this->key('phones'))['valid_count']);

        $this->preview($this->ict, 'network', $this->workbook('network', [$this->networkRow(['ssid' => '=HYPERLINK("x")'])]));
        $this->assertStringContainsString('formula', $this->messages('network')[2]);
    }

    public function test_only_xlsx_within_the_row_cap_is_accepted(): void
    {
        $csv = UploadedFile::fake()->createWithContent('phones.csv', "Asset Name\nA\n");
        $this->preview($this->ict, 'phones', $csv)->assertSessionHasErrors('file');

        $fake = UploadedFile::fake()->createWithContent('phones.xlsx', 'this is not a zip archive');
        $this->preview($this->ict, 'network', $fake)->assertSessionHasErrors('file');

        config(['gwl.assets_import_max_rows' => 2]);
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow(), $this->phoneRow(), $this->phoneRow()]));
        $this->assertStringContainsString('at most 2', session($this->key('phones'))['errors'][0]['message']);
        $this->confirmImport($this->ict, 'phones')->assertSessionHasErrors('import');
        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_a_phones_file_without_serial_and_imei_columns_or_without_required_ones_is_refused(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow()], ['asset_name', 'asset_type', 'manufacturer', 'model']));
        $this->assertStringContainsString('Serial Number or an IMEI column', session($this->key('phones'))['errors'][0]['message']);

        $this->preview($this->ict, 'network', $this->workbook('network', [$this->networkRow()], ['asset_name', 'serial_number']));
        $this->assertStringContainsString('Missing required columns: Device Type', session($this->key('network'))['errors'][0]['message']);
    }

    public function test_confirm_reads_the_stored_file_again_and_ignores_what_the_browser_or_session_says(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow(['asset_name' => 'Previewed Phone'])]));
        $token = session($this->key('phones'))['token'];

        // The stored workbook is swapped: the session still describes the first one.
        $other = $this->workbook('phones', [$this->phoneRow(['asset_name' => 'Replaced Phone'])]);
        Storage::disk('local')->put(AssetImportProfile::for('phones')->directory.'/'.$token.'.xlsx', file_get_contents($other->getPathname()));

        // And the browser tries to push its own rows and counts.
        $this->confirmImport($this->ict, 'phones', ['rows' => [['asset_name' => 'Injected']], 'valid_count' => 99, 'token' => 'x', 'type' => 'network'])->assertSessionHasNoErrors();

        $this->assertTrue(IctAsset::query()->where('asset_name', 'Replaced Phone')->exists());
        $this->assertFalse(IctAsset::query()->where('asset_name', 'Previewed Phone')->exists());
        $this->assertFalse(IctAsset::query()->where('asset_name', 'Injected')->exists());

        // A tampered session valid_count / rows changes nothing either: re-judged against the database as it is now.
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow(['asset_name' => 'Replaced Phone'])]));
        $tampered = session($this->key('phones'));
        $tampered['can_confirm'] = true;
        $tampered['valid_count'] = 5;
        $this->withSession([$this->key('phones') => $tampered])
            ->actingAs($this->ict)->post($this->uploadUrl('phones', 'run'))->assertSessionHasErrors('import');
        $this->assertSame(1, IctAsset::query()->where('asset_name', 'Replaced Phone')->count());
    }

    public function test_a_token_of_one_type_cannot_confirm_another_type_or_another_user(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow()]));
        $phonesPreview = session($this->key('phones'));

        // The phones preview put under the network key: the folder of the network type does not hold that file.
        $forged = $phonesPreview;
        $forged['type'] = 'network';
        $this->withSession([$this->key('network') => $forged])
            ->actingAs($this->ict)->post($this->uploadUrl('network', 'run'))->assertNotFound();

        // Left as it is (type "phones" under the network key) it is not even a preview of that type.
        $this->withSession([$this->key('network') => $phonesPreview])
            ->actingAs($this->ict)->post($this->uploadUrl('network', 'run'))->assertSessionHasErrors('import');

        // The assets route does not read it either.
        $forged['type'] = 'assets';
        $this->withSession([$this->key('assets') => $forged])
            ->actingAs($this->ict)->post($this->uploadUrl('assets', 'run'))->assertNotFound();

        // Another user's preview is ignored.
        $other = $this->makeUser('ICT002', 'ict_team', $this->accra);
        $this->withSession([$this->key('phones') => $phonesPreview])
            ->actingAs($other)->post($this->uploadUrl('phones', 'run'))->assertSessionHasErrors('import');

        $this->assertSame(0, IctAsset::query()->count());
        $this->assertCount(1, Storage::disk('local')->files(AssetImportProfile::for('phones')->directory), 'the phones file is still waiting');
    }

    public function test_the_three_uploads_keep_their_own_waiting_file(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow()]));
        $this->preview($this->ict, 'network', $this->workbook('network', [$this->networkRow()]));

        $this->confirmImport($this->ict, 'network')->assertSessionHasNoErrors();

        $this->assertSame(['network'], IctAsset::query()->pluck('device_category')->all());
        $this->assertNotNull(session($this->key('phones')), 'the phones upload is still waiting');

        $this->confirmImport($this->ict, 'phones')->assertSessionHasNoErrors();
        $this->assertSame(2, IctAsset::query()->count());
    }

    public function test_a_valid_rows_only_import_counts_created_skipped_and_failed(): void
    {
        $this->preview($this->ict, 'phones', $this->workbook('phones', [
            $this->phoneRow(),
            $this->phoneRow(['serial_number' => '', 'imei' => '']),
        ]));

        $this->confirmImport($this->ict, 'phones')->assertSessionHasErrors('import');
        $this->assertSame(0, IctAsset::query()->count());

        $this->confirmImport($this->ict, 'phones', ['valid_only' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, IctAsset::query()->count());
        $this->assertSame(['type' => 'phones', 'created' => 1, 'skipped' => 1, 'failed' => 0, 'assigned' => 0, 'rows' => 2], session('import_result'));

        $audit = AuditLog::query()->where('action', 'import_phones')->firstOrFail();
        $this->assertSame([1, 1, 0, true], [$audit->metadata['created'], $audit->metadata['skipped'], $audit->metadata['failed'], $audit->metadata['valid_rows_only']]);
    }

    // ---------------------------------------------------------------- Who

    public function test_a_user_without_the_permission_gets_403_on_every_upload_page_and_action(): void
    {
        $viewer = $this->makeUser('NOIMP', 'ict_team', $this->accra);
        $viewer->roles()->first()->permissions()->detach(Permission::query()->where('name', 'assets.import')->value('id'));

        foreach (['phones', 'network'] as $type) {
            $this->actingAs($viewer)->get($this->uploadUrl($type, 'index'))->assertForbidden();
            $this->actingAs($viewer)->get($this->uploadUrl($type, 'template'))->assertForbidden();
            $this->actingAs($viewer)->get($this->uploadUrl($type, 'errors'))->assertForbidden();
            $this->preview($viewer, $type, $this->workbook($type, [$type === 'phones' ? $this->phoneRow() : $this->networkRow()]))->assertForbidden();
            $this->actingAs($viewer)->post($this->uploadUrl($type, 'run'))->assertForbidden();
            $this->actingAs($viewer)->post($this->uploadUrl($type, 'clear'))->assertForbidden();
        }

        // Create without import is not enough either, and neither is import without create.
        $service = app(AssetImportService::class);
        $this->assertFalse($service->canImport($viewer));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->stage($this->workbook('phones', [$this->phoneRow()]), $viewer, 'phones');
    }

    public function test_the_regional_ict_user_and_ict_manager_can_use_the_pages_and_the_buttons_show(): void
    {
        $manager = $this->makeUser('ICM1', 'ict_manager', $this->accra);

        foreach ([$this->ict, $manager] as $user) {
            $this->actingAs($user)->get(route('assets.import.phones'))->assertOk()->assertSee('Upload Phones')->assertSee('Download Template');
            $this->actingAs($user)->get(route('assets.import.network'))->assertOk()->assertSee('Upload Network');
        }

        $this->actingAs($this->ict)->get(route('assets.phones'))->assertOk()->assertSee('Upload phones')->assertSee(route('assets.import.phones'));
        $this->actingAs($this->ict)->get(route('assets.network'))->assertOk()->assertSee('Upload network devices')->assertSee(route('assets.import.network'));
        $this->actingAs($this->ict)->get(route('assets.assets'))->assertOk()->assertSee(route('assets.import'));
    }

    public function test_an_unknown_type_is_not_found(): void
    {
        $this->assertSame(404, $this->catch(fn () => AssetImportProfile::for('vehicles')));
    }

    protected function catch(callable $callback): int
    {
        try {
            $callback();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }

    // ---------------------------------------------------------------- Templates and the issue list

    /** @return array{0: Spreadsheet, 1: string} */
    protected function downloadTemplate(string $type): array
    {
        $response = $this->actingAs($this->ict)->get($this->uploadUrl($type, 'template'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tp').'.xlsx';
        file_put_contents($path, $response->baseResponse->getFile()->getContent());

        return [IOFactory::load($path), $path];
    }

    public function test_the_phone_and_network_templates_have_the_exact_headings_an_example_and_allowed_values(): void
    {
        foreach (['phones' => 'Samsung', 'network' => 'Cisco'] as $type => $maker) {
            [$book, $path] = $this->downloadTemplate($type);
            $profile = AssetImportProfile::for($type);

            $this->assertSame([$profile->sheetName, 'Allowed values', 'Instructions'], $book->getSheetNames());

            $data = $book->getSheet(0)->toArray();
            $this->assertSame($profile->headings(), array_values(array_filter($data[0])));
            $this->assertCount(2, $data, 'headings and ONE example row');
            $this->assertStringStartsWith('EXAMPLE - ', $data[1][0]);

            $allowed = json_encode($book->getSheet(1)->toArray());
            $this->assertStringContainsString($maker, $allowed);
            $this->assertStringContainsString('Accra Central', $allowed);
            $this->assertStringNotContainsString('Kumasi Central', $allowed, 'only the districts of the importer\'s own region');
            $this->assertStringContainsString($type === 'phones' ? 'SIM Card' : 'Access Point', $allowed);

            // The untouched template previews as "no data rows": the example is skipped, never imported.
            $this->preview($this->ict, $type, new UploadedFile($path, 'template.xlsx', null, null, true));
            $preview = session($profile->sessionKey);
            $this->assertSame(1, $preview['example_rows']);
            $this->assertFalse($preview['can_confirm']);
            $this->assertSame(0, IctAsset::query()->count());
        }

        [$phones] = $this->downloadTemplate('phones');
        $this->assertStringContainsString('serial number OR an IMEI', json_encode($phones->getSheet(2)->toArray()));
        [$network] = $this->downloadTemplate('network');
        $this->assertStringContainsString('Passwords are NOT imported', json_encode($network->getSheet(2)->toArray()));
    }

    public function test_a_filled_in_template_with_the_example_row_still_in_it_imports_only_the_real_rows(): void
    {
        [$book, $path] = $this->downloadTemplate('phones');
        $sheet = $book->getSheet(0);
        $sheet->setCellValueExplicit('A3', 'Real Phone From Template', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B3', 'Phone', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C3', 'Samsung', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('D3', 'Galaxy A15', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('E3', 'TPL-SERIAL-1', DataType::TYPE_STRING);
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->preview($this->ict, 'phones', new UploadedFile($path, 'filled.xlsx', null, null, true));
        $preview = session($this->key('phones'));
        $this->assertSame([1, 1], [$preview['example_rows'], $preview['valid_count']], json_encode($preview['errors']));

        $this->confirmImport($this->ict, 'phones')->assertSessionHasNoErrors();
        $this->assertSame(['Real Phone From Template'], IctAsset::query()->pluck('asset_name')->all());
    }

    public function test_the_issue_list_has_every_row_column_and_message_read_from_the_file(): void
    {
        config(['gwl.assets_import_max_rows' => 500]);
        $rows = [];

        for ($i = 0; $i < 210; $i++) {
            $rows[] = $this->phoneRow(['location' => 'Nowhere '.$i]);
        }

        $this->preview($this->ict, 'phones', $this->workbook('phones', $rows));
        $preview = session($this->key('phones'));
        $this->assertSame(210, $preview['error_count']);
        $this->assertCount(AssetImportService::MAX_LISTED_ISSUES, $preview['errors'], 'the screen keeps a capped list');

        $response = $this->actingAs($this->ict)->get($this->uploadUrl('phones', 'errors'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'is').'.xlsx';
        file_put_contents($path, $response->baseResponse->getFile()->getContent());
        $issues = IOFactory::load($path)->getSheet(0)->toArray();

        $this->assertSame(['Row', 'Column', 'Severity', 'Message'], $issues[0]);
        // 210 errors (one bad location each) and 210 "nobody is assigned" warnings: all of them, not only the first 200.
        $this->assertCount(421, $issues);
        $this->assertSame(210, collect($issues)->where(2, 'Error')->count());
        $this->assertSame(210, collect($issues)->where(2, 'Warning')->count());
        $this->assertSame(['2', 'Location', 'Error'], array_slice($issues[1], 0, 3));
        $this->assertStringContainsString('does not exist', $issues[1][3]);
    }

    public function test_clearing_an_upload_deletes_its_staged_file(): void
    {
        $this->preview($this->ict, 'network', $this->workbook('network', [$this->networkRow()]));
        $this->assertCount(1, Storage::disk('local')->files(AssetImportProfile::for('network')->directory));

        $this->actingAs($this->ict)->post($this->uploadUrl('network', 'clear'))->assertSessionHasNoErrors();

        $this->assertSame([], Storage::disk('local')->allFiles(AssetImportService::DIRECTORY));
        $this->assertNull(session($this->key('network')));
    }

    public function test_old_staged_files_of_every_type_are_purged_on_the_next_upload(): void
    {
        $old = AssetImportProfile::for('network')->directory.'/'.\Illuminate\Support\Str::uuid().'.xlsx';
        Storage::disk('local')->put($old, 'stale');
        touch(Storage::disk('local')->path($old), now()->subDays(2)->getTimestamp());

        $this->preview($this->ict, 'phones', $this->workbook('phones', [$this->phoneRow()]));

        $this->assertFalse(Storage::disk('local')->exists($old));
    }
}
