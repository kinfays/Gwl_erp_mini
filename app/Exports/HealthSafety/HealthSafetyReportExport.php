<?php

namespace App\Exports\HealthSafety;

use App\Exports\Commercial\Sheets\ReportSheet;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Any Health & Safety report as a workbook: a "Notes" sheet (what it is, who took it, when, how many rows and the caveats)
 * and the table. The sheets are the Commercial module's ReportSheet, a string value binder: text that starts with "=", "+",
 * "-" or "@" stays text and is never turned into a formula, so a description or a name cannot run anything in Excel.
 */
class HealthSafetyReportExport implements Export, WithMultipleSheets
{
    /**
     * @param  array{title: string, headings: list<string>, rows: list<list<mixed>>, count: int, notes: list<string>}  $report
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        protected array $report,
        protected string $generatedBy,
        protected Carbon $generatedAt,
        protected array $filters = [],
    ) {}

    public function sheets(): array
    {
        $notes = [
            ['Report', $this->report['title']],
            ['Taken by', $this->generatedBy],
            ['Generated', $this->generatedAt->format('d M Y, H:i')],
            ['Rows', $this->report['count']],
            ['Filters', $this->filters === [] ? 'None' : collect($this->filters)->map(fn ($value, $key) => $key.': '.(is_scalar($value) ? $value : json_encode($value)))->implode('; ')],
        ];

        foreach ($this->report['notes'] ?? [] as $note) {
            $notes[] = ['Note', $note];
        }

        return [
            new ReportSheet('Notes', ['Item', 'Detail'], $notes),
            new ReportSheet($this->report['title'], $this->report['headings'], $this->report['rows']),
        ];
    }
}
