<?php

namespace App\Exports\Assets\Sheets;

use App\Services\Assets\AssetImportService;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** The fill-in sheet of the asset upload template: every cell text, so serial numbers and tags keep leading zeros. */
class ImportDataSheet extends SummarySheet implements WithColumnFormatting
{
    /** @return array<string, string> */
    public function columnFormats(): array
    {
        $formats = [];

        foreach (array_keys(AssetImportService::COLUMNS) as $index => $key) {
            $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_TEXT;
        }

        return $formats;
    }
}
