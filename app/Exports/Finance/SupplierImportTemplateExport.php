<?php

namespace App\Exports\Finance;

use App\Exports\Commercial\Sheets\ReportSheet;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The workbook a person fills in to bring suppliers in (SupplierImportService): a "Read me" and a "Suppliers" sheet of headings
 * only, no example rows, so nothing in a downloaded template can be imported by mistake. The four columns are formatted as TEXT
 * (a whole-column style, so no empty cells are written) so a phone number keeps its leading zero and a TIN is never turned into
 * a number.
 */
class SupplierImportTemplateExport implements Export, WithMultipleSheets
{
    /**
     * @param  list<string>  $headings
     * @param  list<array{0: string, 1: string}>  $readMe
     */
    public function __construct(protected array $headings, protected array $readMe) {}

    public function sheets(): array
    {
        $suppliers = new class('Suppliers', $this->headings, []) extends ReportSheet implements WithEvents
        {
            public function registerEvents(): array
            {
                return [
                    AfterSheet::class => function (AfterSheet $event): void {
                        $event->sheet->getDelegate()->getStyle('A:D')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                    },
                ];
            }
        };

        return [new ReportSheet('Read me', ['Topic', 'What to know'], $this->readMe), $suppliers];
    }
}
