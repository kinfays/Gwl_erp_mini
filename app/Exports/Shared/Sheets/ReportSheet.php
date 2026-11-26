<?php

namespace App\Exports\Commercial\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * One sheet of a Commercial export. Reader names, district labels and route codes are text from an uploaded file, and the
 * default value binder turns a cell that starts with "=", "+", "-" or "@" into a formula, so the sheet is also the value
 * binder: text stays text (numbers stay numbers).
 */
class ReportSheet extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithTitle
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int|float>>  $rows
     */
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
        return mb_substr(trim(str_replace(['&', '\\', '/', '?', '*', '[', ']', ':'], ['and', ' ', ' ', '', '', '(', ')', ' '], $this->title)), 0, 31);
    }
}
