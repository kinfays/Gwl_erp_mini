<?php

namespace App\Services\Finance;

use App\Exports\Finance\SupplierImportTemplateExport;
use App\Models\FinSupplier;
use App\Models\User;
use App\Services\HealthSafety\Concerns\ReadsImportFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Brings suppliers in from one Excel workbook (sheet "Suppliers": name, code, TIN, phone), in the shape of the other imports:
 * preview first (everything is checked and ROLLED BACK), confirm second. The file is read again on confirm (nothing the browser
 * holds is trusted), the import is CREATE-ONLY, and EVERY row goes through SupplierService, exactly as if typed on the screen, so
 * the format checks, the unique name and TIN, and the permissions are the screen's.
 *
 *  - a supplier already on the list (same name once spaces and case are ignored, a row earlier in this file included) is SKIPPED,
 *    not an error: importing the same file twice changes nothing. It is never updated;
 *  - a TIN that belongs to a different supplier, a bad TIN / phone format or a missing name is an ERROR naming the reason;
 *  - a name that merely LOOKS like a listed one is a WARNING and the row is imported (nobody can tick a box inside a file), so the
 *    preview lists them for the person to check before confirming;
 *  - blank TIN / phone / code are stored as NULL; all-or-nothing unless the person chooses "valid rows only";
 *  - cells are read as TEXT, so a phone number keeps what was typed (a 9-digit number that lost its zero gets it back).
 * ONE audit row per import carries counts only; the per-supplier audit rows are not written (the file has no personal detail there).
 * The file is read into memory, so it is capped (`gwl.fin_supplier_import_max_rows`).
 */
class SupplierImportService
{
    use ReadsImportFiles;

    public const SHEET = 'Suppliers';

    public const HEADINGS = ['name', 'code', 'tin', 'phone'];

    /** Other spellings of a heading that people use. */
    protected const ALIASES = [
        'supplier' => 'name', 'supplier_name' => 'name', 'name_of_supplier' => 'name',
        'supplier_code' => 'code',
        'tin_no' => 'tin', 'tin_number' => 'tin',
        'phone_number' => 'phone', 'telephone' => 'phone', 'mobile' => 'phone', 'contact' => 'phone',
    ];

    public function __construct(protected SupplierService $suppliers, protected FinanceScope $scope) {}

    public function maxRows(): int
    {
        return (int) config('gwl.fin_supplier_import_max_rows', 2000);
    }

    public function templateExport(): SupplierImportTemplateExport
    {
        return new SupplierImportTemplateExport(self::HEADINGS, $this->readMe(), $this->maxRows());
    }

    /** @return array<string, mixed> everything is checked inside a transaction that is rolled back; nothing is kept */
    public function preview(UploadedFile $file, User $actor): array
    {
        return $this->process($file, $actor, commit: false, validOnly: false);
    }

    /**
     * Reads the file again and imports it. All or nothing by default: one failing row keeps the whole file out. With $validOnly
     * the rows that pass are kept and the failing ones are listed.
     *
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file, User $actor, bool $validOnly = false): array
    {
        return $this->process($file, $actor, commit: true, validOnly: $validOnly);
    }

    // ------------------------------------------------------------------ the run

    /** @return array<string, mixed> */
    protected function process(UploadedFile $file, User $actor, bool $commit, bool $validOnly): array
    {
        abort_unless($this->scope->can($actor, 'finance.import_suppliers') && $this->scope->can($actor, 'finance.manage_suppliers'), 403, 'You may not import suppliers.');

        $result = [
            'blocked' => false, 'committed' => false, 'total_rows' => 0, 'valid_count' => 0, 'skipped_count' => 0, 'warning_count' => 0, 'error_count' => 0,
            'imported' => 0, 'errors' => [], 'warnings' => [], 'skipped' => [], 'max_rows' => $this->maxRows(),
        ];

        $read = $this->read($file);

        if ($read['error']) {
            return [...$result, 'blocked' => true, 'error_count' => 1, 'errors' => [['row' => '', 'name' => '', 'message' => $read['error']]]];
        }

        $rows = $read['rows'];
        $result['total_rows'] = count($rows);

        if ($rows === []) {
            return [...$result, 'blocked' => true, 'error_count' => 1, 'errors' => [['row' => '', 'name' => '', 'message' => 'The Suppliers sheet has no rows to import.']]];
        }

        DB::beginTransaction();

        try {
            $matcher = $this->suppliers->matcher();

            foreach ($rows as $row) {
                $outcome = $this->attempt(fn () => $this->post($row['data'], $actor, $matcher));
                $name = $this->text($row['data']['name'] ?? null);

                match ($outcome['status']) {
                    'created' => $this->created($result, $row['number'], $name, $outcome),
                    'skipped' => $this->skipped($result, $row['number'], $name, $outcome['message']),
                    default => $result['errors'][] = ['row' => $row['number'], 'name' => $name, 'message' => $outcome['message']],
                };
            }

            $result['error_count'] = count($result['errors']);

            // Kept only on confirm: everything when nothing failed, or the valid rows when the person chose that.
            $keep = $commit && ($result['error_count'] === 0 || ($validOnly && $result['valid_count'] > 0));

            if ($keep && $result['valid_count'] > 0) {
                $this->suppliers->auditImport([
                    'created' => $result['valid_count'], 'skipped' => $result['skipped_count'], 'errors' => $result['error_count'],
                    'warnings' => $result['warning_count'], 'valid_rows_only' => $validOnly,
                ]);
            }

            $keep ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result['committed'] = $keep;
        $result['imported'] = $keep ? $result['valid_count'] : 0;

        return $result;
    }

    /** Runs one row inside its own savepoint; a failure leaves nothing of that row behind. @return array{status: string, message: string, similar?: list<string>} */
    protected function attempt(\Closure $post): array
    {
        try {
            return DB::transaction($post);
        } catch (ValidationException $exception) {
            return ['status' => 'error', 'message' => (string) collect($exception->errors())->flatten()->first()];
        } catch (\RuntimeException $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /** @return array{status: string, message: string, similar?: list<string>} */
    protected function post(array $data, User $actor, SupplierMatcher $matcher): array
    {
        $clean = $this->suppliers->clean([
            'name' => $this->text($data['name'] ?? null),
            'code' => $this->text($data['code'] ?? null),
            'tin' => $this->text($data['tin'] ?? null),
            'phone' => $this->text($data['phone'] ?? null),
        ]);

        // Already on the list (or earlier in this file): left as it is, never updated.
        $existing = FinSupplier::query()->where('name_key', $clean['name_key'])->first();

        if ($existing) {
            return ['status' => 'skipped', 'message' => 'Already on the list as "'.$existing->name.'".'];
        }

        $similar = collect($matcher->matches($clean['name']))->pluck('name')->all();
        $supplier = $this->suppliers->create($actor, $clean, acknowledgeSimilar: true, audit: false);
        $matcher->add($supplier->id, $supplier->name);

        return ['status' => 'created', 'message' => '', 'similar' => $similar];
    }

    /** @param  array<string, mixed>  $result */
    protected function created(array &$result, int $number, string $name, array $outcome): void
    {
        $result['valid_count']++;

        if (! empty($outcome['similar'])) {
            $result['warning_count']++;
            $result['warnings'][] = ['row' => $number, 'name' => $name, 'message' => 'Looks like a supplier already listed ('.implode('; ', $outcome['similar']).'). It is imported as a new one; remove the row from the file first if it is the same supplier.'];
        }
    }

    /** @param  array<string, mixed>  $result */
    protected function skipped(array &$result, int $number, string $name, string $message): void
    {
        $result['skipped_count']++;
        $result['skipped'][] = ['row' => $number, 'name' => $name, 'message' => $message];
    }

    // ------------------------------------------------------------------ reading the workbook

    /** @return array{error: string|null, rows: list<array{number: int, data: array}>} */
    protected function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $info = collect($reader->listWorksheetInfo($path));
        } catch (\Throwable) {
            return ['error' => 'The file could not be read. Use the template: an .xlsx workbook.', 'rows' => []];
        }

        // The "Suppliers" sheet; a workbook with a single sheet is taken as it is.
        $sheet = $info->first(fn (array $sheet) => $this->sameName($sheet['worksheetName'], self::SHEET));

        if (! $sheet) {
            $candidates = $info->reject(fn (array $sheet) => $this->sameName($sheet['worksheetName'], 'Read me'));

            if ($candidates->count() !== 1) {
                return ['error' => 'The workbook has no sheet named "'.self::SHEET.'". Use the template.', 'rows' => []];
            }

            $sheet = $candidates->first();
        }

        if (max(0, (int) $sheet['totalRows'] - 1) > $this->maxRows()) {
            return ['error' => 'The sheet has about '.number_format(max(0, $sheet['totalRows'] - 1)).' rows; one import takes at most '.number_format($this->maxRows()).'. Split it into several files.', 'rows' => []];
        }

        $reader->setLoadSheetsOnly([$sheet['worksheetName']]);
        $grid = $reader->load($path)->getSheetByName($sheet['worksheetName'])->toArray(null, false, false, false);

        $headings = collect(array_shift($grid) ?? [])
            ->map(fn ($value) => $this->normalizeHeading($value))
            ->map(fn (string $heading) => self::ALIASES[$heading] ?? $heading)
            ->all();

        if (! in_array('name', $headings, true)) {
            return ['error' => 'The Suppliers sheet has no "name" column. Do not rename the headings of the template.', 'rows' => []];
        }

        $rows = [];

        foreach ($grid as $index => $values) {
            $data = $this->mapRow($headings, $values);

            if (! $this->isEmptyRow($data)) {
                $rows[] = ['number' => $index + 2, 'data' => $data];
            }
        }

        return ['error' => null, 'rows' => $rows];
    }

    protected function sameName(string $a, string $b): bool
    {
        $strip = fn (string $name) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));

        return $strip($a) === $strip($b);
    }

    /** @return list<array{0: string, 1: string}> */
    protected function readMe(): array
    {
        return [
            ['What this is', 'Suppliers for the company-wide list used on payment vouchers. Every row is checked exactly as if it were typed on the Suppliers screen.'],
            ['Columns', 'name (required), code, tin, phone (all optional; leave a cell empty if you do not have it). Do not rename the sheet or the headings.'],
            ['TIN', 'Letters, digits and hyphens, 6 to 20 characters. A TIN that already belongs to another supplier is refused.'],
            ['Phone', '9 to 15 digits, with an optional + at the start. Spaces and dashes are ignored. The cells are formatted as text so the leading zero stays.'],
            ['Never overwrites', 'A supplier already on the list (same name, ignoring spaces and capital letters) is skipped, not changed. Importing the same file twice adds nothing.'],
            ['Look-alikes', 'A name that is very close to a listed one (for example "ABC Trading Ltd" and "ABC Trading Limited") is imported but listed as a warning in the check, so you can take the row out of the file first.'],
            ['Check first', 'Use "Check the file": nothing is saved until you confirm. If any row has a problem nothing is imported, unless you choose to import the valid rows only.'],
        ];
    }
}
