<?php

namespace App\Services\GWater;

use App\Exports\GWater\GwaterImportTemplateExport;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\User;
use App\Services\HealthSafety\Concerns\ReadsImportFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Brings past ledger lines in from one Excel workbook: opening balance, purchases (trips), daily sales, damages, expenses and
 * payments to G Water, one sheet each. Preview, then confirm, in the shape of the equipment import: the file is read again on
 * confirm (nothing the browser holds is trusted), it is create-only (a row is never an update; the opening balance posts once, a
 * waybill or payment reference already used is refused), and it never creates a location: the importer's own is used (for
 * super_admin, the one they chose).
 *
 * EVERY row is posted through GwaterWorkflowService, exactly as if typed on the screen, so stock never goes negative, waybills
 * stay unique, prices are the ones in force on the row's date and are frozen on the line, and each row is audited. The preview
 * and the import use the same code: the preview simply runs everything inside a transaction that is rolled back. A row that fails
 * is listed with the reason; nothing partial is kept unless the user chooses "import valid rows only".
 *
 * Rows are processed sheet by sheet (opening, purchases, sales, damages, expenses, payments), each sheet oldest date first, so a
 * sale can use the stock a purchase in the same file brought. The file is read into memory: it is meant for hundreds of rows
 * (gwater_import_max_rows caps it), so correctness is favoured over speed. Amounts are typed in cedis; money is pesewas inside.
 */
class GwaterImportService
{
    use ReadsImportFiles;

    /** Sheets in the order they are posted, with the columns that must be present. */
    public const SHEETS = [
        'opening' => ['title' => 'Opening balance', 'required' => ['as_of_date']],
        'purchases' => ['title' => 'Purchases', 'required' => ['purchase_date', 'waybill_no']],
        'sales' => ['title' => 'Daily sales', 'required' => ['sale_date', 'product', 'packs']],
        'damages' => ['title' => 'Damages', 'required' => ['damage_date', 'product', 'packs', 'stage', 'reason']],
        'expenses' => ['title' => 'Expenses', 'required' => ['expense_date', 'category', 'amount']],
        'payments' => ['title' => 'Payments', 'required' => ['request_date', 'reference']],
    ];

    protected const DATE_COLUMN = [
        'opening' => 'as_of_date', 'purchases' => 'purchase_date', 'sales' => 'sale_date', 'damages' => 'damage_date', 'expenses' => 'expense_date', 'payments' => 'request_date',
    ];

    public function __construct(
        protected GwaterVisibility $visibility,
        protected GwaterWorkflowService $workflow,
        protected GwaterPricingService $pricing,
    ) {}

    /** @return list<string> the columns of a sheet (one packs column per product where a sheet takes packs) */
    public function headings(string $sheet): array
    {
        $codes = GwProduct::query()->active()->orderBy('id')->pluck('code')->all();
        $packs = array_map(fn (string $code) => $code.'_packs', $codes);

        return match ($sheet) {
            'opening' => ['as_of_date', ...$packs, ...array_map(fn (string $code) => $code.'_outstanding_packs', $codes), 'owed_amount', 'note'],
            'purchases' => ['purchase_date', 'waybill_no', ...$packs, ...array_map(fn (string $code) => $code.'_unit_cost', $codes), 'supplier_ref', 'fuel_amount', 'loading_amount', 'offloading_amount', 'payee', 'receipt_ref', 'note'],
            'sales' => ['sale_date', 'product', 'packs', 'on_credit', 'customer_name', 'customer_phone', 'note'],
            'damages' => ['damage_date', 'product', 'packs', 'stage', 'reason', 'waybill_no'],
            'expenses' => ['expense_date', 'category', 'amount', 'waybill_no', 'payee', 'receipt_ref', 'paid_by_staff_id', 'note'],
            'payments' => ['request_date', 'reference', 'amount', 'paid_on', ...$packs, 'note'],
        };
    }

    /** A workbook with one empty sheet per kind (headings only) and a "Read me": nothing in it can be imported by mistake. */
    public function templateExport(): GwaterImportTemplateExport
    {
        $sheets = [];

        foreach (self::SHEETS as $key => $definition) {
            $sheets[$key] = ['title' => $definition['title'], 'headings' => $this->headings($key)];
        }

        return new GwaterImportTemplateExport($sheets, $this->readMe());
    }

    /**
     * Reads and posts everything inside a transaction that is rolled back; nothing is kept.
     *
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, User $actor, ?int $districtId = null): array
    {
        return $this->process($file, $actor, $districtId, commit: false, validOnly: false);
    }

    /**
     * Reads the file again and posts it. All or nothing by default: one failing row keeps the whole file out. With $validOnly
     * the rows that pass are kept and the failing ones are listed.
     *
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file, User $actor, ?int $districtId = null, bool $validOnly = false): array
    {
        return $this->process($file, $actor, $districtId, commit: true, validOnly: $validOnly);
    }

    // ------------------------------------------------------------------ the run

    /** @return array<string, mixed> */
    protected function process(UploadedFile $file, User $actor, ?int $districtId, bool $commit, bool $validOnly): array
    {
        abort_unless($this->visibility->can($actor, 'gwater.record'), 403, 'You may not import records.');

        $result = ['blocked' => false, 'committed' => false, 'sheets' => [], 'errors' => [], 'total_rows' => 0, 'valid_count' => 0, 'error_count' => 0, 'imported' => 0, 'max_rows' => (int) config('gwl.gwater_import_max_rows', 2000)];

        // The books to write to: refused up front (a 404/validation) if the importer has none.
        $location = $this->visibility->recordingLocation($actor, $districtId);
        $read = $this->read($file);

        if ($read['error']) {
            return [...$result, 'blocked' => true, 'errors' => [['sheet' => 'File', 'row' => '', 'message' => $read['error']]], 'error_count' => 1];
        }

        $blocks = $read['blocks'];
        $result['total_rows'] = $blocks->sum(fn (array $block) => count($block['rows']));

        // A sheet whose headings are wrong has no readable rows; say that, not "no rows".
        if ($result['total_rows'] === 0 && ! $blocks->contains(fn (array $block) => $block['errors'] !== [])) {
            return [...$result, 'blocked' => true, 'errors' => [['sheet' => 'File', 'row' => '', 'message' => 'The workbook has no rows to import on any of its sheets.']], 'error_count' => 1];
        }

        DB::beginTransaction();

        try {
            foreach ($blocks as $key => $block) {
                $sheet = ['title' => self::SHEETS[$key]['title'], 'rows' => count($block['rows']), 'valid' => 0, 'errors' => 0];

                foreach ($block['errors'] as $message) {
                    $result['errors'][] = ['sheet' => $sheet['title'], 'row' => 'Header', 'message' => $message];
                    $sheet['errors']++;
                }

                if ($block['errors'] === []) {
                    foreach ($block['rows'] as $row) {
                        $problem = $this->attempt(fn () => $this->post($key, $row['data'], $row['date'], $actor, $districtId, $location));

                        if ($problem === null) {
                            $sheet['valid']++;
                        } else {
                            $sheet['errors']++;
                            $result['errors'][] = ['sheet' => $sheet['title'], 'row' => $row['number'], 'message' => $problem];
                        }
                    }
                }

                $result['sheets'][$key] = $sheet;
            }

            $result['valid_count'] = (int) collect($result['sheets'])->sum('valid');
            $result['error_count'] = count($result['errors']);

            // Kept only on confirm: everything when nothing failed, or the valid rows when the user chose that.
            $keep = $commit && $result['total_rows'] > 0 && ($result['error_count'] === 0 || ($validOnly && $result['valid_count'] > 0));

            $keep ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result['blocked'] = $result['total_rows'] === 0;
        $result['committed'] = $keep;
        $result['imported'] = $keep ? $result['valid_count'] : 0;

        return $result;
    }

    /** Posts one row inside its own savepoint; a failure leaves nothing of that row behind. Returns the problem, or null. */
    protected function attempt(\Closure $post): ?string
    {
        try {
            DB::transaction($post);

            return null;
        } catch (ValidationException $exception) {
            return (string) collect($exception->errors())->flatten()->first();
        } catch (HttpException $exception) {
            return $exception->getStatusCode() === 404
                ? 'This row refers to something that is not in your location.'
                : 'You may not do this.';
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 'This row refers to something that is not in your location.';
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Turns one row into a workflow call. Anything wrong with the row itself throws RuntimeException with the reason; anything
     * the rules refuse comes back as the workflow's own ValidationException.
     */
    protected function post(string $sheet, array $row, ?string $date, User $actor, ?int $districtId, array $location): void
    {
        if ($date === null) {
            throw new \RuntimeException(($row['__date_problem'] ?? null) ? 'The date: '.$row['__date_problem'] : 'The date is missing.');
        }

        $products = GwProduct::query()->active()->orderBy('id')->get();
        $base = ['location_district_id' => $districtId];

        match ($sheet) {
            'opening' => $this->workflow->recordOpeningBalance($actor, $base + [
                'as_of_date' => $date,
                'packs' => $this->packsByProduct($row, $products, '_packs'),
                'outstanding' => $this->packsByProduct($row, $products, '_outstanding_packs'),
                'owed_amount' => $this->money($row['owed_amount'] ?? null, 'owed_amount', zeroIfBlank: true),
                'note' => $this->text($row['note'] ?? null),
            ]),
            'purchases' => $this->postPurchase($row, $date, $products, $actor, $base, $location),
            'sales' => $this->workflow->recordSale($actor, $base + [
                'sale_date' => $date,
                'product_id' => $this->product($row['product'] ?? null, $products)->id,
                'packs' => $this->text($row['packs'] ?? null),
                'payment_type' => $this->paymentType($row['on_credit'] ?? null),
                'customer_name' => $this->text($row['customer_name'] ?? null),
                'customer_phone' => $this->text($row['customer_phone'] ?? null),
                'note' => $this->text($row['note'] ?? null),
            ]),
            'damages' => $this->workflow->recordDamage($actor, $base + [
                'damage_date' => $date,
                'product_id' => $this->product($row['product'] ?? null, $products)->id,
                'packs' => $this->text($row['packs'] ?? null),
                'stage' => strtolower($this->text($row['stage'] ?? null)),
                'reason' => $this->text($row['reason'] ?? null),
                'purchase_id' => $this->tripByWaybill($row['waybill_no'] ?? null, $location),
            ]),
            'expenses' => $this->workflow->recordExpense($actor, $base + [
                'expense_date' => $date,
                'category' => strtolower($this->text($row['category'] ?? null)),
                'amount' => $this->money($row['amount'] ?? null, 'amount'),
                'purchase_id' => $this->tripByWaybill($row['waybill_no'] ?? null, $location),
                'payee' => $this->text($row['payee'] ?? null),
                'receipt_ref' => $this->text($row['receipt_ref'] ?? null),
                'paid_by_user_id' => $this->staff($row['paid_by_staff_id'] ?? null, $location),
                'note' => $this->text($row['note'] ?? null),
            ]),
            'payments' => $this->workflow->recordPayment($actor, $base + [
                'request_date' => $date,
                'reference' => $this->text($row['reference'] ?? null),
                'amount' => $this->money($row['amount'] ?? null, 'amount', zeroIfBlank: true),
                'paid_on' => $this->optionalDate($row['paid_on'] ?? null, 'paid_on'),
                'lines' => $products->map(fn (GwProduct $product) => ['product_id' => $product->id, 'packs' => $this->text($row[$product->code.'_packs'] ?? null)])->all(),
                'note' => $this->text($row['note'] ?? null),
            ]),
        };
    }

    protected function postPurchase(array $row, string $date, Collection $products, User $actor, array $base, array $location): void
    {
        // A cost typed in the file must agree with the price list: prices are never typed on a line, they are the ones in force.
        foreach ($products as $product) {
            $typed = $this->money($row[$product->code.'_unit_cost'] ?? null, $product->code.'_unit_cost', zeroIfBlank: true);
            $packs = $this->text($row[$product->code.'_packs'] ?? null);

            if ($typed > 0 && $packs !== '') {
                $listed = $this->pricing->costPrice($product->id, $date, $location);

                if ($listed !== $typed) {
                    throw new \RuntimeException(sprintf(
                        'The %s cost %s does not match the price list for %s (%s). Add the price on the Prices screen first.',
                        $product->code,
                        Money::format($typed),
                        \Illuminate\Support\Carbon::parse($date)->format('d M Y'),
                        $listed === null ? 'none' : Money::format($listed)
                    ));
                }
            }
        }

        $this->workflow->recordPurchase($actor, $base + [
            'purchase_date' => $date,
            'waybill_no' => $this->text($row['waybill_no'] ?? null),
            'supplier_ref' => $this->text($row['supplier_ref'] ?? null),
            'note' => $this->text($row['note'] ?? null),
            'lines' => $products->map(fn (GwProduct $product) => ['product_id' => $product->id, 'packs' => $this->text($row[$product->code.'_packs'] ?? null)])->all(),
            'fuel_amount' => $this->money($row['fuel_amount'] ?? null, 'fuel_amount', zeroIfBlank: true),
            'loading_amount' => $this->money($row['loading_amount'] ?? null, 'loading_amount', zeroIfBlank: true),
            'offloading_amount' => $this->money($row['offloading_amount'] ?? null, 'offloading_amount', zeroIfBlank: true),
            'payee' => $this->text($row['payee'] ?? null),
            'receipt_ref' => $this->text($row['receipt_ref'] ?? null),
        ]);
    }

    // ------------------------------------------------------------------ reading the workbook

    /**
     * @return array{error: string|null, blocks: Collection<string, array{rows: list<array{number: int, data: array, date: string|null}>, errors: list<string>}>}
     */
    protected function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $max = (int) config('gwl.gwater_import_max_rows', 2000);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $info = collect($reader->listWorksheetInfo($path));
        } catch (\Throwable) {
            return ['error' => 'The file could not be read. Use the template: an .xlsx workbook.', 'blocks' => collect()];
        }

        // Which sheet of the workbook is which kind (by name, ignoring case and spaces).
        $names = [];

        foreach ($info as $sheet) {
            $key = collect(self::SHEETS)->search(fn (array $definition, string $key) => $this->sameName($sheet['worksheetName'], $definition['title']) || $this->sameName($sheet['worksheetName'], $key));

            if ($key !== false) {
                $names[$key] = $sheet['worksheetName'];
            }
        }

        if ($names === []) {
            return ['error' => 'None of the sheets is named like the template ('.collect(self::SHEETS)->pluck('title')->implode(', ').').', 'blocks' => collect()];
        }

        $rowCount = (int) $info->filter(fn (array $sheet) => in_array($sheet['worksheetName'], $names, true))->sum(fn (array $sheet) => max(0, $sheet['totalRows'] - 1));

        if ($rowCount > $max) {
            return ['error' => 'The workbook has about '.number_format($rowCount).' rows; one import takes at most '.number_format($max).'. Split it into several files.', 'blocks' => collect()];
        }

        $reader->setLoadSheetsOnly(array_values($names));
        $book = $reader->load($path);
        $blocks = collect();

        foreach (array_keys(self::SHEETS) as $key) {
            if (! isset($names[$key])) {
                continue;
            }

            $grid = $book->getSheetByName($names[$key])->toArray(null, false, false, false);
            $headings = collect(array_shift($grid) ?? [])->map(fn ($value) => $this->normalizeHeading($value))->all();
            $missing = array_diff(self::SHEETS[$key]['required'], $headings);

            if ($missing !== []) {
                $blocks[$key] = ['rows' => [], 'errors' => ['Missing required columns: '.implode(', ', $missing).'.']];

                continue;
            }

            $rows = [];

            foreach ($grid as $index => $values) {
                $data = $this->mapRow($headings, $values);

                if ($this->isEmptyRow($data)) {
                    continue;
                }

                [$date, $problem] = $this->parseDate($data[self::DATE_COLUMN[$key]] ?? null);
                $rows[] = ['number' => $index + 2, 'data' => $data, 'date' => $date, 'date_problem' => $problem];
            }

            // Oldest first within the sheet (a stable sort, so equal dates keep the file's order); undated rows last.
            usort($rows, fn (array $a, array $b) => [$a['date'] ?? '9999-12-31', $a['number']] <=> [$b['date'] ?? '9999-12-31', $b['number']]);

            $blocks[$key] = ['rows' => array_map(fn (array $row) => ['number' => $row['number'], 'data' => $row['data'] + ['__date_problem' => $row['date_problem']], 'date' => $row['date']], $rows), 'errors' => []];
        }

        return ['error' => null, 'blocks' => $blocks];
    }

    // ------------------------------------------------------------------ cells

    /** @return array<int, mixed> product id => cell, for the columns "<code><suffix>" that hold something */
    protected function packsByProduct(array $row, Collection $products, string $suffix): array
    {
        $packs = [];

        foreach ($products as $product) {
            $cell = $this->text($row[$product->code.$suffix] ?? null);

            if ($cell !== '') {
                $packs[$product->id] = $cell;
            }
        }

        return $packs;
    }

    protected function product(mixed $cell, Collection $products): GwProduct
    {
        $needle = mb_strtolower($this->text($cell));

        return $products->first(fn (GwProduct $product) => $needle !== '' && ($needle === $product->code || $needle === mb_strtolower($product->name)))
            ?? throw new \RuntimeException('Unknown product "'.$this->text($cell).'". Use '.$products->pluck('code')->implode(' or ').'.');
    }

    /** Cedis typed in a cell -> pesewas. Blank is zero where $zeroIfBlank, otherwise an error. */
    protected function money(mixed $cell, string $column, bool $zeroIfBlank = false): int
    {
        $text = $this->text($cell);

        if ($text === '') {
            return $zeroIfBlank ? 0 : throw new \RuntimeException("{$column} is missing.");
        }

        return Money::toPesewas($text) ?? throw new \RuntimeException("{$column} \"{$text}\" is not an amount in cedis (at most two decimals).");
    }

    protected function optionalDate(mixed $cell, string $column): ?string
    {
        [$date, $problem] = $this->parseDate($cell);

        if ($problem) {
            throw new \RuntimeException("{$column}: {$problem}");
        }

        return $date;
    }

    /** The trip of the books being written to with this waybill number, as an id; blank = no trip. */
    protected function tripByWaybill(mixed $cell, array $location): ?int
    {
        $waybill = $this->text($cell);

        if ($waybill === '') {
            return null;
        }

        $trip = $this->visibility->atLocation(GwPurchase::query()->posted(), $location)->whereRaw('LOWER(waybill_no) = ?', [mb_strtolower($waybill)])->first();

        return $trip?->id ?? throw new \RuntimeException('No trip with waybill "'.$waybill.'" is recorded for this location (import the purchases first).');
    }

    protected function staff(mixed $cell, array $location): ?int
    {
        $staffId = $this->text($cell);

        if ($staffId === '') {
            return null;
        }

        return $this->visibility->peopleAt($location)->where('staff_id', $staffId)->value('id')
            ?? throw new \RuntimeException('Staff ID "'.$staffId.'" is not a member of staff at this location.');
    }

    /** The "on_credit" cell: yes / credit / 1 / true for a credit sale, blank / no / cash for a sale paid at once. */
    protected function paymentType(mixed $cell): string
    {
        $text = mb_strtolower($this->text($cell));

        return match (true) {
            in_array($text, ['', 'no', 'n', 'false', '0', 'cash'], true) => 'cash',
            in_array($text, ['yes', 'y', 'true', '1', 'credit'], true) => 'credit',
            default => throw new \RuntimeException('on_credit "'.$this->text($cell).'" is not yes or no.'),
        };
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
            ['What this is', 'Past records of your location, brought in once. Every row is checked and posted exactly as if typed on the screens.'],
            ['Sheets', 'Opening balance, Purchases, Daily sales, Damages, Expenses, Payments. Leave a sheet empty (headings only) if you have nothing for it. Do not rename the sheets or the headings.'],
            ['Order', 'Rows are posted sheet by sheet in the order above, oldest first, so a sale can use stock from a purchase in the same file.'],
            ['Dates', 'dd/MM/yyyy or a real Excel date.'],
            ['Money', 'Amounts in cedis, at most two decimals (for example 1430.00). Prices are never typed: they come from the Prices screen for the date of the row. In Purchases, the optional unit cost columns must agree with it.'],
            ['Packs', 'Whole numbers. One column per product (bottle_packs, sachet_packs).'],
            ['Product', 'In Daily sales and Damages: bottle or sachet.'],
            ['Credit sales', 'In Daily sales put yes in on_credit and the customer_name (customer_phone is optional) for a sale the customer pays for later. Payments received since are recorded on the Daily sales screen, not in this file.'],
            ['Damages', 'stage is transit, loading, offloading or storage. waybill_no ties the damage to a trip (optional).'],
            ['Expenses', 'category is fuel, loading, offloading or other. paid_by_staff_id (fuel only) is the staff ID of the person who paid out of pocket.'],
            ['Payments', 'Give the packs and the amount is worked out, or only the amount. paid_on makes it a payment already made; leave it empty for a request.'],
            ['Never overwrites', 'A row that already exists (the opening balance, a waybill, a payment reference) is refused, never updated. Rows that fail are listed with the reason.'],
        ];
    }
}
