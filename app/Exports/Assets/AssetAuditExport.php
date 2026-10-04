<?php

namespace App\Exports\Assets;

use App\Services\Assets\AssetAuditService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * One completed audit as a spreadsheet. Asset names, locations and reasons are typed by people, and the default value
 * binder turns a cell starting with "=" into a formula, so this class is also the value binder: text stays text
 * (the row number stays numeric).
 */
class AssetAuditExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /** @param  Collection<int, array<string, mixed>>  $rows  the rows of AssetAuditService::exportRows() */
    public function __construct(protected Collection $rows)
    {
        $this->setNumericConversion(false);
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => array_values($row));
    }

    public function headings(): array
    {
        return array_values(app(AssetAuditService::class)->columns());
    }
}
