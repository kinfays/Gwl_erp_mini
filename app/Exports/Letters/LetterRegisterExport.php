<?php

namespace App\Exports\Letters;

use App\Services\Letters\LetterRegisterService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * A holder's letter register as a spreadsheet. Subjects, senders and remarks are typed by people, and the default
 * value binder turns a cell that starts with "=" into a formula, so this class is also the value binder: text stays
 * text (numbers such as the row number stay numbers).
 */
class LetterRegisterExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /** @param  Collection<int, array<string, mixed>>  $rows  the rows of LetterRegisterService::rows() */
    public function __construct(protected Collection $rows)
    {
        $this->setNumericConversion(false); // keep the No. column numeric; everything else, formulas included, is stored as a string
    }

    public function collection(): Collection
    {
        $register = app(LetterRegisterService::class);

        return $this->rows->map(fn (array $row) => array_values($register->cells($row)));
    }

    public function headings(): array
    {
        return array_values(app(LetterRegisterService::class)->columns());
    }
}
