<?php

namespace App\Exports\Assets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * The Maintenance log as a spreadsheet. Asset names, serials, technicians and notes are typed by people, and the default
 * value binder turns a cell that starts with "=", "+", "-" or "@" into a formula, so this class is also the value binder:
 * every cell stays text.
 */
class MaintenanceExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    use Exportable;

    /**
     * @param  array<int, string>  $headings
     * @param  Collection<int, array<int, string>>  $rows  one array per record, in heading order
     */
    public function __construct(protected array $headings, protected Collection $rows)
    {
        $this->setNumericConversion(false);
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row): array => array_values($row));
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return $this->headings;
    }
}
