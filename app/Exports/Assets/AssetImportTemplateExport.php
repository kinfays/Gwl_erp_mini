<?php

namespace App\Exports\Assets;

use App\Exports\Assets\Sheets\ImportDataSheet;
use App\Exports\Assets\Sheets\SummarySheet;
use App\Models\IctAsset;
use App\Services\Assets\AssetImportService;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The asset upload template: the "Assets" sheet to fill in (headings and two sample rows) and a short "Instructions"
 * sheet. Built from AssetImportService::COLUMNS, so the template and the importer always agree on the columns.
 */
class AssetImportTemplateExport implements Export, WithMultipleSheets
{
    public function sheets(): array
    {
        $columns = AssetImportService::COLUMNS;
        $headings = array_map(fn (array $column) => $column[0], array_values($columns));
        $example = array_map(fn (array $column) => $column[2], array_values($columns));

        $second = $example;
        $second[array_search('asset_name', array_keys($columns), true)] = 'Reception Printer';
        $second[array_search('asset_type', array_keys($columns), true)] = 'Printer';
        $second[array_search('manufacturer', array_keys($columns), true)] = 'HP';
        $second[array_search('model', array_keys($columns), true)] = 'LaserJet Pro M404dn';
        $second[array_search('serial_number', array_keys($columns), true)] = 'PRT0099887';
        $second[array_search('asset_tag', array_keys($columns), true)] = 'GWCL/FOE/2026/002';
        $second[array_search('assigned_to', array_keys($columns), true)] = '';
        $second[array_search('general_use', array_keys($columns), true)] = 'Yes';

        return [
            new ImportDataSheet('Assets', $headings, [$example, $second]),
            new SummarySheet('Instructions', ['Column', 'Required', 'What to enter'], self::instructions()),
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function instructions(): array
    {
        $types = implode(', ', array_values(IctAsset::ASSET_TYPES[AssetImportService::CATEGORY]));
        $statuses = implode(', ', IctAsset::STATUSES);

        $rows = [
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
            ['General rules', '', 'This upload only CREATES assets; it never changes or deletes one. Preview first, then confirm. Do not use formulas: type the values. Format the Serial Number and Asset Tag columns as Text so leading zeros are kept. One file: .xlsx only, up to '.config('gwl.assets_import_max_rows', 1000).' rows and '.config('gwl.assets_import_max_mb', 5).' MB. Phones, SIM cards and network devices are added on their own screens.'],
        ];

        return $rows;
    }
}
