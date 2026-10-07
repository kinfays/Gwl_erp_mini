<?php

namespace App\Services\Import;

use App\Enums\StaffGrade;
use App\Exports\ImportTemplateExport;
use App\Imports\RawRowsImport;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\EmployeeDirectory;
use App\Services\Uac\RoleAssignmentService;
use App\Services\Uac\RoleGrantPolicy;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class DataImportService
{
    public function __construct(
        protected EmployeeDirectory $directory,
        protected RoleGrantPolicy $roleGrants,
        protected RoleAssignmentService $roleAssignments,
    ) {}

    public function availableTypes(bool $includeUsers = true): array
    {
        return collect($this->definitions())
            ->filter(fn (array $definition, string $type) => $includeUsers || $type !== 'users')
            ->map(fn (array $definition, string $type) => [
                'type' => $type,
                'label' => $definition['label'],
                'description' => $definition['description'],
            ])
            ->values()
            ->all();
    }

    public function templateExport(string $type): ImportTemplateExport
    {
        $definition = $this->definition($type);

        return new ImportTemplateExport(
            $definition['headings'],
            $definition['sample_rows']
        );
    }

    public function preview(UploadedFile $file, string $type, ?User $actor = null): array
    {
        $definition = $this->definition($type);
        $this->authorizeType($type, $actor);
        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            return [
                'type' => $type,
                'headings' => $definition['headings'],
                'preview_rows' => [],
                'valid_rows' => [],
                'errors' => [['row' => 'File', 'message' => 'The uploaded file is empty.']],
                'total_rows' => 0,
                'valid_count' => 0,
                'error_count' => 1,
            ];
        }

        $headings = collect($rows->shift() ?? [])
            ->map(fn ($value) => $this->normalizeHeading($value))
            ->values()
            ->all();

        $missingHeadings = array_diff(
            array_diff($definition['headings'], $definition['optional_headings'] ?? []),
            $headings
        );
        $errors = [];
        $warnings = [];

        if (! empty($missingHeadings)) {
            $errors[] = [
                'row' => 'Header',
                'message' => 'Missing required columns: '.implode(', ', $missingHeadings),
            ];
        }

        $previewRows = [];
        $validRows = [];
        $processedRows = 0;

        foreach ($rows->values() as $index => $row) {
            $mapped = $this->mapRow($headings, $row);

            if ($this->isEmptyRow($mapped)) {
                continue;
            }

            $processedRows++;
            [$normalized, $rowErrors] = $this->validateRow($type, $mapped, $index + 2, $actor);

            if (count($previewRows) < 8) {
                $previewRows[] = $mapped;
            }

            // Staff without a grade still import, but they are listed so HR can grade them afterwards.
            if ($type === 'employees' && $rowErrors === [] && blank($normalized['grade'] ?? null)) {
                $warnings[] = [
                    'row' => $index + 2,
                    'message' => 'Grade missing'.(filled($normalized['staff_id'] ?? null) ? ' for staff ID '.$normalized['staff_id'] : '').': imported without a grade.',
                ];
            }

            if ($rowErrors !== []) {
                $errors = [...$errors, ...$rowErrors];

                continue;
            }

            $validRows[] = $normalized;
        }

        return [
            'type' => $type,
            'headings' => $headings,
            'preview_rows' => $previewRows,
            'valid_rows' => $validRows,
            'errors' => $errors,
            'warnings' => $warnings,
            'grade_missing_count' => count($warnings),
            'total_rows' => $processedRows,
            'valid_count' => count($validRows),
            'error_count' => count($errors),
        ];
    }

    public function run(string $type, array $rows, ?User $actor = null): array
    {
        $this->authorizeType($type, $actor);

        $created = 0;
        $updated = 0;
        $gradeMissing = 0;

        DB::transaction(function () use ($type, $rows, $actor, &$created, &$updated, &$gradeMissing) {
            foreach ($rows as $row) {
                [$wasRecentlyCreated] = match ($type) {
                    'departments' => [$this->upsertDepartment($row)],
                    'regions' => [$this->upsertRegion($row)],
                    'districts' => [$this->upsertDistrict($row)],
                    'job_titles' => [$this->upsertJobTitle($row)],
                    'employees' => [$this->upsertEmployee($row, $actor)],
                    'users' => [$this->upsertUser($row, $actor)],
                    default => [false],
                };

                if ($wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                if ($type === 'employees' && blank($row['grade'] ?? null)) {
                    $gradeMissing++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'processed' => count($rows),
        ] + ($type === 'employees' ? ['grade_missing' => $gradeMissing] : []);
    }

    protected function validateRow(string $type, array $row, int $rowNumber, ?User $actor = null): array
    {
        $normalized = $this->normalizeRow($type, $row);
        $validator = Validator::make($normalized, $this->rulesFor($type, $normalized, $actor), [], $this->attributesFor($type));
        $errors = [];

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $message,
                ];
            }
        }

        return [$normalized, $errors];
    }

    protected function normalizeRow(string $type, array $row): array
    {
        $normalized = collect($row)
            ->mapWithKeys(fn ($value, $key) => [$key => is_string($value) ? trim($value) : $value])
            ->all();

        if (in_array($type, ['employees', 'users'], true)) {
            $normalized['staff_id'] = $this->normalizeCellText($normalized['staff_id'] ?? null);
        }

        if ($type === 'employees') {
            foreach (['full_name', 'title', 'gender', 'category', 'grade', 'email', 'job_title_name', 'department_name', 'district_name', 'region_name', 'unit', 'present_appointment'] as $field) {
                if (array_key_exists($field, $normalized)) {
                    $normalized[$field] = $this->normalizeCellText($normalized[$field]);
                }
            }

            // Spelling and case variants of a grade ("snr gd level 2", "Junior Grade L3") are read as the real grade; anything
            // that isn't one is kept as typed so the row is rejected with it named. A grade fixes the category.
            $normalized['grade'] = ($normalized['grade'] ?? '') === ''
                ? null
                : (StaffGrade::fromInput($normalized['grade'])?->value ?? $normalized['grade']);

            // An honorific in any case ("ING.", "dr") is read as the real one; anything else is kept so the row is rejected.
            $normalized['title'] = ($normalized['title'] ?? '') === ''
                ? null
                : (collect(Employee::TITLES)->first(fn (string $known) => strcasecmp(rtrim($known, '.'), rtrim((string) $normalized['title'], '.')) === 0) ?? $normalized['title']);

            if (($normalized['category'] ?? '') !== '') {
                $normalized['category'] = $this->canonicalCategory($normalized['category']);
            }
        }

        if ($type === 'users') {
            $normalized['role_slugs'] = collect(explode(',', (string) ($normalized['role_slugs'] ?? '')))
                ->map(fn (string $role) => trim($role))
                ->filter()
                ->values()
                ->all();
            $normalized['is_active'] = ! in_array(Str::lower((string) ($normalized['is_active'] ?? '1')), ['0', 'false', 'no'], true);
        }

        return $normalized;
    }

    /** The category as the app stores it: any case is accepted, and the old "Charwoman" category is Contract. */
    protected function canonicalCategory(string $category): string
    {
        $match = collect([...StaffGrade::allCategories(), 'Charwoman'])
            ->first(fn (string $known) => strcasecmp($known, $category) === 0);

        return $match === 'Charwoman' ? StaffGrade::CATEGORY_CONTRACT : ($match ?? $category);
    }

    protected function rulesFor(string $type, array $row, ?User $actor = null): array
    {
        return match ($type) {
            'departments' => [
                'department_name' => ['required', 'string', 'max:255'],
            ],
            'regions' => [
                'region_name' => ['required', 'string', 'max:255'],
            ],
            'districts' => [
                'district_name' => ['required', 'string', 'max:255'],
                'region_name' => ['required', 'string', 'max:255'],
            ],
            'job_titles' => [
                'job_title_name' => ['required', 'string', 'max:255'],
            ],
            'employees' => [
                'staff_id' => [
                    'required',
                    'string',
                    'max:50',
                    function (string $attribute, mixed $value, Closure $fail) use ($actor): void {
                        if (! $this->canImportExistingEmployee($actor, $value)) {
                            $fail('You can only import employees in your own region.');
                        }
                    },
                ],
                'full_name' => ['required', 'string', 'max:255'],
                'title' => ['nullable', Rule::in(Employee::TITLES)],
                'gender' => ['required', 'in:Male,Female'],
                // A grade fixes the category, so the category column only has to be filled for rows with no grade.
                'grade' => [
                    'nullable',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! StaffGrade::tryFrom((string) $value)) {
                            $fail('The grade "'.$value.'" is not recognised. Use one of: '.implode(', ', StaffGrade::values()).'.');
                        }
                    },
                ],
                'category' => [
                    Rule::requiredIf(blank($row['grade'] ?? null)),
                    'nullable',
                    'in:'.implode(',', StaffGrade::allCategories()),
                    function (string $attribute, mixed $value, Closure $fail) use ($row): void {
                        $grade = StaffGrade::tryFrom((string) ($row['grade'] ?? ''));

                        if ($grade && filled($value) && StaffGrade::reportCategory((string) $value) !== $grade->category()) {
                            $fail('The category "'.$value.'" does not match the grade "'.$grade->value.'" ('.$grade->category().').');
                        }
                    },
                ],
                'email' => ['required', 'email', 'max:255'],
                'job_title_name' => [
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! $this->referenceExists(JobTitle::class, 'job_title_name', $value)) {
                            $fail('The job title must already exist in the system.');
                        }
                    },
                ],
                'department_name' => [
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! $this->referenceExists(Department::class, 'department_name', $value)) {
                            $fail('The department must already exist in the system.');
                        }
                    },
                ],
                'district_name' => [
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, Closure $fail) use ($row): void {
                        if (! $this->referenceExists(District::class, 'district_name', $value)) {
                            $fail('The district must already exist in the system.');

                            return;
                        }

                        $region = $this->findReference(Region::class, 'region_name', $row['region_name'] ?? null);

                        if ($region && ! $this->findDistrictForRegion($value, $region)) {
                            $fail('The district must belong to the selected region.');
                        }
                    },
                ],
                'region_name' => [
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, Closure $fail) use ($actor): void {
                        $region = $this->findReference(Region::class, 'region_name', $value);

                        if (! $region) {
                            $fail('The region must already exist in the system.');

                            return;
                        }

                        if (! $this->canPlaceEmployeeInRegion($actor, $region)) {
                            $fail('Employees must be placed in your own region.');
                        }
                    },
                ],
                'date_of_birth' => ['required', 'date'],
                'date_joined' => ['nullable', 'date'],
                'unit' => ['nullable', 'string', 'max:255'],
                'present_appointment' => ['nullable', 'date'],
            ],
            'users' => [
                'staff_id' => [
                    'required',
                    'string',
                    function (string $attribute, mixed $value, Closure $fail) use ($actor): void {
                        $employee = $actor
                            ? $this->roleGrants->employeesQueryFor($actor)->where('staff_id', $value)->first()
                            : null;

                        if (! $employee) {
                            $fail('The selected staff ID is invalid or outside your scope.');
                        }
                    },
                ],
                'email' => ['required', 'email', 'max:255'],
                'role_slugs' => ['required', 'array', 'min:1'],
                'role_slugs.*' => [
                    Rule::exists('roles', 'name')->where(fn ($query) => $query->where('name', '!=', User::ROLE_EMPLOYEE)),
                    // Same rules as the users screen: tiers, the ICT allow-list, role/location fit, anti-escalation.
                    function (string $attribute, mixed $value, Closure $fail) use ($actor, $row): void {
                        $role = Role::query()->with('permissions')->where('name', $value)->first();
                        $employee = $actor
                            ? $this->roleGrants->employeesQueryFor($actor)->where('staff_id', $row['staff_id'] ?? null)->first()
                            : null;

                        if ($role && $employee && $actor && ($denial = $this->roleGrants->assignmentDenial($actor, $role, $employee))) {
                            $fail($denial);
                        }
                    },
                ],
            ],
            default => [],
        };
    }

    protected function attributesFor(string $type): array
    {
        return match ($type) {
            'employees' => [
                'staff_id' => 'staff ID',
                'job_title_name' => 'job title',
                'department_name' => 'department',
                'district_name' => 'district',
                'region_name' => 'region',
            ],
            'users' => [
                'staff_id' => 'staff ID',
                'role_slugs' => 'roles',
            ],
            default => [],
        };
    }

    protected function readRows(UploadedFile $file): Collection
    {
        // Excel 4.x opens an UploadedFile through getRealPath(), which is false for PHP's upload temp file on some
        // Windows setups ("Path must not be empty"). Read a copy kept under storage instead, where realpath() works.
        $directory = storage_path('app/private/import-tmp');
        File::ensureDirectoryExists($directory);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'xlsx');
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;

        if (! @copy($file->getPathname(), $path)) {
            throw new RuntimeException('The uploaded file could not be read. Please upload it again.');
        }

        try {
            $import = new RawRowsImport;
            Excel::import($import, $path);

            return $import->rows;
        } finally {
            @unlink($path);
        }
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

    protected function referenceExists(string $model, string $column, mixed $value): bool
    {
        return (bool) $this->findReference($model, $column, $value);
    }

    protected function findReference(string $model, string $column, mixed $value): mixed
    {
        $value = $this->normalizeCellText($value);

        if ($value === '') {
            return null;
        }

        return $model::query()
            ->whereRaw('LOWER('.$column.') = ?', [Str::lower($value)])
            ->first();
    }

    protected function findDistrictForRegion(mixed $districtName, Region $region): ?District
    {
        $districtName = $this->normalizeCellText($districtName);

        if ($districtName === '') {
            return null;
        }

        return District::query()
            ->where('region_id', $region->id)
            ->whereRaw('LOWER(district_name) = ?', [Str::lower($districtName)])
            ->first();
    }

    protected function canImportExistingEmployee(?User $actor, mixed $staffId): bool
    {
        $actor ??= Auth::user();

        if (! $actor || $this->directory->assignableRegionIds($actor) === null) {
            return true;
        }

        $existing = Employee::query()
            ->where('staff_id', $this->normalizeCellText($staffId))
            ->first();

        return ! $existing || $this->directory->canAccess($actor, $existing);
    }

    protected function canPlaceEmployeeInRegion(?User $actor, Region $region): bool
    {
        $actor ??= Auth::user();
        $regionIds = $actor ? $this->directory->assignableRegionIds($actor) : null;

        return $regionIds === null || in_array((int) $region->id, $regionIds, true);
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

    protected function upsertDepartment(array $row): bool
    {
        $department = Department::firstOrCreate([
            'department_name' => $row['department_name'],
        ]);

        return $department->wasRecentlyCreated;
    }

    protected function upsertRegion(array $row): bool
    {
        $region = Region::firstOrCreate([
            'region_name' => $row['region_name'],
        ]);

        return $region->wasRecentlyCreated;
    }

    protected function upsertDistrict(array $row): bool
    {
        $region = Region::firstOrCreate([
            'region_name' => $row['region_name'],
        ]);

        $district = District::updateOrCreate(
            [
                'district_name' => $row['district_name'],
                'region_id' => $region->id,
            ],
            []
        );

        return $district->wasRecentlyCreated;
    }

    protected function upsertJobTitle(array $row): bool
    {
        $jobTitle = JobTitle::firstOrCreate([
            'job_title_name' => $row['job_title_name'],
        ]);

        return $jobTitle->wasRecentlyCreated;
    }

    protected function upsertEmployee(array $row, ?User $actor = null): bool
    {
        $region = $this->findReference(Region::class, 'region_name', $row['region_name']);
        $district = $region ? $this->findDistrictForRegion($row['district_name'], $region) : null;
        $department = $this->findReference(Department::class, 'department_name', $row['department_name']);
        $jobTitle = $this->findReference(JobTitle::class, 'job_title_name', $row['job_title_name']);

        if (! $region || ! $district || ! $department || ! $jobTitle) {
            throw ValidationException::withMessages([
                'import' => 'Employee import reference data changed. Please preview the file again.',
            ]);
        }

        if (! $this->canPlaceEmployeeInRegion($actor, $region) || ! $this->canImportExistingEmployee($actor, $row['staff_id'])) {
            throw ValidationException::withMessages([
                'import' => 'You can only import employees in your own region. Please preview the file again.',
            ]);
        }

        $grade = StaffGrade::tryFrom((string) ($row['grade'] ?? ''));

        $employee = Employee::updateOrCreate(
            ['staff_id' => $row['staff_id']],
            array_filter([
                // A row with no grade (or no title) never clears one the employee already has.
                'grade' => $grade?->value,
                'title' => $row['title'] ?? null,
            ], fn ($value) => $value !== null) + [
                'full_name' => $row['full_name'],
                'gender' => $row['gender'],
                // Fixed by the grade (Employee::saving) when there is one.
                'category' => $grade?->category() ?? $row['category'],
                'email' => $row['email'],
                'job_title_id' => $jobTitle->id,
                'department_id' => $department->id,
                'district_id' => $district->id,
                'region_id' => $region->id,
                'date_of_birth' => $row['date_of_birth'],
                'date_joined' => $row['date_joined'] ?: null,
                'unit' => $row['unit'] ?: null,
                'present_appointment' => $row['present_appointment'] ?: null,
                'is_active' => true,
            ]
        );

        return $employee->wasRecentlyCreated;
    }

    protected function upsertUser(array $row, ?User $actor): bool
    {
        if (! $actor) {
            throw new AuthorizationException('Importing users needs a signed-in user.');
        }

        $employee = $this->roleGrants->employeesQueryFor($actor)->where('staff_id', $row['staff_id'])->firstOrFail();

        $payload = [
            'employee_id' => $employee->id,
            'email' => $row['email'],
            'is_active' => (bool) $row['is_active'],
        ];

        if (Schema::hasColumn('users', 'full_name')) {
            $payload['full_name'] = $employee->full_name;
        }

        $user = User::query()->firstOrNew([
            'staff_id' => $employee->staff_id,
        ]);

        $user->fill($payload);

        if (! $user->exists) {
            $user->password = Hash::make(User::DEFAULT_PASSWORD);

            if (Schema::hasColumn('users', 'must_change_password')) {
                $user->must_change_password = true;
            }
        }

        $user->save();

        // The same role checks and audit as the users screen; roles the actor doesn't manage stay as they are.
        // A refused role throws, which rolls the whole import back (run() is one transaction).
        $this->roleAssignments->sync(
            $actor,
            $user,
            Role::query()->whereIn('name', $row['role_slugs'])->pluck('id')->all()
        );

        return $user->wasRecentlyCreated;
    }

    /** Importing users assigns roles, so it is a Global Admin / super_admin job whichever screen the file came from. */
    protected function authorizeType(string $type, ?User $actor): void
    {
        if ($type === 'users' && (! $actor || ! $this->roleGrants->canImportUsers($actor))) {
            abort(403, 'You are not allowed to import users.');
        }
    }

    protected function definition(string $type): array
    {
        $definitions = $this->definitions();

        if (! array_key_exists($type, $definitions)) {
            abort(404, 'Unknown import type.');
        }

        return $definitions[$type];
    }

    protected function definitions(): array
    {
        return [
            'employees' => [
                'label' => 'Employees',
                'description' => 'Staff records with leave-driving profile data.',
                'headings' => [
                    'staff_id',
                    'full_name',
                    'title',
                    'gender',
                    'category',
                    'grade',
                    'email',
                    'job_title_name',
                    'department_name',
                    'district_name',
                    'region_name',
                    'date_of_birth',
                    'date_joined',
                    'unit',
                    'present_appointment',
                ],
                // A file without the grade column still imports (those staff are listed as "grade missing").
                'optional_headings' => ['title', 'grade'],
                'sample_rows' => [
                    ['EMP001', 'Akosua Mensah', 'Ms.', 'Female', 'Management', 'Mgt. Gd. Level 2', 'akosua.mensah@example.com', 'HR Officer', 'Administration', 'Accra West Regional Office', 'Greater Accra', '1990-04-12', '2020-09-01', 'HR Operations', '2024-01-15'],
                ],
            ],
            'departments' => [
                'label' => 'Departments',
                'description' => 'Department master data.',
                'headings' => ['department_name'],
                'sample_rows' => [
                    ['Administration'],
                ],
            ],
            'regions' => [
                'label' => 'Regions',
                'description' => 'Region master data.',
                'headings' => ['region_name'],
                'sample_rows' => [
                    ['Greater Accra'],
                ],
            ],
            'districts' => [
                'label' => 'Districts',
                'description' => 'Districts mapped to regions.',
                'headings' => ['district_name', 'region_name'],
                'sample_rows' => [
                    ['Accra West Regional Office', 'Greater Accra'],
                ],
            ],
            'job_titles' => [
                'label' => 'Job Titles',
                'description' => 'Job title master data.',
                'headings' => ['job_title_name'],
                'sample_rows' => [
                    ['HR Officer'],
                ],
            ],
            'users' => [
                'label' => 'Users',
                'description' => 'Attach roles and account state to existing employees.',
                'headings' => ['staff_id', 'email', 'role_slugs', 'is_active'],
                'sample_rows' => [
                    ['EMP001', 'akosua.mensah@example.com', 'hr_region', '1'],
                ],
            ],
        ];
    }
}
