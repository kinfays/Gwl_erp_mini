<?php

namespace App\Exports\GWater;

use App\Exports\Commercial\Sheets\ReportSheet;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The workbook a location fills in to bring its past records in (GwaterImportService): a "Read me" and one sheet per kind of
 * record, headings only. There are deliberately no example rows, so nothing in a downloaded template can be imported by mistake.
 */
class GwaterImportTemplateExport implements Export, WithMultipleSheets
{
    /**
     * @param  array<string, array{title: string, headings: list<string>}>  $sheets
     * @param  list<array{0: string, 1: string}>  $readMe
     */
    public function __construct(protected array $sheets, protected array $readMe) {}

    public function sheets(): array
    {
        $out = [new ReportSheet('Read me', ['Topic', 'What to know'], $this->readMe)];

        foreach ($this->sheets as $sheet) {
            $out[] = new ReportSheet($sheet['title'], $sheet['headings'], []);
        }

        return $out;
    }
}
