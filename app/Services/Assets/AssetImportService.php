<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\Permission;
use App\Models\User;
use App\Services\Import\DataImportService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Bulk upload of assets (category "asset": desktops, laptops, printers, servers...). Create-only: it never updates or
 * deletes an asset. The file is read by the shared pipeline (DataImportService::readStoredFile) and every row is
 * judged by the same rules as the Add Asset form:
 *
 *  - unique asset name (case-insensitive, trimmed, also inside the file) and unique serial number;
 *  - a known asset type, a manufacturer that exists, and a model that exists FOR that manufacturer and type (a missing
 *    one is an error, never created here: add it first under Settings > Models);
 *  - a department that exists and a location (district) INSIDE THE IMPORTER'S OWN REGION, exactly like the form. There is
 *    no region column: the region is always the importer's, so nothing in the file can place an asset elsewhere;
 *  - an optional holder (staff ID, an active employee, in the importer's region for a scoped ICT user).
 *
 * Preview and confirm both call analyze(), so confirming re-reads the stored file and re-checks every row against the
 * database as it is NOW; nothing the browser sends (rows, counts, ids) is trusted. Rows are written through
 * AssetRecordService (one transaction, no per-asset audit row) and AssetAssignmentService (a plain assign), plus ONE
 * summary audit row of counts and a batch id: never a name, a serial or a file name. No MDM call is made.
 */
class AssetImportService
{
    public const CATEGORY = IctAsset::DEVICE_CATEGORY_ASSET;

    /** Where staged files live (private disk). */
    public const DIRECTORY = 'asset-imports';

    /** Columns of the template: key => [heading, required, example]. The order is the order in the file. */
    public const COLUMNS = [
        'asset_name' => ['Asset Name', true, 'Accounts Laptop 01'],
        'asset_type' => ['Asset Type', true, 'Laptop'],
        'manufacturer' => ['Manufacturer', true, 'Dell'],
        'model' => ['Model', true, 'Latitude 5420'],
        'serial_number' => ['Serial Number', true, 'SN12345678'],
        'asset_tag' => ['Asset Tag', false, 'GWCL/FOE/2026/001'],
        'department' => ['Department', true, 'Finance'],
        'location' => ['Location', true, 'Accra Central'],
        'actual_location' => ['Actual Location', false, 'Room 12, second floor'],
        'status' => ['Status', false, 'Active'],
        'status_reason' => ['Status Reason', false, ''],
        'assigned_to' => ['Assigned To (Staff ID)', false, 'GW1234'],
        'general_use' => ['General Use', false, 'No'],
        'purchase_date' => ['Purchase Date', false, '2025-03-14'],
        'warranty_expires' => ['Warranty Expires', false, '2028-03-14'],
    ];

    /** How many problem lines a preview keeps (the session holds it). */
    public const MAX_LISTED_ISSUES = 200;

    public function __construct(
        protected DataImportService $reader,
        protected AssetRecordService $records,
    ) {}

    // ------------------------------------------------------------------ Who

    /** Importing creates assets, so it needs both permissions (and the importer's own region, checked in analyze()). */
    public function canImport(User $actor): bool
    {
        return $actor->hasRoles(User::ROLE_SUPER_ADMIN)
            || ($actor->hasPermission('assets.import') && $actor->hasPermission('assets.create'));
    }

    protected function authorize(User $actor): void
    {
        abort_unless($this->canImport($actor), 403, 'You do not have permission to perform this action.');
    }

    protected function regionOf(User $actor): ?int
    {
        return ($actor->employee ?? $actor->employeeByStaffId)?->region_id;
    }

    // ------------------------------------------------------------------ Staging the file

    /**
     * Keeps an uploaded workbook on the private disk under a random name and returns its token. xlsx only, by content
     * as well as by name, and within the configured size.
     *
     * @throws ValidationException
     */
    public function stage(UploadedFile $file, User $actor): string
    {
        $this->authorize($actor);

        $maxBytes = (int) config('gwl.assets_import_max_mb', 5) * 1024 * 1024;
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension !== 'xlsx') {
            throw ValidationException::withMessages(['file' => 'Upload an Excel workbook (.xlsx). Other formats are not accepted.']);
        }

        if ($file->getSize() === false || $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'The file is larger than '.config('gwl.assets_import_max_mb', 5).' MB.']);
        }

        // An .xlsx is a zip archive: anything else with that name is refused.
        $handle = @fopen($file->getPathname(), 'rb');
        $signature = $handle ? fread($handle, 4) : '';
        $handle && fclose($handle);

        if ($signature !== "PK\x03\x04") {
            throw ValidationException::withMessages(['file' => 'This is not a valid .xlsx workbook.']);
        }

        $this->purgeOldFiles();

        $token = (string) Str::uuid();
        Storage::disk('local')->putFileAs(self::DIRECTORY, $file, $token.'.xlsx');

        return $token;
    }

    public function discard(?string $token): void
    {
        if ($token && Str::isUuid($token)) {
            Storage::disk('local')->delete(self::DIRECTORY.'/'.$token.'.xlsx');
        }
    }

    /** Staged files nobody confirmed are removed after a day. */
    protected function purgeOldFiles(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::DIRECTORY) as $path) {
            if ($disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                $disk->delete($path);
            }
        }
    }

    protected function storedPath(string $token): string
    {
        abort_unless(Str::isUuid($token) && Storage::disk('local')->exists(self::DIRECTORY.'/'.$token.'.xlsx'), 404);

        return Storage::disk('local')->path(self::DIRECTORY.'/'.$token.'.xlsx');
    }

    // ------------------------------------------------------------------ Preview and confirm

    /**
     * What the preview screen shows. Nothing is written. The result is plain arrays and scalars (it is kept in the
     * session), and holds the token only.
     *
     * @return array<string, mixed>
     */
    public function preview(string $token, User $actor): array
    {
        $this->authorize($actor);
        $analysis = $this->analyze($token, $actor);

        $problems = [];
        $warnings = [];

        foreach ($analysis['file_errors'] as $message) {
            $problems[] = ['row' => 'File', 'message' => $message];
        }

        foreach ($analysis['rows'] as $row) {
            foreach ($row['errors'] as $message) {
                $problems[] = ['row' => $row['row'], 'message' => $message];
            }

            foreach ($row['warnings'] as $message) {
                $warnings[] = ['row' => $row['row'], 'message' => $message];
            }
        }

        return [
            'token' => $token,
            'user_id' => $actor->id,
            'total_rows' => $analysis['total'],
            'valid_count' => $analysis['valid'],
            'invalid_rows' => $analysis['invalid'],
            'error_count' => count($problems),
            'warning_count' => count($warnings),
            'errors' => array_slice($problems, 0, self::MAX_LISTED_ISSUES),
            'warnings' => array_slice($warnings, 0, self::MAX_LISTED_ISSUES),
            'preview_rows' => array_slice(array_map(fn (array $row) => $row['display'], $analysis['rows']), 0, 8),
            'can_confirm' => $analysis['file_errors'] === [] && $analysis['valid'] > 0,
        ];
    }

    /**
     * Writes the file's valid rows. It reads the stored file again and checks every row again, whatever the preview said.
     * All or nothing unless $validRowsOnly; with it, rows with errors are skipped and counted.
     *
     * @return array{batch: string, created: int, skipped: int, assigned: int, rows: int}
     *
     * @throws ValidationException
     */
    public function confirm(string $token, User $actor, bool $validRowsOnly = false): array
    {
        $this->authorize($actor);
        $analysis = $this->analyze($token, $actor);

        if ($analysis['file_errors'] !== []) {
            throw ValidationException::withMessages(['import' => $analysis['file_errors'][0]]);
        }

        if ($analysis['invalid'] > 0 && ! $validRowsOnly) {
            throw ValidationException::withMessages(['import' => $analysis['invalid'].' row(s) have errors, so nothing was imported. Fix the file, or choose "import valid rows only".']);
        }

        $valid = array_values(array_filter($analysis['rows'], fn (array $row) => $row['errors'] === []));

        if ($valid === []) {
            throw ValidationException::withMessages(['import' => 'There are no valid rows to import.']);
        }

        $created = 0;
        $assigned = 0;
        $skipped = $analysis['invalid'];
        $batch = (string) Str::uuid();

        DB::transaction(function () use ($valid, $validRowsOnly, &$created, &$assigned, &$skipped) {
            foreach ($valid as $row) {
                try {
                    // A savepoint per row: in "valid rows only" mode a row that fails at write time (a race with another
                    // save) is skipped; otherwise the failure rolls the whole import back.
                    DB::transaction(fn () => $this->records->save($row['payload'], self::CATEGORY, null, null, false));
                    $created++;
                    $assigned += filled($row['payload']['assigned_to_employee_id'] ?? null) ? 1 : 0;
                } catch (ValidationException $e) {
                    if (! $validRowsOnly) {
                        throw ValidationException::withMessages(['import' => 'Row '.$row['row'].' could not be saved: '.collect($e->errors())->flatten()->first()]);
                    }

                    $skipped++;
                } catch (Throwable $e) {
                    if (! $validRowsOnly) {
                        throw $e;
                    }

                    $skipped++;
                }
            }
        });

        // Counts and a batch id only: never a name, a serial number, a holder or the file's name.
        AuditLog::record(
            action: 'import_assets',
            module: Permission::MODULE_ASSETS,
            targetType: 'ict_assets',
            metadata: ['batch' => $batch, 'rows' => $analysis['total'], 'created' => $created, 'assigned' => $assigned, 'skipped' => $skipped, 'valid_rows_only' => $validRowsOnly],
        );

        $this->discard($token);

        return ['batch' => $batch, 'created' => $created, 'skipped' => $skipped, 'assigned' => $assigned, 'rows' => $analysis['total']];
    }

    // ------------------------------------------------------------------ Reading and judging the file

    /**
     * Reads the stored workbook and judges every row. The only place the rules live: preview() and confirm() both call it.
     *
     * @return array{file_errors: list<string>, total: int, valid: int, invalid: int, rows: list<array{row: int, errors: list<string>, warnings: list<string>, display: array<string, string>, payload: array<string, mixed>}>}
     */
    protected function analyze(string $token, User $actor): array
    {
        $empty = ['file_errors' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0, 'rows' => []];

        $regionId = $this->regionOf($actor);

        if (! $regionId) {
            return [...$empty, 'file_errors' => ['Your account has no region assigned. Contact an administrator before importing assets.']];
        }

        $grid = $this->reader->readStoredFile($this->storedPath($token));

        if ($grid->isEmpty()) {
            return [...$empty, 'file_errors' => ['The uploaded file is empty.']];
        }

        $headings = collect($grid->shift())->map(fn ($value) => $this->headingKey($value))->values()->all();
        $headingProblems = [];

        foreach ($headings as $heading) {
            if (is_string($heading) && str_starts_with($heading, '=')) {
                $headingProblems[] = 'The header row contains a formula. Formulas are not allowed.';
            }
        }

        $missing = collect(self::COLUMNS)
            ->filter(fn (array $column, string $key) => $column[1] && ! in_array($key, $headings, true))
            ->map(fn (array $column) => $column[0])
            ->values()
            ->all();

        if ($missing !== []) {
            $headingProblems[] = 'Missing required columns: '.implode(', ', $missing).'.';
        }

        if ($headingProblems !== []) {
            return [...$empty, 'file_errors' => array_values(array_unique($headingProblems))];
        }

        // Rows with something in them, kept with their spreadsheet row number.
        $dataRows = [];

        foreach ($grid->values() as $index => $cells) {
            $mapped = $this->mapRow($headings, $cells->all());

            if (collect($mapped)->contains(fn ($value) => $value !== '')) {
                $dataRows[] = ['row' => $index + 2, 'cells' => $mapped];
            }
        }

        $max = (int) config('gwl.assets_import_max_rows', 1000);

        if (count($dataRows) > $max) {
            return [...$empty, 'file_errors' => ['The file has '.count($dataRows).' rows; one upload may hold at most '.$max.'. Split it into smaller files.']];
        }

        if ($dataRows === []) {
            return [...$empty, 'file_errors' => ['The file has no data rows below the header.']];
        }

        $lookups = $this->lookups($actor, $regionId, $dataRows);
        $seenNames = [];
        $seenSerials = [];
        $rows = [];

        foreach ($dataRows as $dataRow) {
            $rows[] = $this->judge($dataRow['row'], $dataRow['cells'], $lookups, $regionId, $seenNames, $seenSerials);
        }

        $invalid = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));

        return [
            'file_errors' => [],
            'total' => count($rows),
            'valid' => count($rows) - $invalid,
            'invalid' => $invalid,
            'rows' => $rows,
        ];
    }

    /** @param  array<string, string>  $cells */
    protected function judge(int $row, array $cells, array $lookups, int $regionId, array &$seenNames, array &$seenSerials): array
    {
        $errors = [];
        $warnings = [];

        // Formulas are never evaluated or imported.
        foreach ($cells as $key => $value) {
            if (str_starts_with($value, '=')) {
                $errors[] = self::COLUMNS[$key][0].' contains a formula. Formulas are not allowed: paste the value instead.';
            }
        }

        $name = trim($cells['asset_name'] ?? '');

        if ($name === '') {
            $errors[] = 'Asset Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Asset Name is longer than 255 characters.';
        } else {
            $key = IctAsset::nameKey($name);

            if (isset($lookups['names'][$key])) {
                $errors[] = AssetRecordService::DUPLICATE_NAME_MESSAGE;
            } elseif (isset($seenNames[$key])) {
                $errors[] = 'This asset name is also used on row '.$seenNames[$key].' of the file.';
            }

            $seenNames[$key] ??= $row;
        }

        $serial = trim($cells['serial_number'] ?? '');

        if ($serial === '') {
            $errors[] = 'Serial Number is required.';
        } elseif (mb_strlen($serial) > 255) {
            $errors[] = 'Serial Number is longer than 255 characters.';
        } else {
            $key = mb_strtolower($serial);

            if (isset($lookups['serials'][$key])) {
                $errors[] = 'An asset with this serial number already exists.';
            } elseif (isset($seenSerials[$key])) {
                $errors[] = 'This serial number is also used on row '.$seenSerials[$key].' of the file.';
            }

            $seenSerials[$key] ??= $row;
        }

        // Type, manufacturer, model.
        $typeText = trim($cells['asset_type'] ?? '');
        $type = $lookups['types'][mb_strtolower($typeText)] ?? null;

        if ($typeText === '') {
            $errors[] = 'Asset Type is required.';
        } elseif (! $type) {
            $errors[] = 'Asset Type "'.$typeText.'" is not recognised. Use one of: '.implode(', ', array_values(IctAsset::ASSET_TYPES[self::CATEGORY])).'.';
        }

        $makerText = trim($cells['manufacturer'] ?? '');
        $modelText = trim($cells['model'] ?? '');
        $makerId = $lookups['manufacturers'][mb_strtolower($makerText)] ?? null;
        $modelId = null;

        if ($makerText === '') {
            $errors[] = 'Manufacturer is required.';
        } elseif (! $makerId) {
            $errors[] = 'Manufacturer "'.$makerText.'" does not exist. Add it first under Settings > Manufacturers.';
        }

        if ($modelText === '') {
            $errors[] = 'Model is required.';
        } elseif ($makerId && $type) {
            $modelId = $lookups['models'][$makerId.'|'.$type.'|'.mb_strtolower($modelText)] ?? null;

            if (! $modelId) {
                $errors[] = 'Model "'.$modelText.'" does not exist for '.$makerText.' as a '.IctAsset::ASSET_TYPES[self::CATEGORY][$type].'. Add it first under Settings > Models.';
            }
        }

        // Department and location (district inside the importer's region, exactly like the form).
        $departmentText = trim($cells['department'] ?? '');
        $departmentId = $lookups['departments'][mb_strtolower($departmentText)] ?? null;

        if ($departmentText === '') {
            $errors[] = 'Department is required.';
        } elseif (! $departmentId) {
            $errors[] = 'Department "'.$departmentText.'" does not exist.';
        }

        $locationText = trim($cells['location'] ?? '');
        $districtId = $lookups['districts'][mb_strtolower($locationText)] ?? null;

        if ($locationText === '') {
            $errors[] = 'Location is required.';
        } elseif (! $districtId) {
            $errors[] = isset($lookups['otherDistricts'][mb_strtolower($locationText)])
                ? 'Location "'.$locationText.'" is not in your region. You can only add assets in your own region.'
                : 'Location "'.$locationText.'" does not exist.';
        }

        // Status.
        $statusText = trim($cells['status'] ?? '');
        $status = $statusText === '' ? IctAsset::STATUS_ACTIVE : ($lookups['statuses'][mb_strtolower($statusText)] ?? null);

        if (! $status) {
            $errors[] = 'Status "'.$statusText.'" is not recognised. Use one of: '.implode(', ', IctAsset::STATUSES).'.';
        }

        $reason = trim($cells['status_reason'] ?? '');

        if ($status === IctAsset::STATUS_DAMAGED && $reason === '') {
            $errors[] = 'Status Reason is required when the status is Damaged.';
        }

        if (mb_strlen($reason) > 1000) {
            $errors[] = 'Status Reason is longer than 1000 characters.';
        }

        // Tag, physical location.
        $tag = trim($cells['asset_tag'] ?? '');

        if (mb_strlen($tag) > 100) {
            $errors[] = 'Asset Tag is longer than 100 characters.';
        } elseif ($tag !== '' && isset($lookups['tags'][mb_strtolower($tag)])) {
            $warnings[] = 'Asset Tag "'.$tag.'" is already used by another asset (tags are not unique, so this is only a warning).';
        }

        $actualLocation = trim($cells['actual_location'] ?? '');

        if (mb_strlen($actualLocation) > 255) {
            $errors[] = 'Actual Location is longer than 255 characters.';
        }

        // General use and holder.
        $generalText = mb_strtolower(trim($cells['general_use'] ?? ''));
        $general = null;

        if ($generalText === '' || in_array($generalText, ['no', 'n', '0', 'false'], true)) {
            $general = false;
        } elseif (in_array($generalText, ['yes', 'y', '1', 'true'], true)) {
            $general = true;
        } else {
            $errors[] = 'General Use must be Yes or No.';
        }

        $staffId = trim($cells['assigned_to'] ?? '');
        $employeeId = null;

        if ($staffId !== '') {
            $employee = $lookups['employees'][mb_strtolower($staffId)] ?? null;

            if ($general === true) {
                $errors[] = 'A general-use asset is shared and has no holder: leave Assigned To empty.';
            } elseif (! $employee) {
                $errors[] = 'Staff ID "'.$staffId.'" was not found'.($lookups['scopedEmployees'] ? ' in your region' : '').'.';
            } elseif (! $employee['active']) {
                $errors[] = 'Staff ID "'.$staffId.'" is no longer active and cannot be given an asset.';
            } else {
                $employeeId = $employee['id'];
            }
        } elseif ($type && $general === false && IctAsset::isPersonallyAssignedType($type)) {
            $warnings[] = 'Nobody is assigned: this asset will show as Unassigned until someone is (or it is marked General Use).';
        }

        // Dates.
        $purchased = $this->date($cells['purchase_date'] ?? '', 'Purchase Date', $errors);
        $warranty = $this->date($cells['warranty_expires'] ?? '', 'Warranty Expires', $errors);

        return [
            'row' => $row,
            'errors' => $errors,
            'warnings' => $warnings,
            'display' => [
                'Row' => (string) $row,
                'Asset Name' => $name,
                'Type' => $type ? IctAsset::ASSET_TYPES[self::CATEGORY][$type] : $typeText,
                'Manufacturer / Model' => trim($makerText.' '.$modelText),
                'Serial Number' => $serial,
                'Department' => $departmentText,
                'Location' => $locationText,
                'Assigned To' => $staffId,
            ],
            // The region is ALWAYS the importer's own; no column of the file can set it.
            'payload' => [
                'asset_name' => $name,
                'serial_number' => $serial,
                'asset_type' => $type,
                'ict_asset_model_id' => $modelId,
                'status' => $status,
                'status_reason' => $reason !== '' ? $reason : null,
                'is_general_use' => (bool) $general,
                'assigned_to_employee_id' => $employeeId,
                'department_id' => $departmentId,
                'tag_no' => $tag !== '' ? $tag : null,
                'region_id' => $regionId,
                'district_id' => $districtId,
                'actual_location' => $actualLocation !== '' ? $actualLocation : null,
                'purchased_at' => $purchased,
                'warranty_expires_at' => $warranty,
            ],
        ];
    }

    /**
     * Everything a row is checked against, read once for the whole file.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $dataRows
     * @return array<string, mixed>
     */
    protected function lookups(User $actor, int $regionId, array $dataRows): array
    {
        $names = collect($dataRows)->map(fn (array $r) => IctAsset::nameKey($r['cells']['asset_name'] ?? ''))->filter()->unique()->values()->all();
        $serials = collect($dataRows)->map(fn (array $r) => trim($r['cells']['serial_number'] ?? ''))->filter()->unique()->values()->all();
        $tags = collect($dataRows)->map(fn (array $r) => trim($r['cells']['asset_tag'] ?? ''))->filter()->unique()->values()->all();
        $staffIds = collect($dataRows)->map(fn (array $r) => trim($r['cells']['assigned_to'] ?? ''))->filter()->unique()->values()->all();
        $scoped = $actor->isScopedIct();

        // Compared in lower case on both sides, so MySQL and SQLite agree on what "the same" serial or tag is.
        $existing = fn (string $column, array $values) => $values === [] ? [] : collect($values)->map(fn (string $value) => mb_strtolower($value))->unique()->chunk(500)->flatMap(
            fn ($chunk) => IctAsset::query()->whereRaw('LOWER('.$column.') IN ('.implode(',', array_fill(0, $chunk->count(), '?')).')', $chunk->values()->all())->pluck($column)
        )->all();

        $districts = District::query()->get(['id', 'district_name', 'region_id']);

        return [
            'names' => array_fill_keys($existing('asset_name_key', $names), true),
            // MySQL compares serials case-insensitively; judge them that way everywhere so the form, the index and this agree.
            'serials' => array_fill_keys(array_map('mb_strtolower', $existing('serial_number', $serials)), true),
            'tags' => array_fill_keys(array_map('mb_strtolower', $existing('tag_no', $tags)), true),
            'types' => $this->typeMap(),
            'statuses' => collect(IctAsset::STATUSES)->mapWithKeys(fn (string $status) => [mb_strtolower($status) => $status])->all(),
            'manufacturers' => IctAssetManufacturer::query()->get(['id', 'name'])->mapWithKeys(fn ($m) => [mb_strtolower(trim($m->name)) => $m->id])->all(),
            'models' => IctAssetModel::query()->where('is_active', true)->get(['id', 'name', 'category', 'ict_asset_manufacturer_id'])
                ->mapWithKeys(fn ($m) => [$m->ict_asset_manufacturer_id.'|'.$m->category.'|'.mb_strtolower(trim($m->name)) => $m->id])->all(),
            'departments' => Department::query()->get(['id', 'department_name'])->mapWithKeys(fn ($d) => [mb_strtolower(trim($d->department_name)) => $d->id])->all(),
            'districts' => $districts->where('region_id', $regionId)->mapWithKeys(fn ($d) => [mb_strtolower(trim($d->district_name)) => $d->id])->all(),
            'otherDistricts' => $districts->where('region_id', '!=', $regionId)->mapWithKeys(fn ($d) => [mb_strtolower(trim($d->district_name)) => $d->id])->all(),
            'scopedEmployees' => $scoped,
            'employees' => $staffIds === [] ? [] : Employee::query()
                ->whereIn('staff_id', $staffIds)
                // The same pool the Assigned To picker offers: a regional ICT user picks from their own region only.
                ->when($scoped, fn ($query) => $query->where('region_id', $regionId))
                ->get(['id', 'staff_id', 'is_active'])
                ->mapWithKeys(fn ($e) => [mb_strtolower(trim($e->staff_id)) => ['id' => $e->id, 'active' => (bool) $e->is_active]])
                ->all(),
        ];
    }

    /** Type text (label or code, any case) => code, for the asset category only. @return array<string, string> */
    protected function typeMap(): array
    {
        $map = [];

        foreach (IctAsset::ASSET_TYPES[self::CATEGORY] as $code => $label) {
            $map[mb_strtolower($code)] = $code;
            $map[mb_strtolower($label)] = $code;
        }

        return $map;
    }

    // ------------------------------------------------------------------ Cells

    /** "Assigned To (Staff ID)" and "assigned_to" both become assigned_to; an unknown heading becomes its own key and is ignored. */
    protected function headingKey(mixed $value): string
    {
        $text = Str::of($this->cellText($value))->lower()->replaceMatches('/\(.*?\)/', '')->trim()->replace([' ', '-'], '_')->value();

        // The Instructions sheet and users may use the heading as printed.
        foreach (self::COLUMNS as $key => [$heading]) {
            if ($text === $key || $text === Str::of($heading)->lower()->replaceMatches('/\(.*?\)/', '')->trim()->replace([' ', '-'], '_')->value()) {
                return $key;
            }
        }

        return $text;
    }

    /**
     * One row as key => text, for the known columns only (anything else in the file is ignored; the region is never read).
     *
     * @param  list<string>  $headings
     * @param  list<mixed>  $cells
     * @return array<string, string>
     */
    protected function mapRow(array $headings, array $cells): array
    {
        $mapped = [];

        foreach ($headings as $index => $heading) {
            if (array_key_exists($heading, self::COLUMNS) && ! array_key_exists($heading, $mapped)) {
                $mapped[$heading] = $this->cellText($cells[$index] ?? null, $heading);
            }
        }

        return $mapped;
    }

    /** A cell as trimmed text. A whole number read as a float ("12345.0") loses its ".0"; dates are handled by date(). */
    protected function cellText(mixed $value, ?string $column = null): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_float($value) || is_int($value)) {
            if (in_array($column, ['purchase_date', 'warranty_expires'], true)) {
                return (string) $value;
            }

            return is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value;
        }

        return trim((string) $value);
    }

    /**
     * A date cell: an Excel serial number, or text as Y-m-d, d/m/Y, d-m-Y, d.m.Y or "14 Mar 2025". Blank is null.
     *
     * @param  list<string>  $errors
     */
    protected function date(string $text, string $label, array &$errors): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            if (is_numeric($text)) {
                $serial = (float) $text;

                // Excel serials for plausible dates (1900-2200); anything else numeric is not a date.
                if ($serial < 1 || $serial > 110000) {
                    throw new \InvalidArgumentException;
                }

                return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->format('Y-m-d');
            }

            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'j F Y'] as $format) {
                try {
                    $parsed = Carbon::createFromFormat('!'.$format, $text);
                } catch (Throwable) {
                    continue;
                }

                if ($parsed && $parsed->format($format) === $text) {
                    return $parsed->format('Y-m-d');
                }
            }
        } catch (Throwable) {
            // falls through to the error below
        }

        $errors[] = $label.' "'.$text.'" is not a date. Use YYYY-MM-DD.';

        return null;
    }
}
