<?php

namespace App\Services\Commercial;

use App\Imports\Commercial\SheetGridsImport;
use App\Models\CommercialImportBatch;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Shared helpers for the two report importers: reading every sheet as a raw grid, recognising which report a workbook
 * is, and reading cells by LABEL (never by fixed coordinates, so a column that shifts by one cannot silently load the
 * wrong numbers).
 *
 * The cell clean-up mirrors DeductionImportService::normalizeCellText()/normalizeAmount() on purpose: that service is
 * left alone rather than refactored.
 */
class ReportFileReader
{
    /** Rows scanned for the title / filter block at the top of a sheet. */
    public const HEADER_SCAN_ROWS = 40;

    /**
     * @return list<array{name: string, rows: array<int, array<int, mixed>>}>
     */
    public function readSheets(UploadedFile $file): array
    {
        // Sheet names come from the workbook's index alone (cheap), so the unreadable "Document map" is never loaded.
        $names = IOFactory::createReaderForFile($file->getRealPath())->listWorksheetNames($file->getRealPath());

        $import = new SheetGridsImport($names, fn (string $name): bool => (bool) preg_match('/^\s*document\s*map\s*$/i', $name));
        Excel::import($import, $file);

        ksort($import->sheets);

        return array_values($import->sheets);
    }

    public function fileHash(UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getRealPath());
    }

    /**
     * Which report a workbook is, from the title text near the top of its first sheets, or null when it is neither.
     *
     * @param  list<array{name: string, rows: array<int, array<int, mixed>>}>  $sheets
     */
    public function detectType(array $sheets): ?string
    {
        foreach (array_slice($sheets, 0, 4) as $sheet) {
            foreach (array_slice($sheet['rows'], 0, self::HEADER_SCAN_ROWS) as $row) {
                foreach ($row as $cell) {
                    $text = $this->text($cell);

                    if ($text === '') {
                        continue;
                    }

                    if (preg_match('/customer\s+meter\s+reading\s+report/i', $text)) {
                        return CommercialImportBatch::TYPE_READING_SUMMARY;
                    }

                    if (preg_match('/billing\s+summary\s+report/i', $text)) {
                        return CommercialImportBatch::TYPE_BILLING_SUMMARY;
                    }
                }
            }
        }

        return null;
    }

    public function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    /**
     * A number from a cell, or null when the cell is blank or not a number (#VALUE!, #DIV/0!, free text). Accounting
     * formats are accepted: thousands separators, a currency sign, (12.00) for negatives and a lone dash for zero.
     */
    public function amount(mixed $value): ?float
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? round((float) $value, 2) : null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (in_array($text, ['-', '--', '–'], true)) {
            return 0.0;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $matches)) {
            $negative = true;
            $text = $matches[1];
        }

        $cleaned = str_replace([',', ' ', "\u{00A0}", 'GH¢', 'GHS', '¢'], '', $text);

        if (! is_numeric($cleaned)) {
            return null;
        }

        $number = round((float) $cleaned, 2);

        return $negative ? -$number : $number;
    }

    public function integer(mixed $value): ?int
    {
        $amount = $this->amount($value);

        return $amount === null ? null : (int) round($amount);
    }

    /** Upper-cased, whitespace-collapsed text, the form labels are compared in. */
    public function normalizeLabel(?string $value): string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return mb_strtoupper($text);
    }

    /**
     * The value that goes with a label in the filter block: the rest of the same cell after a colon ("REGION: ACCRA
     * WEST"), otherwise the next non-empty cell to its right. $labelPattern matches the whole label text and may capture
     * the remainder in group 1.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function labelValue(array $rows, string $labelPattern, int $scanRows = self::HEADER_SCAN_ROWS): ?string
    {
        foreach (array_slice($rows, 0, $scanRows) as $row) {
            foreach ($row as $index => $cell) {
                // The filter block is often ONE cell holding several lines ("REPORT PERIOD: ...\nREGION: ...").
                foreach (preg_split('/\R/u', $this->text($cell)) ?: [] as $line) {
                    $line = trim($line);

                    if ($line === '' || ! preg_match($labelPattern, $line, $matches)) {
                        continue;
                    }

                    $inline = trim(ltrim(trim($matches[1] ?? ''), ':'));

                    if ($inline !== '') {
                        return $inline;
                    }

                    for ($next = $index + 1; $next < count($row); $next++) {
                        $candidate = trim(ltrim($this->text($row[$next]), ':'));

                        if ($candidate !== '') {
                            return $candidate;
                        }
                    }

                    return null;
                }
            }
        }

        return null;
    }

    /**
     * The month a label cell stands for ("Mon 01-Jun-2026", "June-2026", a real date), as the first of that month.
     * A bare number is only read as an Excel date serial when $allowSerial is set, because counts look like serials.
     */
    public function parseMonth(mixed $value, bool $allowSerial = false): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfMonth()->startOfDay();
        }

        if ($allowSerial && (is_int($value) || is_float($value)) && $value >= 43831 && $value <= 54789 && floor($value) === (float) $value) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->startOfMonth()->startOfDay();
        }

        if (! is_string($value)) {
            return null;
        }

        return $this->monthsIn($value)[0] ?? null;
    }

    /**
     * Every month-and-year pair in a piece of text, in order, each as the first of that month: "June-2026 - August-2026"
     * gives June and August 2026, "Mon 01-Jun-2026" gives June 2026. Words that are not month names are ignored.
     *
     * @return list<Carbon>
     */
    public function monthsIn(string $text): array
    {
        preg_match_all('/([A-Za-z]{3,9})\.?[-\s\/,]*(\d{4})(?!\d)/', $text, $matches, PREG_SET_ORDER);

        $months = [];

        foreach ($matches as $match) {
            $number = $this->monthNumber($match[1]);

            if ($number !== null) {
                $months[] = Carbon::create((int) $match[2], $number, 1)->startOfDay();
            }
        }

        return $months;
    }

    public function monthNumber(string $name): ?int
    {
        if (! preg_match('/^(jan(uary)?|feb(ruary)?|mar(ch)?|apr(il)?|may|june?|july?|aug(ust)?|sep(t(ember)?)?|oct(ober)?|nov(ember)?|dec(ember)?)$/i', $name)) {
            return null;
        }

        return array_search(strtolower(substr($name, 0, 3)), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true) + 1;
    }

    /**
     * Index of the first non-empty cell of a row, or null for a blank row.
     *
     * @param  array<int, mixed>  $row
     */
    public function firstFilledIndex(array $row): ?int
    {
        foreach ($row as $index => $cell) {
            if ($this->text($cell) !== '') {
                return $index;
            }
        }

        return null;
    }
}
