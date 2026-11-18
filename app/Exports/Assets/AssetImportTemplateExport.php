<?php

namespace App\Exports\Assets;

use App\Exports\Assets\Sheets\ImportDataSheet;
use App\Exports\Assets\Sheets\SummarySheet;
use App\Models\IctAsset;
use App\Services\Assets\AssetImportProfile;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The upload template of one type: the sheet to fill in (exact headings, text cells, ONE example row marked "EXAMPLE - "
 * that the importer skips), an "Allowed values" sheet and an "Instructions" sheet. Built from the profile's columns, so
 * the template and the importer always agree on them.
 */
class AssetImportTemplateExport implements Export, WithMultipleSheets
{
    /** @param  array<string, list<string>>  $allowed  heading => values, from AssetImportService::allowedValues() */
    public function __construct(
        protected string $type = AssetImportProfile::ASSETS,
        protected array $allowed = [],
    ) {}

    public function sheets(): array
    {
        $profile = AssetImportProfile::for($this->type);
        $example = array_map(fn (array $column) => $column[2], array_values($profile->columns));
        $example[array_search('asset_name', array_keys($profile->columns), true)] = AssetImportProfile::EXAMPLE_PREFIX.$profile->columns['asset_name'][2].' (delete this row)';

        return [
            new ImportDataSheet($profile->sheetName, $profile->headings(), [$example]),
            new SummarySheet('Allowed values', array_keys($this->allowed), $this->transpose($this->allowed)),
            new SummarySheet('Instructions', ['Column', 'Required', 'What to enter'], self::instructionsFor($this->type)),
        ];
    }

    /**
     * Lists of different lengths as rows (a column per list, shorter ones padded).
     *
     * @param  array<string, list<string>>  $lists
     * @return list<list<string>>
     */
    protected function transpose(array $lists): array
    {
        $lists = array_values($lists);
        $height = $lists === [] ? 0 : max(array_map('count', $lists));
        $rows = [];

        for ($i = 0; $i < $height; $i++) {
            $rows[] = array_map(fn (array $list) => $list[$i] ?? '', $lists);
        }

        return $rows;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function instructionsFor(string $type): array
    {
        return match ($type) {
            AssetImportProfile::PHONES => self::phoneInstructions(),
            AssetImportProfile::NETWORK => self::networkInstructions(),
            default => self::instructions(),
        };
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function instructions(): array
    {
        $types = implode(', ', array_values(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_ASSET]));
        $statuses = implode(', ', IctAsset::STATUSES);

        return [
            ['Asset Name', 'Yes', 'Must be unique: no two assets may share a name (upper or lower case makes no difference), in the system or in this file.'],
            ['Asset Type', 'Yes', 'One of: '.$types.'.'],
            ['Manufacturer', 'Yes', 'As listed under Settings > Manufacturers. A manufacturer that does not exist is an error: add it first.'],
            ['Model', 'Yes', 'As listed under Settings > Models for that manufacturer and asset type. A model that does not exist is an error: add it first.'],
            ['Serial Number', 'Yes', 'Must be unique.'],
            ['Asset Tag', 'No', 'For example GWCL/FOE/YEAR/NUMBER. A tag already used by another asset only gives a warning.'],
            ['Department', 'Yes', 'The department name, exactly as it is in the system.'],
            ['Location', 'Yes', 'The district or office the asset belongs to. It must be in YOUR region; the region itself is always yours and cannot be set here.'],
            ['Actual Location', 'No', 'Where the asset physically is (a room, a counter, a vehicle).'],
            ['Status', 'No', 'One of: '.$statuses.'. Blank means Active.'],
            ['Status Reason', 'When Damaged', 'Why the asset is damaged (required for the Damaged status).'],
            ['Assigned To (Staff ID)', 'No', 'The staff ID of the person who holds it. Leave blank if nobody does yet (a laptop, desktop, printer, phone or SIM left blank is listed as Unassigned).'],
            ['General Use', 'No', 'Yes for a shared asset with no holder. Blank means No.'],
            ['Purchase Date', 'No', 'YYYY-MM-DD (for example 2025-03-14).'],
            ['Warranty Expires', 'No', 'YYYY-MM-DD.'],
            ['', '', ''],
            ['General rules', '', self::generalRules('assets', 'Phones and network devices have their own upload (Assets > Upload > Phones / Network).')],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function phoneInstructions(): array
    {
        $types = implode(', ', array_values(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_PHONE]));
        $statuses = implode(', ', IctAsset::STATUSES);

        return [
            ['Asset Name', 'Yes', 'Must be unique: no two assets may share a name (upper or lower case makes no difference), in the system or in this file.'],
            ['Device Type', 'Yes', 'One of: '.$types.'.'],
            ['Manufacturer', 'Phone: yes', 'As listed under Settings > Manufacturers. Required for a Phone; optional for a POS Terminal or SIM Card (but give it together with the Model). A manufacturer that does not exist is an error: add it first.'],
            ['Model', 'Phone: yes', 'As listed under Settings > Models for that manufacturer and device type. A model that does not exist is an error: add it first.'],
            ['Serial Number', 'Serial or IMEI', 'A phone needs a serial number OR an IMEI (or both). Must not repeat in the file or in the system. Format the column as Text.'],
            ['IMEI', 'Serial or IMEI', 'Usually 15 digits. Must not repeat in the file or in the system. Format the column as Text, or leading zeros and long numbers are damaged by Excel.'],
            ['Status', 'No', 'One of: '.$statuses.'. Blank means Active.'],
            ['Status Reason', 'When Damaged', 'Why the device is damaged (required for the Damaged status).'],
            ['Assigned To (Staff ID)', 'No', 'The staff ID of the person who holds it. The person must be an active employee you can see. Nobody is ever created from this file.'],
            ['General Use', 'No', 'Yes for a shared device (a POS terminal at a counter) with no holder. Blank means No.'],
            ['Location', 'No', 'The district or office. It must be in YOUR region. Left blank, it is the holder\'s district (when that is in your region); a location typed here always wins.'],
            ['User Phone Number', 'No', 'The holder\'s number. Format as Text so the leading 0 stays.'],
            ['Device Phone Number', 'No', 'The number of the SIM in the device. Format as Text.'],
            ['Purchase Date', 'No', 'YYYY-MM-DD (for example 2025-03-14).'],
            ['', '', ''],
            ['General rules', '', self::generalRules('phones')],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function networkInstructions(): array
    {
        $types = implode(', ', array_values(IctAsset::ASSET_TYPES[IctAsset::DEVICE_CATEGORY_NETWORK]));
        $statuses = implode(', ', IctAsset::STATUSES);

        return [
            ['Asset Name', 'Yes', 'Must be unique: no two assets may share a name (upper or lower case makes no difference), in the system or in this file.'],
            ['Device Type', 'Yes', 'One of: '.$types.'.'],
            ['Manufacturer', 'No', 'As listed under Settings > Manufacturers. Give it together with the Model. A manufacturer that does not exist is an error: add it first.'],
            ['Model', 'No', 'As listed under Settings > Models for that manufacturer and device type. A model that does not exist is an error: add it first.'],
            ['Serial Number', 'No', 'Must not repeat in the file or in the system. Format the column as Text.'],
            ['MAC Address', 'No', '12 hexadecimal digits, with or without : or - between pairs (AA:BB:CC:DD:EE:FF). Must not repeat in the file or in the system. It is stored as AA:BB:CC:DD:EE:FF.'],
            ['IP Address', 'No', 'A valid IPv4 or IPv6 address. When the location has IP ranges set up, the address must be inside one of them.'],
            ['SSID', 'No', 'The Wi-Fi network name, if the device broadcasts one.'],
            ['Device Username', 'No', 'The administration login name. Passwords are NOT imported: enter them on the Add Network Device form.'],
            ['Location', 'No', 'The district or office. It must be in YOUR region.'],
            ['Actual Location', 'No', 'Where the device physically is (a room, a pole, a cabinet).'],
            ['Status', 'No', 'One of: '.$statuses.'. Blank means Active.'],
            ['Status Reason', 'When Damaged', 'Why the device is damaged (required for the Damaged status).'],
            ['', '', ''],
            ['General rules', '', self::generalRules('network devices')],
        ];
    }

    protected static function generalRules(string $what, string $extra = ''): string
    {
        return 'This upload only CREATES '.$what.'; it never changes or deletes one. Row 2 of the template is an EXAMPLE (its name starts with "'.AssetImportProfile::EXAMPLE_PREFIX.'") and is ignored: replace it with your own data. Preview first, then confirm. Do not use formulas: type the values. Format identifier columns (serial numbers, IMEI, MAC, phone numbers) as Text so leading zeros are kept. One file: .xlsx only, up to '
            .config('gwl.assets_import_max_rows', 1000).' rows and '.config('gwl.assets_import_max_mb', 5).' MB. '.$extra;
    }
}
