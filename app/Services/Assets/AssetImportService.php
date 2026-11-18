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
 * Bulk upload of assets, phones and network devices (Assets > Upload). One service, three PROFILES
 * (AssetImportProfile: columns, required fields, text columns, folder, audit action). Create-only: it never updates or
 * deletes anything. The file is read by the shared pipeline (DataImportService::readStoredFile) and every row is judged
 * by the same rules as the matching form (Add Asset, Add Phone Device, Add Network Device):
 *
 *  - unique asset name (case-insensitive, trimmed, also inside the file) and unique serial number; a phone's IMEI and a
 *    network device's MAC address must not repeat in the file or in the database;
 *  - a known type OF THE PROFILE'S CATEGORY, a manufacturer that exists, and a model that exists FOR that manufacturer
 *    and type (a missing one is an error, never created here: add it first under Settings > Models);
 *  - a phone (type Ph) needs a serial number or an IMEI, by the same rule as the phone form
 *    (AssetRecordService::phoneIdentityMissing), and an IP address must be a real address inside the location's ranges;
 *  - a location (district) INSIDE THE IMPORTER'S OWN REGION, exactly like the forms. There is no region column: the
 *    region is always the importer's, so nothing in the file can place a device elsewhere. A blank location takes the
 *    holder's district when it is in that region; a location typed in the file always wins;
 *  - an optional holder (staff ID, an active employee, in the importer's region for a scoped ICT user), never created.
 *
 * Preview and confirm both call analyze(), so confirming re-reads the stored file and re-checks every row against the
 * database as it is NOW; nothing the browser sends (rows, counts, ids) is trusted. Rows are written through
 * AssetRecordService (one transaction, no per-asset audit row) and AssetAssignmentService (a plain assign), plus ONE
 * summary audit row of counts and a batch id: never a name, a serial, an IMEI, a holder or a file name. No MDM call is made.
 */
class AssetImportService
{
    public const CATEGORY = IctAsset::DEVICE_CATEGORY_ASSET;

    /** Where staged files live (private disk). Phones and Network keep theirs in a sub-folder named after the type. */
    public const DIRECTORY = 'asset-imports';

    /** Columns of the Assets template: key => [heading, required, example]. The order is the order in the file. */
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

    /**
     * Columns of the Phones template (the Add Phone Device form). Manufacturer and Model are required for a phone (type
     * Ph) and optional for a POS terminal or SIM card, which have no models; the rule is in judge().
     */
    public const PHONE_COLUMNS = [
        'asset_name' => ['Asset Name', true, 'Accounts Phone 01'],
        'asset_type' => ['Device Type', true, 'Phone'],
        'manufacturer' => ['Manufacturer', true, 'Samsung'],
        'model' => ['Model', true, 'Galaxy A15'],
        'serial_number' => ['Serial Number', false, 'R58N1234XYZ'],
        'imei' => ['IMEI', false, '356938035643809'],
        'status' => ['Status', false, 'Active'],
        'status_reason' => ['Status Reason', false, ''],
        'assigned_to' => ['Assigned To (Staff ID)', false, 'GW1234'],
        'general_use' => ['General Use', false, 'No'],
        'location' => ['Location', false, 'Accra Central'],
        'user_phone_number' => ['User Phone Number', false, '0244123456'],
        'device_phone_number' => ['Device Phone Number', false, '0201234567'],
        'purchase_date' => ['Purchase Date', false, '2025-03-14'],
    ];

    /**
     * Columns of the Network template (the Add Network Device form, plus the MAC address). Passwords are NOT importable:
     * a login or Wi-Fi password in a spreadsheet would be a secret kept in a file, so they are entered on the form.
     */
    public const NETWORK_COLUMNS = [
        'asset_name' => ['Asset Name', true, 'Accra Central Core Switch'],
        'asset_type' => ['Device Type', true, 'Switch'],
        'manufacturer' => ['Manufacturer', false, 'Cisco'],
        'model' => ['Model', false, 'Catalyst 2960'],
        'serial_number' => ['Serial Number', false, 'FOC1234ABCD'],
        'mac_address' => ['MAC Address', false, 'AA:BB:CC:DD:EE:FF'],
        'device_ip' => ['IP Address', false, '10.10.1.2'],
        'ssid' => ['SSID', false, ''],
        'device_username' => ['Device Username', false, 'admin'],
        'location' => ['Location', false, 'Accra Central'],
        'actual_location' => ['Actual Location', false, 'Server room'],
        'status' => ['Status', false, 'Active'],
        'status_reason' => ['Status Reason', false, ''],
    ];

    /** How many problem lines a preview keeps (the session holds it). The full list is rebuilt from the file on download. */
    public const MAX_LISTED_ISSUES = 200;

    public function __construct(
        protected DataImportService $reader,
        protected AssetRecordService $records,
        protected IpRangeService $ipRanges,
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

    // ------------------------------------------------------------------ Template lists

    /**
     * The "Allowed values" sheet of a template: what the importer may type, as columns of text (heading => values). The
     * locations are the districts of the importer's own region, the only ones a row may use.
     *
     * @return array<string, list<string>>
     */
    public function allowedValues(string $type, User $actor): array
    {
        $this->authorize($actor);
        $profile = AssetImportProfile::for($type);
        $regionId = $this->regionOf($actor);

        $models = IctAssetModel::query()->with('manufacturer')->where('is_active', true)->whereIn('category', array_keys($profile->types()))->get();

        $lists = [
            $profile->heading('asset_type').' (code)' => array_keys($profile->types()),
            $profile->heading('asset_type') => array_values($profile->types()),
            'Status' => IctAsset::STATUSES,
            'Manufacturer' => $models->map(fn ($model) => (string) $model->manufacturer?->name)->filter()->unique()->sort()->values()->all(),
            'Model (manufacturer / type / model)' => $models->filter(fn ($model) => $model->manufacturer)
                ->map(fn ($model) => $model->manufacturer->name.' / '.($profile->types()[$model->category] ?? $model->category).' / '.$model->name)
                ->sort()->values()->all(),
            'Location (districts in your region)' => $regionId
                ? District::query()->where('region_id', $regionId)->orderBy('district_name')->pluck('district_name')->map(fn ($name) => (string) $name)->all()
                : [],
        ];

        if ($profile->has('department')) {
            $lists['Department'] = Department::query()->orderBy('department_name')->pluck('department_name')->map(fn ($name) => (string) $name)->all();
        }

        if ($profile->has('general_use')) {
            $lists['General Use'] = ['Yes', 'No'];
        }

        return $lists;
    }

    // ------------------------------------------------------------------ Staging the file

    /**
     * Keeps an uploaded workbook on the private disk under a random name and returns its token. xlsx only, by content
     * as well as by name, and within the configured size. The file is kept in the folder of its type, so its token only
     * opens for that type.
     *
     * @throws ValidationException
     */
    public function stage(UploadedFile $file, User $actor, string $type = AssetImportProfile::ASSETS): string
    {
        $this->authorize($actor);
        $profile = AssetImportProfile::for($type);

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
        Storage::disk('local')->putFileAs($profile->directory, $file, $token.'.xlsx');

        return $token;
    }

    public function discard(?string $token, string $type = AssetImportProfile::ASSETS): void
    {
        if ($token && Str::isUuid($token) && AssetImportProfile::exists($type)) {
            Storage::disk('local')->delete(AssetImportProfile::for($type)->directory.'/'.$token.'.xlsx');
        }
    }

    /** Staged files nobody confirmed are removed after a day (every type's folder). */
    protected function purgeOldFiles(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->allFiles(self::DIRECTORY) as $path) {
            if ($disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                $disk->delete($path);
            }
        }
    }

    protected function storedPath(AssetImportProfile $profile, string $token): string
    {
        abort_unless(Str::isUuid($token) && Storage::disk('local')->exists($profile->directory.'/'.$token.'.xlsx'), 404);

        return Storage::disk('local')->path($profile->directory.'/'.$token.'.xlsx');
    }

    // ------------------------------------------------------------------ Preview and confirm

    /**
     * What the preview screen shows. Nothing is written. The result is plain arrays and scalars (it is kept in the
     * session), and holds the token only.
     *
     * @return array<string, mixed>
     */
    public function preview(string $token, User $actor, string $type = AssetImportProfile::ASSETS): array
    {
        $this->authorize($actor);
        $profile = AssetImportProfile::for($type);
        $analysis = $this->analyze($profile, $token, $actor);
        [$problems, $warnings] = $this->problemsFrom($analysis);

        return [
            'token' => $token,
            'type' => $profile->type,
            'user_id' => $actor->id,
            'total_rows' => $analysis['total'],
            'valid_count' => $analysis['valid'],
            'invalid_rows' => $analysis['invalid'],
            'warning_rows' => $analysis['warning_rows'],
            'example_rows' => $analysis['examples'],
            'error_count' => count($problems),
            'warning_count' => count($warnings),
            'errors' => array_slice($problems, 0, self::MAX_LISTED_ISSUES),
            'warnings' => array_slice($warnings, 0, self::MAX_LISTED_ISSUES),
            'preview_rows' => array_slice(array_map(fn (array $row) => $row['display'], $analysis['rows']), 0, 8),
            'can_confirm' => $analysis['file_errors'] === [] && $analysis['valid'] > 0,
        ];
    }

    /**
     * EVERY problem and warning of the stored file (no cap), for the downloadable list: Row, Column, Severity, Message.
     * Built from the file again, never from the session.
     *
     * @return list<array{row: int|string, column: string, severity: string, message: string}>
     */
    public function issues(string $token, User $actor, string $type = AssetImportProfile::ASSETS): array
    {
        $this->authorize($actor);
        [$problems, $warnings] = $this->problemsFrom($this->analyze(AssetImportProfile::for($type), $token, $actor));

        return [
            ...array_map(fn (array $issue) => [...$issue, 'severity' => 'Error'], $problems),
            ...array_map(fn (array $issue) => [...$issue, 'severity' => 'Warning'], $warnings),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{0: list<array{row: int|string, column: string, message: string}>, 1: list<array{row: int|string, column: string, message: string}>}
     */
    protected function problemsFrom(array $analysis): array
    {
        $problems = [];
        $warnings = [];

        foreach ($analysis['file_errors'] as $message) {
            $problems[] = ['row' => 'File', 'column' => '', 'message' => $message];
        }

        foreach ($analysis['rows'] as $row) {
            foreach ($row['errors'] as $index => $message) {
                $problems[] = ['row' => $row['row'], 'column' => $row['error_columns'][$index] ?? '', 'message' => $message];
            }

            foreach ($row['warnings'] as $index => $message) {
                $warnings[] = ['row' => $row['row'], 'column' => $row['warning_columns'][$index] ?? '', 'message' => $message];
            }
        }

        return [$problems, $warnings];
    }

    /**
     * Writes the file's valid rows. It reads the stored file again and checks every row again, whatever the preview said.
     * All or nothing unless $validRowsOnly; with it, rows with errors are skipped and counted, and a row that fails at
     * write time (a race with another save) is counted as failed.
     *
     * @return array{batch: string, type: string, created: int, skipped: int, failed: int, assigned: int, rows: int}
     *
     * @throws ValidationException
     */
    public function confirm(string $token, User $actor, bool $validRowsOnly = false, string $type = AssetImportProfile::ASSETS): array
    {
        $this->authorize($actor);
        $profile = AssetImportProfile::for($type);
        $analysis = $this->analyze($profile, $token, $actor);

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
        $failed = 0;
        $skipped = $analysis['invalid'];
        $batch = (string) Str::uuid();

        DB::transaction(function () use ($valid, $validRowsOnly, $profile, &$created, &$assigned, &$failed) {
            foreach ($valid as $row) {
                try {
                    // A savepoint per row: in "valid rows only" mode a row that fails at write time (a race with another
                    // save) is counted as failed; otherwise the failure rolls the whole import back.
                    DB::transaction(fn () => $this->records->save($row['payload'], $profile->category, null, null, false));
                    $created++;
                    $assigned += filled($row['payload']['assigned_to_employee_id'] ?? null) ? 1 : 0;
                } catch (ValidationException $e) {
                    if (! $validRowsOnly) {
                        throw ValidationException::withMessages(['import' => 'Row '.$row['row'].' could not be saved: '.collect($e->errors())->flatten()->first()]);
                    }

                    $failed++;
                } catch (Throwable $e) {
                    if (! $validRowsOnly) {
                        throw $e;
                    }

                    $failed++;
                }
            }
        });

        // Counts and a batch id only: never a name, a serial number, an IMEI, a holder or the file's name.
        AuditLog::record(
            action: $profile->auditAction,
            module: Permission::MODULE_ASSETS,
            targetType: 'ict_assets',
            metadata: ['batch' => $batch, 'rows' => $analysis['total'], 'created' => $created, 'assigned' => $assigned, 'skipped' => $skipped, 'failed' => $failed, 'valid_rows_only' => $validRowsOnly],
        );

        $this->discard($token, $profile->type);

        return ['batch' => $batch, 'type' => $profile->type, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'assigned' => $assigned, 'rows' => $analysis['total']];
    }

    // ------------------------------------------------------------------ Reading and judging the file

    /**
     * Reads the stored workbook and judges every row. The only place the rules live: preview(), issues() and confirm()
     * all call it.
     *
     * @return array{file_errors: list<string>, total: int, valid: int, invalid: int, warning_rows: int, examples: int, rows: list<array<string, mixed>>}
     */
    protected function analyze(AssetImportProfile $profile, string $token, User $actor): array
    {
        $empty = ['file_errors' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0, 'warning_rows' => 0, 'examples' => 0, 'rows' => []];

        $regionId = $this->regionOf($actor);

        if (! $regionId) {
            return [...$empty, 'file_errors' => ['Your account has no region assigned. Contact an administrator before importing assets.']];
        }

        // The template has more sheets after the one to fill in (allowed values, instructions): only the first is read.
        $grid = $this->reader->readStoredFile($this->storedPath($profile, $token), 'xlsx', true);

        if ($grid->isEmpty()) {
            return [...$empty, 'file_errors' => ['The uploaded file is empty.']];
        }

        $headings = collect($grid->shift())->map(fn ($value) => $this->headingKey($profile, $value))->values()->all();
        $headingProblems = [];

        foreach ($headings as $heading) {
            if (is_string($heading) && str_starts_with($heading, '=')) {
                $headingProblems[] = 'The header row contains a formula. Formulas are not allowed.';
            }
        }

        $missing = collect($profile->columns)
            ->filter(fn (array $column, string $key) => $column[1] && ! in_array($key, $headings, true))
            ->map(fn (array $column) => $column[0])
            ->values()
            ->all();

        if ($missing !== []) {
            $headingProblems[] = 'Missing required columns: '.implode(', ', $missing).'.';
        }

        // A phone is identified by its serial number or IMEI, so the file needs at least one of the two columns.
        if ($profile->type === AssetImportProfile::PHONES && ! in_array('serial_number', $headings, true) && ! in_array('imei', $headings, true)) {
            $headingProblems[] = 'The file needs a Serial Number or an IMEI column.';
        }

        if ($headingProblems !== []) {
            return [...$empty, 'file_errors' => array_values(array_unique($headingProblems))];
        }

        // Rows with something in them, kept with their spreadsheet row number. The template's example row is skipped.
        $dataRows = [];
        $examples = 0;

        foreach ($grid->values() as $index => $cells) {
            [$mapped, $numeric] = $this->mapRow($profile, $headings, $cells->all());

            if (! collect($mapped)->contains(fn ($value) => $value !== '')) {
                continue;
            }

            if ($this->isExampleRow($mapped)) {
                $examples++;

                continue;
            }

            $dataRows[] = ['row' => $index + 2, 'cells' => $mapped, 'numeric' => $numeric];
        }

        $max = (int) config('gwl.assets_import_max_rows', 1000);

        if (count($dataRows) > $max) {
            return [...$empty, 'examples' => $examples, 'file_errors' => ['The file has '.count($dataRows).' rows; one upload may hold at most '.$max.'. Split it into smaller files.']];
        }

        if ($dataRows === []) {
            return [...$empty, 'examples' => $examples, 'file_errors' => ['The file has no data rows below the header.'.($examples > 0 ? ' The example row of the template is ignored: replace it with your own data.' : '')]];
        }

        $lookups = $this->lookups($profile, $actor, $regionId, $dataRows);
        $seen = ['names' => [], 'serials' => [], 'imeis' => [], 'macs' => []];
        $rows = [];

        foreach ($dataRows as $dataRow) {
            $rows[] = $this->judge($profile, $dataRow['row'], $dataRow['cells'], $dataRow['numeric'], $lookups, $regionId, $seen);
        }

        $invalid = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));

        return [
            'file_errors' => [],
            'total' => count($rows),
            'valid' => count($rows) - $invalid,
            'invalid' => $invalid,
            'warning_rows' => count(array_filter($rows, fn (array $row) => $row['warnings'] !== [])),
            'examples' => $examples,
            'rows' => $rows,
        ];
    }

    /**
     * The template's example row is marked by its name starting "EXAMPLE - ". It is skipped and counted, never imported
     * (the preview says how many were ignored, so a real asset with such a name cannot vanish unnoticed).
     *
     * @param  array<string, string>  $cells
     */
    protected function isExampleRow(array $cells): bool
    {
        return str_starts_with(mb_strtolower(trim($cells['asset_name'] ?? '')), mb_strtolower(AssetImportProfile::EXAMPLE_PREFIX));
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, int|float>  $numeric  the raw value of cells that arrived as numbers, by column key
     * @param  array{names: array<string, int>, serials: array<string, int>, imeis: array<string, int>, macs: array<string, int>}  $seen
     * @return array<string, mixed>
     */
    protected function judge(AssetImportProfile $profile, int $row, array $cells, array $numeric, array $lookups, int $regionId, array &$seen): array
    {
        $errors = [];
        $errorColumns = [];
        $warnings = [];
        $warningColumns = [];

        $fail = function (string $column, string $message) use (&$errors, &$errorColumns, $profile) {
            $errors[] = $message;
            $errorColumns[] = $profile->has($column) ? $profile->heading($column) : $column;
        };
        $warn = function (string $column, string $message) use (&$warnings, &$warningColumns, $profile) {
            $warnings[] = $message;
            $warningColumns[] = $profile->has($column) ? $profile->heading($column) : $column;
        };

        $isAssets = $profile->type === AssetImportProfile::ASSETS;
        $isPhones = $profile->type === AssetImportProfile::PHONES;
        $isNetwork = $profile->type === AssetImportProfile::NETWORK;

        // Formulas are never evaluated or imported.
        foreach ($cells as $key => $value) {
            if (str_starts_with($value, '=')) {
                $fail($key, $profile->heading($key).' contains a formula. Formulas are not allowed: paste the value instead.');
            }
        }

        // An identifier typed as a number loses leading zeros, and Excel keeps only 15 significant digits.
        foreach ($profile->textColumns as $key) {
            if (! isset($numeric[$key])) {
                continue;
            }

            $raw = $numeric[$key];

            if ((is_float($raw) && (floor($raw) !== $raw || abs($raw) >= 1e15)) || (is_int($raw) && abs($raw) >= 10 ** 15)) {
                $fail($key, $profile->heading($key).' was stored as a number and has lost digits (Excel keeps only 15). Format the column as Text and type the value again.');
            } else {
                $warn($key, $profile->heading($key).' was stored as a number, so any leading zeros are already lost. Format the column as Text if it should have them.');
            }
        }

        // Name.
        $name = trim($cells['asset_name'] ?? '');

        if ($name === '') {
            $fail('asset_name', 'Asset Name is required.');
        } elseif (mb_strlen($name) > 255) {
            $fail('asset_name', 'Asset Name is longer than 255 characters.');
        } else {
            $key = IctAsset::nameKey($name);

            if (isset($lookups['names'][$key])) {
                $fail('asset_name', AssetRecordService::DUPLICATE_NAME_MESSAGE);
            } elseif (isset($seen['names'][$key])) {
                $fail('asset_name', 'This asset name is also used on row '.$seen['names'][$key].' of the file.');
            }

            $seen['names'][$key] ??= $row;
        }

        // Serial number: required for an asset, optional for a phone or network device (a phone's rule is below).
        $serial = trim($cells['serial_number'] ?? '');

        if ($serial === '') {
            if ($isAssets) {
                $fail('serial_number', 'Serial Number is required.');
            }
        } elseif (mb_strlen($serial) > 255) {
            $fail('serial_number', 'Serial Number is longer than 255 characters.');
        } else {
            $key = mb_strtolower($serial);

            if (isset($lookups['serials'][$key])) {
                $fail('serial_number', AssetRecordService::DUPLICATE_SERIAL_MESSAGE);
            } elseif (isset($seen['serials'][$key])) {
                $fail('serial_number', 'This serial number is also used on row '.$seen['serials'][$key].' of the file.');
            }

            $seen['serials'][$key] ??= $row;
        }

        // IMEI (phones): kept as typed text; repeats are errors, in the file and in the database.
        $imei = trim($cells['imei'] ?? '');

        if ($imei !== '') {
            if (mb_strlen($imei) > 50) {
                $fail('imei', 'IMEI is longer than 50 characters.');
            } else {
                $key = mb_strtolower($imei);

                if (isset($lookups['imeis'][$key])) {
                    $fail('imei', 'An asset with this IMEI already exists.');
                } elseif (isset($seen['imeis'][$key])) {
                    $fail('imei', 'This IMEI is also used on row '.$seen['imeis'][$key].' of the file.');
                }

                $seen['imeis'][$key] ??= $row;

                if (ctype_digit($imei) && strlen($imei) !== 15) {
                    $warn('imei', 'IMEI "'.$imei.'" has '.strlen($imei).' digits; an IMEI normally has 15. Check it is complete.');
                }
            }
        }

        // Type.
        $typeText = trim($cells['asset_type'] ?? '');
        $type = $lookups['types'][mb_strtolower($typeText)] ?? null;

        if ($typeText === '') {
            $fail('asset_type', $profile->heading('asset_type').' is required.');
        } elseif (! $type) {
            $fail('asset_type', $profile->heading('asset_type').' "'.$typeText.'" is not recognised. Use one of: '.implode(', ', array_values($profile->types())).'.');
        }

        // The same rule as the phone form and AssetRecordService::save(): a phone (type Ph) needs a serial number or an IMEI.
        if ($isPhones && $type && AssetRecordService::phoneIdentityMissing($type, $serial, $imei)) {
            $fail('Serial Number / IMEI', AssetRecordService::PHONE_IDENTITY_MESSAGE);
        }

        // Manufacturer and model. Both are required for an asset and for a phone; for a POS terminal, a SIM card or a
        // network device they are optional, but a manufacturer needs its model and a model needs its manufacturer.
        $makerText = trim($cells['manufacturer'] ?? '');
        $modelText = trim($cells['model'] ?? '');
        $makerId = $lookups['manufacturers'][mb_strtolower($makerText)] ?? null;
        $modelId = null;
        $bothRequired = $isAssets || ($isPhones && $type === AssetRecordService::PHONE_IDENTITY_TYPE);

        if ($makerText === '' && ($bothRequired || $modelText !== '')) {
            $fail('manufacturer', 'Manufacturer is required.');
        } elseif ($makerText !== '' && ! $makerId) {
            $fail('manufacturer', 'Manufacturer "'.$makerText.'" does not exist. Add it first under Settings > Manufacturers.');
        }

        if ($modelText === '' && ($bothRequired || $makerText !== '')) {
            $fail('model', 'Model is required.');
        } elseif ($modelText !== '' && $makerId && $type) {
            $modelId = $lookups['models'][$makerId.'|'.$type.'|'.mb_strtolower($modelText)] ?? null;

            if (! $modelId) {
                $fail('model', 'Model "'.$modelText.'" does not exist for '.$makerText.' as a '.$profile->types()[$type].'. Add it first under Settings > Models.');
            }
        }

        // Department (assets only).
        $departmentText = trim($cells['department'] ?? '');
        $departmentId = null;

        if ($profile->has('department')) {
            $departmentId = $lookups['departments'][mb_strtolower($departmentText)] ?? null;

            if ($departmentText === '') {
                $fail('department', 'Department is required.');
            } elseif (! $departmentId) {
                $fail('department', 'Department "'.$departmentText.'" does not exist.');
            }
        }

        // Location: a district inside the importer's region, exactly like the forms. Required for an asset.
        $locationText = trim($cells['location'] ?? '');
        $districtId = $lookups['districts'][mb_strtolower($locationText)] ?? null;

        if ($locationText === '') {
            if ($isAssets) {
                $fail('location', 'Location is required.');
            }
        } elseif (! $districtId) {
            $fail('location', isset($lookups['otherDistricts'][mb_strtolower($locationText)])
                ? 'Location "'.$locationText.'" is not in your region. You can only add '.($isAssets ? 'assets' : 'devices').' in your own region.'
                : 'Location "'.$locationText.'" does not exist.');
        }

        // Status.
        $statusText = trim($cells['status'] ?? '');
        $status = $statusText === '' ? IctAsset::STATUS_ACTIVE : ($lookups['statuses'][mb_strtolower($statusText)] ?? null);

        if (! $status) {
            $fail('status', 'Status "'.$statusText.'" is not recognised. Use one of: '.implode(', ', IctAsset::STATUSES).'.');
        }

        $reason = trim($cells['status_reason'] ?? '');

        if ($status === IctAsset::STATUS_DAMAGED && $reason === '') {
            $fail('status_reason', 'Status Reason is required when the status is Damaged.');
        }

        if (mb_strlen($reason) > 1000) {
            $fail('status_reason', 'Status Reason is longer than 1000 characters.');
        }

        // Tag (assets), physical location (assets, network).
        $tag = trim($cells['asset_tag'] ?? '');

        if (mb_strlen($tag) > 100) {
            $fail('asset_tag', 'Asset Tag is longer than 100 characters.');
        } elseif ($tag !== '' && isset($lookups['tags'][mb_strtolower($tag)])) {
            $warn('asset_tag', 'Asset Tag "'.$tag.'" is already used by another asset (tags are not unique, so this is only a warning).');
        }

        $actualLocation = trim($cells['actual_location'] ?? '');

        if (mb_strlen($actualLocation) > 255) {
            $fail('actual_location', 'Actual Location is longer than 255 characters.');
        }

        // General use and holder (assets and phones; network devices are never assigned to a person).
        $general = null;
        $employeeId = null;
        $staffId = trim($cells['assigned_to'] ?? '');

        if ($profile->has('assigned_to')) {
            $generalText = mb_strtolower(trim($cells['general_use'] ?? ''));

            if ($generalText === '' && $staffId === '' && in_array($type, IctAsset::GENERAL_USE_BY_DEFAULT_TYPES, true)) {
                // Left blank on a type that is shared by default (a Counting Machine) with nobody named: general use. "No" still means no.
                $general = true;
            } elseif ($generalText === '' || in_array($generalText, ['no', 'n', '0', 'false'], true)) {
                $general = false;
            } elseif (in_array($generalText, ['yes', 'y', '1', 'true'], true)) {
                $general = true;
            } else {
                $fail('general_use', 'General Use must be Yes or No.');
            }

            if ($staffId !== '') {
                $employee = $lookups['employees'][mb_strtolower($staffId)] ?? null;

                if ($general === true) {
                    $fail('assigned_to', 'A general-use '.$profile->noun.' is shared and has no holder: leave Assigned To empty.');
                } elseif (! $employee) {
                    $fail('assigned_to', 'Staff ID "'.$staffId.'" was not found'.($lookups['scopedEmployees'] ? ' in your region' : '').'.');
                } elseif (! $employee['active']) {
                    $fail('assigned_to', 'Staff ID "'.$staffId.'" is no longer active and cannot be given '.($isAssets ? 'an asset' : 'a device').'.');
                } else {
                    $employeeId = $employee['id'];

                    // A blank location takes the holder's district, but only one inside the importer's region (the form's rule).
                    // A location typed in the file always wins.
                    if ($locationText === '' && ! $isAssets) {
                        if ($employee['district_id'] && isset($lookups['districtNames'][$employee['district_id']])) {
                            $districtId = $employee['district_id'];
                        } else {
                            $warn('location', 'No Location given and the holder\'s district is not in your region: the location is left blank.');
                        }
                    }
                }
            } elseif ($type && $general === false && IctAsset::isPersonallyAssignedType($type)) {
                $warn('assigned_to', 'Nobody is assigned: this '.$profile->noun.' will show as Unassigned until someone is (or it is marked General Use).');
            }
        }

        // Phone numbers (phones).
        foreach (['user_phone_number', 'device_phone_number'] as $numberKey) {
            if (mb_strlen(trim($cells[$numberKey] ?? '')) > 30) {
                $fail($numberKey, $profile->heading($numberKey).' is longer than 30 characters.');
            }
        }

        // Network: IP address, MAC address, SSID, username.
        $ip = trim($cells['device_ip'] ?? '');

        if ($ip !== '') {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                $fail('device_ip', 'IP Address "'.$ip.'" is not a valid IP address.');
            } elseif ($rangeError = $this->ipRanges->validateIpForLocation($ip, $districtId, $regionId)) {
                $fail('device_ip', $rangeError);
            }
        }

        $macText = trim($cells['mac_address'] ?? '');
        $mac = null;

        if ($macText !== '') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $macText) ?? '';

            if (preg_match('/^[0-9A-Fa-f:\-.\s]+$/', $macText) !== 1 || strlen($hex) !== 12) {
                $fail('mac_address', 'MAC Address "'.$macText.'" is not valid. Use 12 hexadecimal digits, for example AA:BB:CC:DD:EE:FF.');
            } else {
                $mac = strtoupper(implode(':', str_split($hex, 2)));
                $key = strtolower($hex);

                if (isset($lookups['macs'][$key])) {
                    $fail('mac_address', 'A device with this MAC address already exists.');
                } elseif (isset($seen['macs'][$key])) {
                    $fail('mac_address', 'This MAC address is also used on row '.$seen['macs'][$key].' of the file.');
                }

                $seen['macs'][$key] ??= $row;
            }
        }

        foreach (['ssid', 'device_username'] as $textKey) {
            if (mb_strlen(trim($cells[$textKey] ?? '')) > 255) {
                $fail($textKey, $profile->heading($textKey).' is longer than 255 characters.');
            }
        }

        // Dates.
        $dateErrors = [];
        $purchased = $profile->has('purchase_date') ? $this->date($cells['purchase_date'] ?? '', 'Purchase Date', $dateErrors) : null;
        $warranty = $profile->has('warranty_expires') ? $this->date($cells['warranty_expires'] ?? '', 'Warranty Expires', $dateErrors) : null;

        foreach ($dateErrors as $message) {
            $fail(str_starts_with($message, 'Purchase') ? 'purchase_date' : 'warranty_expires', $message);
        }

        $typeLabel = $type ? $profile->types()[$type] : $typeText;
        $districtLabel = $locationText !== '' ? $locationText : ($districtId ? ($lookups['districtNames'][$districtId] ?? '') : '');
        $result = $errors !== [] ? 'Error' : ($warnings !== [] ? 'Warning' : 'OK');

        $display = ['Row' => (string) $row, 'Asset Name' => $name, 'Type' => $typeLabel, 'Manufacturer / Model' => trim($makerText.' '.$modelText), 'Serial Number' => $serial];

        if ($isAssets) {
            $display += ['Department' => $departmentText, 'Location' => $districtLabel, 'Assigned To' => $staffId];
        } elseif ($isPhones) {
            $display += ['IMEI' => $imei, 'Assigned To' => $staffId, 'Location' => $districtLabel];
        } else {
            $display += ['MAC Address' => $mac ?? $macText, 'IP Address' => $ip, 'Location' => $districtLabel];
        }

        $display['Result'] = $result;

        // The region is ALWAYS the importer's own; no column of the file can set it.
        $payload = [
            'asset_name' => $name,
            'serial_number' => $serial !== '' ? $serial : null,
            'asset_type' => $type,
            'ict_asset_model_id' => $modelId,
            'status' => $status,
            'status_reason' => $reason !== '' ? $reason : null,
            'region_id' => $regionId,
            'district_id' => $districtId,
        ];

        if ($profile->has('assigned_to')) {
            $payload['is_general_use'] = (bool) $general;
            $payload['assigned_to_employee_id'] = $employeeId;
        }

        if ($isAssets) {
            $payload += [
                'department_id' => $departmentId,
                'tag_no' => $tag !== '' ? $tag : null,
                'actual_location' => $actualLocation !== '' ? $actualLocation : null,
                'purchased_at' => $purchased,
                'warranty_expires_at' => $warranty,
            ];
        } elseif ($isPhones) {
            $payload += [
                'imei' => $imei !== '' ? $imei : null,
                'user_phone_number' => filled(trim($cells['user_phone_number'] ?? '')) ? trim($cells['user_phone_number']) : null,
                'device_phone_number' => filled(trim($cells['device_phone_number'] ?? '')) ? trim($cells['device_phone_number']) : null,
                'purchased_at' => $purchased,
            ];
        } elseif ($isNetwork) {
            $payload += [
                'device_ip' => $ip !== '' ? $ip : null,
                'mac_address' => $mac,
                'ssid' => filled(trim($cells['ssid'] ?? '')) ? trim($cells['ssid']) : null,
                'device_username' => filled(trim($cells['device_username'] ?? '')) ? trim($cells['device_username']) : null,
                'actual_location' => $actualLocation !== '' ? $actualLocation : null,
            ];
        }

        return [
            'row' => $row,
            'errors' => $errors,
            'error_columns' => $errorColumns,
            'warnings' => $warnings,
            'warning_columns' => $warningColumns,
            'display' => $display,
            'payload' => $payload,
        ];
    }

    /**
     * Everything a row is checked against, read once for the whole file.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $dataRows
     * @return array<string, mixed>
     */
    protected function lookups(AssetImportProfile $profile, User $actor, int $regionId, array $dataRows): array
    {
        $column = fn (string $key, bool $lower = false) => collect($dataRows)
            ->map(fn (array $r) => $lower ? IctAsset::nameKey($r['cells'][$key] ?? '') : trim($r['cells'][$key] ?? ''))
            ->filter()->unique()->values()->all();

        $names = $column('asset_name', true);
        $serials = $column('serial_number');
        $imeis = $column('imei');
        $tags = $column('asset_tag');
        $staffIds = $column('assigned_to');
        $macKeys = collect($dataRows)
            ->map(fn (array $r) => strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $r['cells']['mac_address'] ?? '') ?? ''))
            ->filter(fn (string $hex) => strlen($hex) === 12)->unique()->values()->all();
        $scoped = $actor->isScopedIct();

        // Compared in lower case on both sides, so MySQL and SQLite agree on what "the same" serial or tag is.
        $existing = fn (string $sql, string $pluck, array $values) => $values === [] ? [] : collect($values)->map(fn (string $value) => mb_strtolower($value))->unique()->chunk(500)->flatMap(
            fn ($chunk) => IctAsset::query()->whereRaw($sql.' IN ('.implode(',', array_fill(0, $chunk->count(), '?')).')', $chunk->values()->all())->pluck($pluck)
        )->all();

        $districts = District::query()->get(['id', 'district_name', 'region_id']);
        $own = $districts->where('region_id', $regionId);

        return [
            'names' => array_fill_keys($existing('LOWER(asset_name_key)', 'asset_name_key', $names), true),
            // MySQL compares serials case-insensitively; judge them that way everywhere so the form, the index and this agree.
            'serials' => array_fill_keys(array_map('mb_strtolower', $existing('LOWER(serial_number)', 'serial_number', $serials)), true),
            'imeis' => array_fill_keys(array_map('mb_strtolower', $existing('LOWER(imei)', 'imei', $imeis)), true),
            // A MAC address is compared as its 12 hex digits, whatever separators the stored one has (the agent writes its own).
            'macs' => array_fill_keys(array_map(
                fn ($stored) => strtolower(preg_replace('/[^0-9A-Fa-f]/', '', (string) $stored) ?? ''),
                $existing("LOWER(REPLACE(REPLACE(REPLACE(mac_address, ':', ''), '-', ''), '.', ''))", 'mac_address', $macKeys)
            ), true),
            'tags' => array_fill_keys(array_map('mb_strtolower', $existing('LOWER(tag_no)', 'tag_no', $tags)), true),
            'types' => $this->typeMap($profile),
            'statuses' => collect(IctAsset::STATUSES)->mapWithKeys(fn (string $status) => [mb_strtolower($status) => $status])->all(),
            'manufacturers' => IctAssetManufacturer::query()->get(['id', 'name'])->mapWithKeys(fn ($m) => [mb_strtolower(trim($m->name)) => $m->id])->all(),
            'models' => IctAssetModel::query()->where('is_active', true)->whereIn('category', array_keys($profile->types()))->get(['id', 'name', 'category', 'ict_asset_manufacturer_id'])
                ->mapWithKeys(fn ($m) => [$m->ict_asset_manufacturer_id.'|'.$m->category.'|'.mb_strtolower(trim($m->name)) => $m->id])->all(),
            'departments' => $profile->has('department')
                ? Department::query()->get(['id', 'department_name'])->mapWithKeys(fn ($d) => [mb_strtolower(trim($d->department_name)) => $d->id])->all()
                : [],
            'districts' => $own->mapWithKeys(fn ($d) => [mb_strtolower(trim($d->district_name)) => $d->id])->all(),
            'districtNames' => $own->mapWithKeys(fn ($d) => [$d->id => (string) $d->district_name])->all(),
            'otherDistricts' => $districts->where('region_id', '!=', $regionId)->mapWithKeys(fn ($d) => [mb_strtolower(trim($d->district_name)) => $d->id])->all(),
            'scopedEmployees' => $scoped,
            'employees' => $staffIds === [] ? [] : Employee::query()
                ->whereIn('staff_id', $staffIds)
                // The same pool the Assigned To picker offers: a regional ICT user picks from their own region only.
                ->when($scoped, fn ($query) => $query->where('region_id', $regionId))
                ->get(['id', 'staff_id', 'is_active', 'district_id'])
                ->mapWithKeys(fn ($e) => [mb_strtolower(trim($e->staff_id)) => ['id' => $e->id, 'active' => (bool) $e->is_active, 'district_id' => $e->district_id]])
                ->all(),
        ];
    }

    /** Type text (label or code, any case) => code, for the profile's category only. @return array<string, string> */
    protected function typeMap(AssetImportProfile $profile): array
    {
        $map = [];

        foreach ($profile->types() as $code => $label) {
            $map[mb_strtolower($code)] = $code;
            $map[mb_strtolower($label)] = $code;
        }

        return $map;
    }

    // ------------------------------------------------------------------ Cells

    /** "Assigned To (Staff ID)" and "assigned_to" both become assigned_to; an unknown heading becomes its own key and is ignored. */
    protected function headingKey(AssetImportProfile $profile, mixed $value): string
    {
        $normalise = fn (string $text) => Str::of($text)->lower()->replaceMatches('/\(.*?\)/', '')->trim()->replace([' ', '-'], '_')->value();
        $text = $normalise($this->cellText($value));

        // The Instructions sheet and users may use the heading as printed.
        foreach ($profile->columns as $key => [$heading]) {
            if ($text === $key || $text === $normalise($heading)) {
                return $key;
            }
        }

        // The Assets template says "Asset Type" where the other two say "Device Type"; the key works in both.
        return $text;
    }

    /**
     * One row as key => text, for the known columns only (anything else in the file is ignored; the region is never
     * read), plus the raw value of any cell in a text column that arrived as a number.
     *
     * @param  list<string>  $headings
     * @param  list<mixed>  $cells
     * @return array{0: array<string, string>, 1: array<string, int|float>}
     */
    protected function mapRow(AssetImportProfile $profile, array $headings, array $cells): array
    {
        $mapped = [];
        $numeric = [];

        foreach ($headings as $index => $heading) {
            if ($profile->has($heading) && ! array_key_exists($heading, $mapped)) {
                $raw = $cells[$index] ?? null;
                $mapped[$heading] = $this->cellText($raw, $heading);

                if ((is_int($raw) || is_float($raw)) && in_array($heading, $profile->textColumns, true)) {
                    $numeric[$heading] = $raw;
                }
            }
        }

        return [$mapped, $numeric];
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

            return is_float($value) && floor($value) === $value ? number_format($value, 0, '', '') : (string) $value;
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
