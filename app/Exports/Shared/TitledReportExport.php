<?php

namespace App\Exports\Shared;

use App\Exports\Shared\Sheets\ReportSheet;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * A titled-tables report as a workbook: a "Notes" sheet (what it is, for which location and period, when, and the caveats)
 * followed by one sheet per table. Used by the G-Water and Finance/Audit exports; the same array feeds the PDF page
 * `exports.report-pdf`.
 */
class TitledReportExport implements Export, WithMultipleSheets
{
    /** @param array<string, mixed> $report {title, meta, row_count, notes, tables: [{title, headings, rows}]} */
    public function __construct(protected array $report, protected Carbon $generatedAt) {}

    public function sheets(): array
    {
        $rows = [['Report', $this->report['title']]];

        foreach ($this->report['meta'] as $label => $value) {
            $rows[] = [$label, (string) $value];
        }

        $rows[] = ['Generated', $this->generatedAt->format('d M Y, H:i')];
        $rows[] = ['Rows', $this->report['row_count']];

        foreach ($this->report['notes'] as $note) {
            $rows[] = ['Note', $note];
        }

        $sheets = [new ReportSheet('Notes', ['Item', 'Detail'], $rows)];
        $titles = ['notes' => true];

        foreach ($this->report['tables'] as $table) {
            $sheet = new ReportSheet($table['title'], $table['headings'], $table['rows']);
            $title = $sheet->title();

            // Sheet names must be unique, and truncation can make two the same.
            for ($i = 2; isset($titles[strtolower($title)]); $i++) {
                $title = mb_substr($sheet->title(), 0, 28).' '.$i;
            }

            $titles[strtolower($title)] = true;
            $sheets[] = $title === $sheet->title() ? $sheet : new ReportSheet($title, $table['headings'], $table['rows']);
        }

        return $sheets;
    }
}
