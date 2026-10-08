<?php

namespace App\Services\HealthSafety\Concerns;

use App\Imports\RawRowsImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reading an Excel sheet of rows for the Health & Safety imports (equipment, PPE issues): the file as raw rows, tolerant
 * headings, tidy cell text and dates. The cell handling was copied from the Credit Union deduction import, not shared with
 * it; the two Health & Safety imports share this.
 */
trait ReadsImportFiles
{
    protected function readRows(UploadedFile $file): Collection
    {
        $import = new RawRowsImport;
        Excel::import($import, $file);

        return $import->rows;
    }

    protected function mapRow(array $headings, $row): array
    {
        $values = collect($row instanceof Collection ? $row->all() : (array) $row)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->values()
            ->all();

        $mapped = [];

        foreach ($headings as $index => $heading) {
            if (! $heading) {
                continue;
            }

            $mapped[$heading] = $values[$index] ?? null;
        }

        return $mapped;
    }

    protected function text(mixed $value): string
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

        return trim((string) $value);
    }

    protected function normalizeHeading(mixed $value): string
    {
        return Str::of((string) $value)->trim()->lower()->replace([' ', '-'], '_')->value();
    }

    protected function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty();
    }

    /**
     * A real Excel date (a number in the date range) or dd/MM/yyyy text (also dd-MM-yyyy, dd.MM.yyyy) or yyyy-MM-dd.
     * Anything else is an error rather than a guess, including two-digit years, month names and other number formats.
     *
     * @return array{0: string|null, 1: string|null} the date as Y-m-d, and the problem if there is one
     */
    protected function parseDate(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        if ($value instanceof \DateTimeInterface) {
            return [Carbon::instance($value)->toDateString(), null];
        }

        // A number: only one that falls where an Excel date does (1954 to 2119) is a date.
        if (is_int($value) || is_float($value)) {
            if ($value >= 20000 && $value <= 80000) {
                return [Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString(), null];
            }

            return [null, 'the number '.$value.' is not a date. Use dd/MM/yyyy.'];
        }

        $text = trim((string) $value);

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $text, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? [sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), null]
                : [null, '"'.$text.'" is not a real date (dates are dd/MM/yyyy).'];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]00:00:00)?$/', $text, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), null]
                : [null, '"'.$text.'" is not a real date.'];
        }

        return [null, '"'.$text.'" is not a date this import can read. Use dd/MM/yyyy or a real Excel date.'];
    }
}
