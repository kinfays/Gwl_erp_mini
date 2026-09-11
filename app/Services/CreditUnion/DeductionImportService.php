<?php

namespace App\Services\CreditUnion;

use App\Exports\ImportTemplateExport;
use App\Imports\RawRowsImport;
use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Parses the monthly payroll-deduction file GWCL sends with its bulk remittance and
 * matches every row back to a credit union member. Mirrors the preview/validate/
 * failure-threshold shape of App\Services\Import\DataImportService.
 */
class DeductionImportService
{
    public const HEADINGS = [
        'staff_id',
        'name',
        'shares_amount',
        'savings_amount',
        'loan_repayment_amount',
    ];

    public function templateExport(): ImportTemplateExport
    {
        return new ImportTemplateExport(self::HEADINGS, [
            ['100001', 'Akosua Mensah', '50.00', '120.00', '85.00'],
            ['100002', 'Kwame Boateng', '50.00', '200.00', '0'],
        ]);
    }

    public function preview(UploadedFile $file): array
    {
        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            return $this->withThreshold([
                'headings' => self::HEADINGS,
                'preview_rows' => [],
                'valid_rows' => [],
                'errors' => [['row' => 'File', 'message' => 'The uploaded file is empty.']],
                'warnings' => [],
                'total_rows' => 0,
                'valid_count' => 0,
                'error_count' => 1,
                'warning_count' => 0,
                'postable_count' => 0,
                'postable_total' => 0.0,
                'loan_repayment_total' => 0.0,
            ]);
        }

        $headings = collect($rows->shift() ?? [])
            ->map(fn ($value) => $this->normalizeHeading($value))
            ->values()
            ->all();

        $missingHeadings = array_diff(self::HEADINGS, $headings);
        $errors = [];

        if (! empty($missingHeadings)) {
            $errors[] = [
                'row' => 'Header',
                'message' => 'Missing required columns: '.implode(', ', $missingHeadings),
            ];
        }

        $previewRows = [];
        $validRows = [];
        $warnings = [];
        $processedRows = 0;
        $seenStaffIds = [];

        foreach ($rows->values() as $index => $row) {
            $mapped = $this->mapRow($headings, $row);

            if ($this->isEmptyRow($mapped)) {
                continue;
            }

            $rowNumber = $index + 2;
            $processedRows++;

            [$normalized, $rowErrors] = $this->validateRow($mapped, $rowNumber);

            if (count($previewRows) < 8) {
                $previewRows[] = $mapped;
            }

            if ($rowErrors !== []) {
                $errors = [...$errors, ...$rowErrors];

                continue;
            }

            if (isset($seenStaffIds[$normalized['staff_id']])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => 'Staff ID '.$normalized['staff_id'].' appears more than once in this file.',
                ];

                continue;
            }

            $seenStaffIds[$normalized['staff_id']] = true;

            $resolved = $this->resolveMember($normalized);

            if ($resolved['match_status'] !== CreditUnionDeductionBatchLine::MATCH_MATCHED) {
                $warnings[] = [
                    'row' => $rowNumber,
                    'message' => $resolved['resolution_notes'],
                ];
            }

            $validRows[] = [...$normalized, ...$resolved];
        }

        $postable = collect($validRows)
            ->where('match_status', CreditUnionDeductionBatchLine::MATCH_MATCHED);

        return $this->withThreshold([
            'headings' => $headings,
            'preview_rows' => $previewRows,
            'valid_rows' => $validRows,
            'errors' => $errors,
            'warnings' => $warnings,
            'total_rows' => $processedRows,
            'valid_count' => count($validRows),
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            'postable_count' => $postable->count(),
            'postable_total' => round($postable->sum(fn (array $row) => $row['shares_amount'] + $row['savings_amount']), 2),
            'loan_repayment_total' => round(collect($validRows)->sum(fn (array $row) => $row['loan_repayment_amount']), 2),
        ]);
    }

    /**
     * Turns a validated preview into a batch plus one line per row. Rows that could not be
     * matched are still written out — resolving them is the whole point of the detail screen.
     */
    public function createBatch(array $batchAttributes, array $rows, ?string $importFilePath = null, ?int $actorId = null): CreditUnionDeductionBatch
    {
        return DB::transaction(function () use ($batchAttributes, $rows, $importFilePath, $actorId): CreditUnionDeductionBatch {
            $batch = CreditUnionDeductionBatch::query()->create([
                'period_month' => Carbon::parse($batchAttributes['period_month'])->startOfMonth()->toDateString(),
                'bank_reference' => $batchAttributes['bank_reference'] ?? null,
                'banked_date' => $batchAttributes['banked_date'] ?? null,
                'amount_received' => round((float) ($batchAttributes['amount_received'] ?? 0), 2),
                'amount_posted' => 0,
                'status' => CreditUnionDeductionBatch::STATUS_IMPORTED,
                'import_file_path' => $importFilePath,
                'imported_by' => $actorId ?? auth()->id(),
                'imported_at' => now(),
                'notes' => $batchAttributes['notes'] ?? null,
            ]);

            foreach ($rows as $row) {
                $batch->lines()->create([
                    'member_id' => $row['member_id'] ?? null,
                    'staff_id_raw' => $row['staff_id'],
                    'name_raw' => $row['name'] ?: null,
                    'shares_amount' => $row['shares_amount'],
                    'savings_amount' => $row['savings_amount'],
                    'loan_repayment_amount' => $row['loan_repayment_amount'],
                    'loan_repayment_posted' => false,
                    'match_status' => $row['match_status'] ?? CreditUnionDeductionBatchLine::MATCH_UNMATCHED,
                    'resolution_notes' => $row['resolution_notes'] ?? null,
                ]);
            }

            $batch->load('lines');

            Audit::log(
                action: 'credit_union.deduction_batch_imported',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionDeductionBatch::class,
                targetId: $batch->id,
                metadata: [
                    'period_month' => $batch->period_month->toDateString(),
                    'bank_reference' => $batch->bank_reference,
                    'amount_received' => (float) $batch->amount_received,
                    'line_count' => $batch->lines->count(),
                    'matched' => $batch->lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_MATCHED)->count(),
                    'unmatched' => $batch->lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_UNMATCHED)->count(),
                    'invalid_associate_member' => $batch->lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_INVALID_ASSOCIATE)->count(),
                    'skipped' => $batch->lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_SKIPPED)->count(),
                ]
            );

            return $batch;
        });
    }

    /**
     * Re-runs matching for a single line after an officer has fixed the underlying data
     * (registered the missing member, corrected the staff ID, and so on).
     */
    public function rematchLine(CreditUnionDeductionBatchLine $line): CreditUnionDeductionBatchLine
    {
        $resolved = $this->resolveMember(['staff_id' => (string) $line->staff_id_raw]);

        $line->update([
            'member_id' => $resolved['member_id'],
            'match_status' => $resolved['match_status'],
            'resolution_notes' => $resolved['match_status'] === CreditUnionDeductionBatchLine::MATCH_MATCHED
                ? $line->resolution_notes
                : $resolved['resolution_notes'],
        ]);

        return $line->refresh();
    }

    /**
     * @return array{member_id: int|null, match_status: string, resolution_notes: string|null}
     */
    public function resolveMember(array $row): array
    {
        $staffId = $this->normalizeCellText($row['staff_id'] ?? null);

        $member = CreditUnionMember::query()
            ->where('staff_id', $staffId)
            ->orWhere('member_number', $staffId)
            ->first();

        if (! $member) {
            return [
                'member_id' => null,
                'match_status' => CreditUnionDeductionBatchLine::MATCH_UNMATCHED,
                'resolution_notes' => 'Staff ID '.$staffId.' does not match any credit union member.',
            ];
        }

        // Associate members are never on GWL payroll, so a payroll row matching one is a
        // data error to surface rather than a posting to make.
        if ($member->isAssociate()) {
            return [
                'member_id' => $member->id,
                'match_status' => CreditUnionDeductionBatchLine::MATCH_INVALID_ASSOCIATE,
                'resolution_notes' => 'Member '.$member->member_number.' is an associate member and is never on GWL payroll.',
            ];
        }

        if (! $member->isActive()) {
            return [
                'member_id' => $member->id,
                'match_status' => CreditUnionDeductionBatchLine::MATCH_SKIPPED,
                'resolution_notes' => 'Member '.$member->member_number.' is '.$member->status.', not active, so nothing was posted.',
            ];
        }

        return [
            'member_id' => $member->id,
            'match_status' => CreditUnionDeductionBatchLine::MATCH_MATCHED,
            'resolution_notes' => null,
        ];
    }

    /**
     * Both hard validation failures and unpostable match results count toward the failure
     * rate: either way that row's money is not going to reach a member's ledger.
     */
    protected function withThreshold(array $preview): array
    {
        $maxFailurePercent = (int) config('gwl.max_import_failure_percent', 20);
        $rowCount = max(1, (int) ($preview['total_rows'] ?? 0));
        $failedRows = (int) ($preview['error_count'] ?? 0) + (int) ($preview['warning_count'] ?? 0);
        $failurePercent = round(($failedRows / $rowCount) * 100, 1);

        $preview['failure_percent'] = $failurePercent;
        $preview['max_failure_percent'] = $maxFailurePercent;
        $preview['blocked'] = $failurePercent > $maxFailurePercent;

        return $preview;
    }

    protected function validateRow(array $row, int $rowNumber): array
    {
        $normalized = $this->normalizeRow($row);

        $validator = Validator::make($normalized, [
            'staff_id' => ['required', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'shares_amount' => ['required', 'numeric', 'min:0'],
            'savings_amount' => ['required', 'numeric', 'min:0'],
            'loan_repayment_amount' => ['required', 'numeric', 'min:0'],
        ], [], [
            'staff_id' => 'staff ID',
            'shares_amount' => 'shares amount',
            'savings_amount' => 'savings amount',
            'loan_repayment_amount' => 'loan repayment amount',
        ]);

        $errors = [];

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $errors[] = ['row' => $rowNumber, 'message' => $message];
            }

            return [$normalized, $errors];
        }

        $total = $normalized['shares_amount'] + $normalized['savings_amount'] + $normalized['loan_repayment_amount'];

        if ($total <= 0) {
            $errors[] = [
                'row' => $rowNumber,
                'message' => 'Row for staff ID '.$normalized['staff_id'].' carries no deduction amounts.',
            ];
        }

        return [$normalized, $errors];
    }

    protected function normalizeRow(array $row): array
    {
        return [
            'staff_id' => $this->normalizeCellText($row['staff_id'] ?? null),
            'name' => $this->normalizeCellText($row['name'] ?? null),
            'shares_amount' => $this->normalizeAmount($row['shares_amount'] ?? null),
            'savings_amount' => $this->normalizeAmount($row['savings_amount'] ?? null),
            'loan_repayment_amount' => $this->normalizeAmount($row['loan_repayment_amount'] ?? null),
        ];
    }

    protected function normalizeAmount(mixed $value): float|string
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $cleaned = str_replace([',', ' '], '', trim($value));

            // Leave anything non-numeric alone so the validator reports it as a bad amount.
            return is_numeric($cleaned) ? round((float) $cleaned, 2) : $value;
        }

        return is_numeric($value) ? round((float) $value, 2) : $value;
    }

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

    protected function normalizeCellText(mixed $value): string
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

    protected function normalizeHeading($value): string
    {
        return Str::of((string) $value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->value();
    }

    protected function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty();
    }
}
