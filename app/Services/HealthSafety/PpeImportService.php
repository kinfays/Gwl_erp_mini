<?php

namespace App\Services\HealthSafety;

use App\Exports\ImportTemplateExport;
use App\Models\HsPpeIssue;
use App\Models\HsPpeType;
use App\Models\User;
use App\Services\HealthSafety\Concerns\ReadsImportFiles;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bring in what staff ALREADY hold, from Excel, so a new system does not start by flagging everyone as missing PPE they
 * have had for years. Every row becomes a historic ("already held") issue: it records who holds what since when, takes
 * nothing from any store and posts no ledger line. Create-only. Same shape as the equipment import: preview, then confirm,
 * with confirm reading the file again on the server; blocked above gwl.max_import_failure_percent of rows with errors.
 *
 * Columns: staff_id, ppe_type (the type's name), size, quantity, issued_on, expires_on (optional). Staff must be active and
 * in the importer's own region (or any, for someone who sees every region).
 */
class PpeImportService
{
    use ReadsImportFiles;

    public const HEADINGS = ['staff_id', 'ppe_type', 'size', 'quantity', 'issued_on', 'expires_on'];

    protected const REQUIRED = ['staff_id', 'ppe_type', 'quantity', 'issued_on'];

    /** A guard against an enormous upload: the file is read into memory. */
    public const MAX_ROWS = 3000;

    public function __construct(
        protected EquipmentScope $scope,
        protected PpeIssueService $issues,
    ) {}

    /** A template with an invented example row. */
    public function templateExport(): ImportTemplateExport
    {
        return new ImportTemplateExport(self::HEADINGS, [
            ['100001', 'Example boots', '42', '1', '15/03/2025', ''],
        ]);
    }

    /** @return array<string, mixed> */
    public function preview(UploadedFile $file, User $actor): array
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_ppe'), 403, 'You may not import PPE issues.');

        $max = (int) config('gwl.max_import_failure_percent', 20);
        $empty = [
            'headings' => self::HEADINGS, 'valid_rows' => [], 'errors' => [], 'warnings' => [], 'unknown_types' => [],
            'total_rows' => 0, 'valid_count' => 0, 'error_count' => 0, 'error_rows' => 0, 'warning_count' => 0, 'skipped_count' => 0,
            'failure_percent' => 0.0, 'max_failure_percent' => $max, 'blocked' => false,
        ];

        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            return [...$empty, 'errors' => [['row' => 'File', 'message' => 'The uploaded file is empty.']], 'error_count' => 1, 'blocked' => true];
        }

        $headings = collect($rows->shift() ?? [])->map(fn ($value) => $this->normalizeHeading($value))->values()->all();
        $missing = array_diff(self::REQUIRED, $headings);

        if ($missing !== []) {
            return [...$empty, 'headings' => $headings, 'errors' => [['row' => 'Header', 'message' => 'Missing required columns: '.implode(', ', $missing).'.']], 'error_count' => 1, 'blocked' => true];
        }

        $mapped = $rows->values()->map(fn ($row) => $this->mapRow($headings, $row))->reject(fn (array $row) => $this->isEmptyRow($row))->values();

        if ($mapped->count() > self::MAX_ROWS) {
            return [...$empty, 'headings' => $headings, 'errors' => [['row' => 'File', 'message' => 'The file has more than '.self::MAX_ROWS.' rows. Split it and import the parts one at a time.']], 'error_count' => 1, 'blocked' => true];
        }

        $types = HsPpeType::query()->active()->get()->keyBy(fn (HsPpeType $type) => $this->key($type->name));
        $staff = [];
        $errors = [];
        $warnings = [];
        $valid = [];
        $unknownTypes = [];
        $errorRows = 0;
        $skipped = 0;

        foreach ($mapped as $index => $row) {
            $rowNumber = $index + 2;
            $problems = [];

            $staffId = $this->text($row['staff_id'] ?? null);
            $staff[$staffId] ??= $staffId === '' ? null : $this->scope->employees($actor)->where('staff_id', $staffId)->first(['id', 'staff_id', 'full_name']);
            $employee = $staff[$staffId];

            if (! $employee) {
                $problems[] = $staffId === '' ? 'No staff ID given.' : 'Staff ID '.$staffId.' was not found among active staff in your region.';
            }

            $typeName = $this->text($row['ppe_type'] ?? null);
            $type = $types[$this->key($typeName)] ?? null;

            if (! $type) {
                $problems[] = 'Unknown PPE type "'.$typeName.'". Add it under PPE types first.';

                if ($typeName !== '') {
                    $unknownTypes[$this->key($typeName)] ??= $typeName;
                }
            }

            $size = $this->text($row['size'] ?? null);

            if ($type) {
                if ($type->has_sizes && ($size === '' || ! in_array($size, $type->sizeList(), true))) {
                    $problems[] = ($size === '' ? 'Size is missing' : 'Size "'.$size.'" is not one of the sizes').' for '.$type->name.' ('.implode(', ', $type->sizeList()).').';
                } elseif (! $type->has_sizes && $size !== '') {
                    $problems[] = $type->name.' does not come in sizes.';
                }
            }

            $quantityText = $this->text($row['quantity'] ?? null);

            if (! ctype_digit($quantityText) || (int) $quantityText < 1) {
                $problems[] = 'Quantity must be a whole number of at least 1.';
            }

            [$issuedOn, $issuedProblem] = $this->parseDate($row['issued_on'] ?? null);

            if ($issuedProblem) {
                $problems[] = 'issued on: '.$issuedProblem;
            } elseif ($issuedOn === null) {
                $problems[] = 'issued on: enter the date it was given out.';
            } elseif (Carbon::parse($issuedOn)->gt(today())) {
                $problems[] = 'issued on cannot be in the future.';
            }

            [$expiresOn, $expiresProblem] = $this->parseDate($row['expires_on'] ?? null);

            if ($expiresProblem) {
                $problems[] = 'expires on: '.$expiresProblem;
            } elseif ($type && $type->has_expiry && $expiresOn === null) {
                $problems[] = $type->name.' has an expiry date: expires_on is needed.';
            } elseif ($expiresOn !== null && $issuedOn !== null && Carbon::parse($expiresOn)->lt(Carbon::parse($issuedOn))) {
                $problems[] = 'expires on cannot be before it was issued.';
            }

            if ($problems !== []) {
                $errorRows++;

                foreach ($problems as $message) {
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                }

                continue;
            }

            // The same holding twice (the file uploaded again) is skipped, not doubled.
            $already = HsPpeIssue::query()->open()
                ->where('employee_id', $employee->id)->where('ppe_type_id', $type->id)
                ->where('quantity', (int) $quantityText)->whereDate('issued_on', $issuedOn)
                ->when($size === '', fn ($query) => $query->whereNull('size'), fn ($query) => $query->where('size', $size))
                ->exists();

            if ($already) {
                $skipped++;
                $warnings[] = ['row' => $rowNumber, 'message' => 'Already recorded: '.$employee->full_name.' holds this since '.Carbon::parse($issuedOn)->format('d/m/Y').'. Skipped.'];

                continue;
            }

            $valid[] = [
                'employee_id' => $employee->id,
                'ppe_type_id' => $type->id,
                'size' => $size === '' ? null : $size,
                'quantity' => (int) $quantityText,
                'issued_on' => $issuedOn,
                'expires_on' => $type->has_expiry ? $expiresOn : null,
                'is_historic' => true,
            ];
        }

        $total = $mapped->count();
        $failurePercent = $total > 0 ? round(($errorRows / $total) * 100, 1) : 0.0;

        return [
            ...$empty,
            'valid_rows' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'unknown_types' => array_values($unknownTypes),
            'total_rows' => $total,
            'valid_count' => count($valid),
            'error_count' => count($errors),
            'error_rows' => $errorRows,
            'warning_count' => count($warnings),
            'skipped_count' => $skipped,
            'failure_percent' => $failurePercent,
            'blocked' => $failurePercent > $max,
        ];
    }

    /**
     * Confirm: read the file again, refuse a blocked run, and record every usable row as an "already held" issue in one
     * transaction.
     *
     * @return array{created: int, skipped: int, error_rows: int, total_rows: int}
     */
    public function import(UploadedFile $file, User $actor): array
    {
        $preview = $this->preview($file, $actor);

        if ($preview['blocked']) {
            throw ValidationException::withMessages(['file' => $preview['total_rows'] === 0
                ? $preview['errors'][0]['message']
                : sprintf('Import blocked because %.1f%% of rows have errors. The configured maximum is %d%%.', $preview['failure_percent'], $preview['max_failure_percent'])]);
        }

        if ($preview['valid_rows'] === []) {
            throw ValidationException::withMessages(['file' => 'There are no usable rows to import.']);
        }

        $created = DB::transaction(function () use ($preview, $actor) {
            foreach ($preview['valid_rows'] as $row) {
                $this->issues->issue($actor, $row, [], audit: false);
            }

            return count($preview['valid_rows']);
        });

        Audit::log('health_safety.ppe_issues_imported', 'health_safety', null, null, [
            'file' => mb_substr($file->getClientOriginalName(), 0, 150),
            'created' => $created,
            'skipped_existing' => $preview['skipped_count'],
            'error_rows' => $preview['error_rows'],
            'total_rows' => $preview['total_rows'],
        ]);

        return ['created' => $created, 'skipped' => $preview['skipped_count'], 'error_rows' => $preview['error_rows'], 'total_rows' => $preview['total_rows']];
    }

    protected function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }
}
