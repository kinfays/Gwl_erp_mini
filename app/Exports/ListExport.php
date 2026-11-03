<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Any list as a spreadsheet: headings plus rows the caller has already built as plain arrays in heading order. Shared by
 * every module (the one export class that is not namespaced per module). Names, serials and notes are typed by people,
 * and the default value binder turns a cell starting with "=" into a formula, so this class is also the value binder:
 * every cell stays text.
 */
class ListExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /**
     * @param  array<int, string>  $headings
     * @param  Collection<int, array<int, string|int|float|null>>  $rows
     */
    public function __construct(protected array $headings, protected Collection $rows)
    {
        $this->setNumericConversion(false);
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => array_values($row));
    }

    public function headings(): array
    {
        return $this->headings;
    }
}
