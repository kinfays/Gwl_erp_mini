<?php

namespace App\Services\HealthSafety;

use App\Exports\ImportTemplateExport;
use App\Services\HealthSafety\Concerns\ReadsImportFiles;
use App\Imports\RawRowsImport;
use App\Models\Employee;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsSite;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bring an existing register of fire extinguishers or first aid kits in from Excel: preview, then confirm, in the shape of
 * the Credit Union deduction import (errors, warnings, counts, a failure threshold) but with no stored batch: confirming
 * reads the file again and inserts the usable rows in one transaction.
 *
 * Create-only. An asset code that already exists is skipped with a warning, never updated. The import never creates a
 * site: a row naming one that is not under Sites is an error, and the distinct unmatched names are listed so they can be
 * added there and the file uploaded again. A kit's contents are not imported; the kit is given the template for its type.
 * Anyone who does not see every region imports into the sites of their own region only.
 *
 * Cell handling (copied from DeductionImportService, not shared with it): headings are trimmed, lower-cased and have
 * spaces and hyphens turned into underscores; whole-number floats lose their ".0"; empty rows are skipped.
 */
class EquipmentImportService
{
    use ReadsImportFiles;

    public const KIND_EXTINGUISHERS = 'extinguishers';
    public const KIND_KITS = 'kits';

    /** A guard against an enormous upload: the file is read into memory. */
    public const MAX_ROWS = 2000;

    public const EXTINGUISHER_HEADINGS = [
        'asset_code', 'serial_number', 'type', 'capacity', 'manufacturer', 'manufactured_on', 'site', 'district', 'location_detail',
        'expiry_date', 'last_serviced_on', 'next_service_due', 'last_hydro_test_on', 'next_hydro_test_due', 'responsible_staff_id', 'notes',
    ];

    public const KIT_HEADINGS = [
        'asset_code', 'kit_type', 'site', 'district', 'location_detail', 'responsible_staff_id', 'last_checked_on', 'notes',
    ];

    protected const REQUIRED = [
        self::KIND_EXTINGUISHERS => ['type', 'site'],
        self::KIND_KITS => ['kit_type', 'site'],
    ];

    protected const DATE_COLUMNS = [
        self::KIND_EXTINGUISHERS => ['manufactured_on', 'expiry_date', 'last_serviced_on', 'next_service_due', 'last_hydro_test_on', 'next_hydro_test_due'],
        self::KIND_KITS => ['last_checked_on'],
    ];

    /** Spellings accepted for each extinguisher type, compared with spaces, hyphens and underscores removed. */
    protected const TYPE_ALIASES = [
        'water' => HsFireExtinguisher::TYPE_WATER,
        'foam' => HsFireExtinguisher::TYPE_FOAM,
        'drypowder' => HsFireExtinguisher::TYPE_DRY_POWDER,
        'powder' => HsFireExtinguisher::TYPE_DRY_POWDER,
        'dcp' => HsFireExtinguisher::TYPE_DRY_POWDER,
        'co2' => HsFireExtinguisher::TYPE_CO2,
        'carbondioxide' => HsFireExtinguisher::TYPE_CO2,
        'wetchemical' => HsFireExtinguisher::TYPE_WET_CHEMICAL,
    ];

    public function __construct(
        protected EquipmentScope $scope,
        protected FireExtinguisherService $extinguishers,
        protected FirstAidKitService $kits,
    ) {}

    public function headings(string $kind): array
    {
        return $kind === self::KIND_KITS ? self::KIT_HEADINGS : self::EXTINGUISHER_HEADINGS;
    }

    /** A template with invented example rows: the sites and codes in it are not real. */
    public function templateExport(string $kind): ImportTemplateExport
    {
        $rows = $kind === self::KIND_KITS
            ? [
                ['', 'medium', 'Example District Office', '', 'Reception desk', '', '08/10/2026', 'Example row: delete it'],
            ]
            : [
                ['', 'SN-0001', 'dry powder', '9 kg', 'Example Maker', '01/03/2024', 'Example District Office', '', 'Ground floor corridor',
                    '01/03/2029', '01/03/2026', '01/03/2027', '', '', '', 'Example row: delete it'],
            ];

        return new ImportTemplateExport($this->headings($kind), $rows);
    }

    /**
     * Read the file and check every row, without writing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(string $kind, UploadedFile $file, User $actor): array
    {
        $this->guardKind($kind);
        abort_unless($this->scope->can($actor, 'health_safety.manage_equipment'), 403, 'You may not import equipment.');

        $rows = $this->readRows($file);
        $empty = [
            'kind' => $kind, 'headings' => $this->headings($kind), 'preview_rows' => [], 'valid_rows' => [], 'errors' => [], 'warnings' => [],
            'unmatched_sites' => [], 'total_rows' => 0, 'valid_count' => 0, 'error_count' => 0, 'error_rows' => 0, 'warning_count' => 0,
            'skipped_count' => 0, 'failure_percent' => 0.0, 'max_failure_percent' => (int) config('gwl.max_import_failure_percent', 20), 'blocked' => false,
        ];

        if ($rows->isEmpty()) {
            return [...$empty, 'errors' => [['row' => 'File', 'message' => 'The uploaded file is empty.']], 'error_count' => 1, 'blocked' => true];
        }

        $headings = collect($rows->shift() ?? [])->map(fn ($value) => $this->normalizeHeading($value))->values()->all();
        $missing = array_diff(self::REQUIRED[$kind], $headings);

        if ($missing !== []) {
            return [...$empty, 'headings' => $headings, 'errors' => [['row' => 'Header', 'message' => 'Missing required columns: '.implode(', ', $missing).'.']], 'error_count' => 1, 'blocked' => true];
        }

        $mapped = $rows->values()->map(fn ($row) => $this->mapRow($headings, $row))->reject(fn (array $row) => $this->isEmptyRow($row))->values();

        if ($mapped->count() > self::MAX_ROWS) {
            return [...$empty, 'headings' => $headings, 'errors' => [['row' => 'File', 'message' => 'The file has more than '.self::MAX_ROWS.' rows. Split it and import the parts one at a time.']], 'error_count' => 1, 'blocked' => true];
        }

        $sites = $this->sitesFor($actor);
        $employees = [];
        $seenCodes = [];
        $existingCodes = $this->existingCodes($kind);
        $errors = [];
        $warnings = [];
        $valid = [];
        $unmatched = [];
        $errorRows = 0;
        $skipped = 0;

        foreach ($mapped as $index => $row) {
            $rowNumber = $index + 2;
            $rowErrors = [];

            $code = $this->text($row['asset_code'] ?? null);

            if ($code !== '') {
                $key = mb_strtolower($code);

                if (isset($seenCodes[$key])) {
                    $rowErrors[] = 'Asset code '.$code.' appears more than once in this file.';
                }

                $seenCodes[$key] = true;
            }

            [$site, $siteProblem] = $this->resolveSite($sites, $this->text($row['site'] ?? null), $this->text($row['district'] ?? null));

            if ($siteProblem !== null) {
                $rowErrors[] = $siteProblem['message'];

                if ($siteProblem['unmatched'] !== null) {
                    // One entry per distinct name, whatever the case or spacing, keeping the first spelling met.
                    $unmatched[$this->siteKey($siteProblem['unmatched'])] ??= $siteProblem['unmatched'];
                }
            }

            $typeKey = $kind === self::KIND_KITS ? 'kit_type' : 'type';
            $type = $kind === self::KIND_KITS ? $this->kitType($row[$typeKey] ?? null) : $this->extinguisherType($row[$typeKey] ?? null);

            if ($type === null) {
                $rowErrors[] = 'Unknown '.($kind === self::KIND_KITS ? 'kit type' : 'extinguisher type').' "'.$this->text($row[$typeKey] ?? null).'".';
            }

            $dates = [];

            foreach (self::DATE_COLUMNS[$kind] as $column) {
                [$date, $problem] = $this->parseDate($row[$column] ?? null);

                if ($problem !== null) {
                    $rowErrors[] = str_replace('_', ' ', $column).': '.$problem;
                }

                $dates[$column] = $date;
            }

            foreach (['last_serviced_on', 'last_hydro_test_on', 'last_checked_on'] as $past) {
                if (($dates[$past] ?? null) !== null && Carbon::parse($dates[$past])->gt(today())) {
                    $rowErrors[] = str_replace('_', ' ', $past).' cannot be in the future.';
                }
            }

            $staffId = $this->text($row['responsible_staff_id'] ?? null);
            $employeeId = null;

            if ($staffId !== '') {
                $employees[$staffId] ??= Employee::query()->where('staff_id', $staffId)->value('id');
                $employeeId = $employees[$staffId];

                if (! $employeeId) {
                    $rowErrors[] = 'Staff ID '.$staffId.' was not found.';
                }
            }

            if (count($valid) + $errorRows < 8) {
                $previewRows[] = $row;
            }

            if ($rowErrors !== []) {
                $errorRows++;

                foreach ($rowErrors as $message) {
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                }

                continue;
            }

            if ($code !== '' && isset($existingCodes[mb_strtolower($code)])) {
                $skipped++;
                $warnings[] = ['row' => $rowNumber, 'message' => 'Asset code '.$code.' already exists, so this row was skipped.'];

                continue;
            }

            if ($kind === self::KIND_EXTINGUISHERS && $dates['expiry_date'] === null) {
                $warnings[] = ['row' => $rowNumber, 'message' => 'No expiry date: this extinguisher will not be flagged as expiring until one is set.'];
            }

            $valid[] = $this->attributes($kind, $row, $code, $type, $site, $employeeId, $dates);
        }

        $total = $mapped->count();
        $failurePercent = $total > 0 ? round(($errorRows / $total) * 100, 1) : 0.0;
        $max = (int) config('gwl.max_import_failure_percent', 20);

        return [
            ...$empty,
            'headings' => $headings,
            'preview_rows' => $previewRows ?? [],
            'valid_rows' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'unmatched_sites' => array_values($unmatched),
            'total_rows' => $total,
            'valid_count' => count($valid),
            'error_count' => count($errors),
            'error_rows' => $errorRows,
            'warning_count' => count($warnings),
            'skipped_count' => $skipped,
            'failure_percent' => $failurePercent,
            'max_failure_percent' => $max,
            'blocked' => $failurePercent > $max,
        ];
    }

    /**
     * Confirm: read the file again (nothing the browser holds is trusted), refuse a blocked run, and insert every usable
     * row in one transaction. Returns what was done.
     *
     * @return array{created: int, skipped: int, error_rows: int, total_rows: int}
     */
    public function import(string $kind, UploadedFile $file, User $actor): array
    {
        $preview = $this->preview($kind, $file, $actor);

        if ($preview['blocked']) {
            throw ValidationException::withMessages(['file' => $preview['errors'] !== [] && $preview['total_rows'] === 0
                ? $preview['errors'][0]['message']
                : sprintf('Import blocked because %.1f%% of rows have errors. The configured maximum is %d%%.', $preview['failure_percent'], $preview['max_failure_percent'])]);
        }

        if ($preview['valid_rows'] === []) {
            throw ValidationException::withMessages(['file' => 'There are no usable rows to import.']);
        }

        $created = DB::transaction(function () use ($kind, $preview, $actor) {
            foreach ($preview['valid_rows'] as $row) {
                $kind === self::KIND_KITS
                    ? $this->kits->create($actor, $row, audit: false)
                    : $this->extinguishers->create($actor, $row, audit: false);
            }

            return count($preview['valid_rows']);
        });

        Audit::log('health_safety.equipment_imported', 'health_safety', null, null, [
            'kind' => $kind,
            'file' => mb_substr($file->getClientOriginalName(), 0, 150),
            'created' => $created,
            'skipped_existing' => $preview['skipped_count'],
            'error_rows' => $preview['error_rows'],
            'total_rows' => $preview['total_rows'],
        ]);

        return ['created' => $created, 'skipped' => $preview['skipped_count'], 'error_rows' => $preview['error_rows'], 'total_rows' => $preview['total_rows']];
    }

    // ------------------------------------------------------------------ rows

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $dates
     * @return array<string, mixed>
     */
    protected function attributes(string $kind, array $row, string $code, string $type, HsSite $site, ?int $employeeId, array $dates): array
    {
        $common = [
            'asset_code' => $code !== '' ? $code : null,
            'site_id' => $site->id,
            'location_detail' => $this->text($row['location_detail'] ?? null) ?: null,
            'responsible_employee_id' => $employeeId,
            'notes' => $this->text($row['notes'] ?? null) ?: null,
        ];

        if ($kind === self::KIND_KITS) {
            return [...$common, 'kit_type' => $type, 'last_checked_on' => $dates['last_checked_on']];
        }

        return [
            ...$common,
            'extinguisher_type' => $type,
            'serial_number' => $this->text($row['serial_number'] ?? null) ?: null,
            'capacity' => $this->text($row['capacity'] ?? null) ?: null,
            'manufacturer' => $this->text($row['manufacturer'] ?? null) ?: null,
            ...$dates,
        ];
    }

    /**
     * @param  Collection<int, HsSite>  $sites
     * @return array{0: HsSite|null, 1: array{message: string, unmatched: string|null}|null}
     */
    protected function resolveSite(Collection $sites, string $name, string $district): array
    {
        if ($name === '') {
            return [null, ['message' => 'No site given.', 'unmatched' => null]];
        }

        $key = $this->siteKey($name);
        $matches = $sites->filter(fn (HsSite $site) => $this->siteKey($site->name) === $key);

        if ($district !== '') {
            $matches = $matches->filter(fn (HsSite $site) => $site->district && $this->siteKey($site->district->district_name) === $this->siteKey($district));
        }

        if ($matches->isEmpty()) {
            return [null, ['message' => 'Site "'.$name.'"'.($district !== '' ? ' in '.$district : '').' was not found. Add it under Sites, then upload again.', 'unmatched' => $name]];
        }

        $active = $matches->filter(fn (HsSite $site) => $site->is_active);

        if ($active->isEmpty()) {
            return [null, ['message' => 'Site "'.$name.'" is deactivated.', 'unmatched' => null]];
        }

        if ($active->count() > 1) {
            return [null, ['message' => 'Site "'.$name.'" matches more than one site. Add the district column to say which.', 'unmatched' => null]];
        }

        return [$active->first(), null];
    }

    /** @return Collection<int, HsSite> The sites a row may name: every region's, or only the actor's own. */
    protected function sitesFor(User $actor): Collection
    {
        $scope = $this->scope->scopeOf($actor);

        return HsSite::query()
            ->with('district')
            ->when($scope['level'] === ActorScope::REGION, fn ($query) => $query->where('region_id', $scope['id']))
            ->when($scope['level'] === ActorScope::DISTRICT, fn ($query) => $query->where('district_id', $scope['id']))
            ->when($scope['level'] === ActorScope::NONE, fn ($query) => $query->whereRaw('1 = 0'))
            ->get();
    }

    /** @return array<string, true> lower-cased asset codes already in the register */
    protected function existingCodes(string $kind): array
    {
        $model = $kind === self::KIND_KITS ? HsFirstAidKit::class : HsFireExtinguisher::class;

        return $model::query()->pluck('asset_code')->mapWithKeys(fn ($code) => [mb_strtolower((string) $code) => true])->all();
    }

    protected function extinguisherType(mixed $value): ?string
    {
        $key = preg_replace('/[\s_\-]+/', '', mb_strtolower($this->text($value)));

        return self::TYPE_ALIASES[$key] ?? null;
    }

    protected function kitType(mixed $value): ?string
    {
        $key = mb_strtolower($this->text($value));

        return array_key_exists($key, HsFirstAidKit::TYPES) ? $key : null;
    }

    // ------------------------------------------------------------------ cells

    protected function guardKind(string $kind): void
    {
        abort_unless(in_array($kind, [self::KIND_EXTINGUISHERS, self::KIND_KITS], true), 404);
    }

    protected function siteKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }
}
