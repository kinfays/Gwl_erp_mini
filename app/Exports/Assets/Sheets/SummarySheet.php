<?php

namespace App\Exports\Assets\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * One sheet of the summary workbook. Asset names, serials and employee names are typed by people, and the default
 * value binder turns a cell starting with "=" into a formula, so the sheet is also the value binder: text stays text
 * and numbers stay numbers.
 */
class SummarySheet extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithTitle
{
    public function __construct(
        protected string $title,
        protected array $headings,
        protected array $rows,
    ) {
        $this->setNumericConversion(false);
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        // Excel sheet names: at most 31 characters and none of \ / ? * [ ] :
        return mb_substr(str_replace(['&'], 'and', $this->title), 0, 31);
    }
}
