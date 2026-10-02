<?php

namespace App\Services\Hr;

use App\Enums\StaffGrade;
use App\Models\CompulsoryLeavePeriod;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\Region;
use App\Models\User;
use App\Services\Leave\LeaveEntitlementCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Workforce analytics for HR: milestones (birthdays, anniversaries, retirement), headcount and turnover, how staff are
 * distributed, why people leave, and who has no grade yet. One place for the scoping, the date arithmetic and the caching.
 *
 * Scope:    super_admin, Global Admin (admin) and Head Office HR see every region (and may narrow to one region or
 *           department); regional HR see their own region only (the region their employee record sits in, the same rule
 *           as the staff list and Staff Reports). Anyone else is refused. Staff holding the super_admin role are never
 *           counted for anyone.
 * Counting: every figure is a query-level aggregate or a column-only fetch of the people it names: no model is loaded per
 *           row and nothing queries inside a loop. The result is a plain array (cached values are unserialised without
 *           classes) kept for gwl.hr_analytics_cache_seconds; 0 turns the cache off.
 * Exits:    an exit is a deactivated employee with a deactivation date, whatever the reason except Transfer (an internal
 *           move: shown in the exit-reasons chart, left out of the exit count and the turnover rate). Turnover is the
 *           exits in the period over the average of the headcount at the start and at the end of it. A deactivated
 *           employee with no deactivation date can't be placed in time, so they count in neither.
 * Dates:    "today" is $asOf (default today); birthdays and anniversaries of 29 February fall on the 28th in other years;
 *           the retirement date is the date of birth plus gwl.retirement_age years (Employee::retirementDateFromBirthDate()).
 */
class HrAnalyticsService
{
    /** Anniversaries called out for the year: staff completing exactly this many years of service. */
    public const MILESTONE_YEARS = [5, 10, 15, 20];

    /** How many names each list on the page carries; the count beside it is always the full figure. */
    public const LIST_LIMIT = 25;

    public const AGE_BANDS = [
        'Under 25' => [0, 24],
        '25 to 34' => [25, 34],
        '35 to 44' => [35, 44],
        '45 to 54' => [45, 54],
        '55 and over' => [55, 200],
    ];

    public function __construct(protected LeaveEntitlementCalculator $calculator) {}

    /**
     * Who may open the analytics and how much of the workforce they see.
     *
     * @return array{all: bool, region_id: int|null}
     */
    public function scopeFor(?User $user): array
    {
        abort_unless($user, 403);

        if ($user->hasRoles('super_admin', 'admin', 'hr_headoffice')) {
            return ['all' => true, 'region_id' => null];
        }

        abort_unless($user->hasRoles('hr_region'), 403, 'HR analytics are for HR.');

        $regionId = ($user->employee ?? $user->employeeByStaffId)?->region_id;

        abort_if(! $regionId, 403, 'Employee region is required for regional HR analytics.');

        return ['all' => false, 'region_id' => (int) $regionId];
    }

    /** Regions (only for those who see more than one) and departments to filter by. */
    public function filterOptions(User $user): array
    {
        $scope = $this->scopeFor($user);
        $staff = $this->staff($scope);

        return [
            'regions' => $scope['all']
                ? Region::query()->whereIn('id', (clone $staff)->whereNotNull('region_id')->distinct()->select('region_id'))->orderBy('region_name')->get(['id', 'region_name'])->all()
                : [],
            'departments' => Department::query()->whereIn('id', (clone $staff)->whereNotNull('department_id')->distinct()->select('department_id'))->orderBy('department_name')->get(['id', 'department_name'])->all(),
            'scope_label' => $scope['all'] ? 'All regions' : (Region::query()->whereKey($scope['region_id'])->value('region_name') ?? 'Your region'),
        ];
    }

    /**
     * @param  array{region_id?: int|string|null, department_id?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function analytics(User $user, array $filters = [], ?CarbonInterface $asOf = null): array
    {
        $scope = $this->scopeFor($user);
        $asOf = ($asOf ? Carbon::instance($asOf) : today())->copy()->startOfDay();
        $filters = [
            'region_id' => $scope['all'] && filled($filters['region_id'] ?? null) ? (int) $filters['region_id'] : null,
            'department_id' => filled($filters['department_id'] ?? null) ? (int) $filters['department_id'] : null,
        ];

        $build = fn () => $this->build($scope, $filters, $asOf);
        $seconds = (int) config('gwl.hr_analytics_cache_seconds', 120);

        if ($seconds <= 0) {
            return $build();
        }

        // The scope is part of the key, so a regional HR user can never be served the all-regions figures.
        return Cache::remember(
            'hr_analytics:v1:'.md5(json_encode([$scope, $filters, $asOf->toDateString(), Employee::retirementAge(), (int) config('gwl.retirement_window_months', 12)])),
            now()->addSeconds($seconds),
            $build
        );
    }

    /**
     * @param  array{all: bool, region_id: int|null}  $scope
     * @param  array{region_id: int|null, department_id: int|null}  $filters
     * @return array<string, mixed>
     */
    protected function build(array $scope, array $filters, Carbon $asOf): array
    {
        $staff = $this->staff($scope, $filters);
        $active = (clone $staff)->where('is_active', true);
        $people = (clone $active)->get(['id', 'staff_id', 'full_name', 'date_of_birth', 'date_joined', 'category', 'grade', 'location_type']);

        $headcount = $this->headcount($staff, $people, $asOf);

        return [
            'as_of' => $asOf->toDateString(),
            'scope' => $scope['all'] ? ($filters['region_id'] ? 'region' : 'all') : 'own_region',
            'milestones' => $this->milestones($active, $asOf),
            'headcount' => $headcount,
            'age' => $this->ageProfile($people, $asOf),
            'distribution' => $this->distribution($active, $people),
            'exit_reasons' => $this->exitReasons($staff, $asOf),
            'grades' => $this->gradeSummary($people, $asOf),
        ];
    }

    // ------------------------------------------------------------------ scope

    /**
     * Employees in scope, regardless of status. Staff holding the super_admin role are excluded for every viewer.
     *
     * @param  array{all: bool, region_id: int|null}  $scope
     * @param  array{region_id?: int|null, department_id?: int|null}  $filters
     */
    protected function staff(array $scope, array $filters = []): Builder
    {
        return Employee::query()
            ->visibleInErp()
            ->when(! $scope['all'], fn (Builder $query) => $query->where('region_id', $scope['region_id']))
            ->when($scope['all'] && ! empty($filters['region_id']), fn (Builder $query) => $query->where('region_id', $filters['region_id']))
            ->when(! empty($filters['department_id']), fn (Builder $query) => $query->where('department_id', $filters['department_id']));
    }

    // ------------------------------------------------------------------ (a) milestones

    protected function milestones(Builder $active, Carbon $asOf): array
    {
        $birthdayRows = (clone $active)->whereNotNull('date_of_birth')->whereMonth('date_of_birth', $asOf->month)
            ->get(['id', 'staff_id', 'full_name', 'date_of_birth']);
        $anniversaryRows = (clone $active)->whereNotNull('date_joined')->whereMonth('date_joined', $asOf->month)
            ->whereDate('date_joined', '<', $asOf->copy()->startOfYear()->toDateString())
            ->get(['id', 'staff_id', 'full_name', 'date_joined']);

        $milestoneYears = collect(self::MILESTONE_YEARS)->map(fn (int $years) => $asOf->year - $years);
        $milestoneRows = (clone $active)->whereNotNull('date_joined')
            ->where(function (Builder $query) use ($milestoneYears) {
                foreach ($milestoneYears as $year) {
                    $query->orWhere(fn (Builder $inner) => $inner
                        ->whereDate('date_joined', '>=', Carbon::create($year, 1, 1)->toDateString())
                        ->whereDate('date_joined', '<=', Carbon::create($year, 12, 31)->toDateString()));
                }
            })
            ->get(['id', 'staff_id', 'full_name', 'date_joined']);

        $birthdays = $birthdayRows
            ->map(fn (Employee $employee) => $this->person($employee) + [
                'date' => $this->onDayInYear($employee->date_of_birth, $asOf->year)->toDateString(),
                'turning' => $asOf->year - $employee->date_of_birth->year,
            ])
            ->sortBy([['date', 'asc'], ['name', 'asc']])
            ->values();

        $anniversaries = $anniversaryRows
            ->map(fn (Employee $employee) => $this->person($employee) + [
                'date' => $this->onDayInYear($employee->date_joined, $asOf->year)->toDateString(),
                'years' => $asOf->year - $employee->date_joined->year,
            ])
            ->sortBy([['date', 'asc'], ['name', 'asc']])
            ->values();

        $byYears = collect(self::MILESTONE_YEARS)->mapWithKeys(fn (int $years) => [
            $years => $milestoneRows
                ->filter(fn (Employee $employee) => $employee->date_joined->year === $asOf->year - $years)
                ->map(fn (Employee $employee) => $this->person($employee) + [
                    'date' => $this->onDayInYear($employee->date_joined, $asOf->year)->toDateString(),
                    'years' => $years,
                ])
                ->sortBy([['date', 'asc'], ['name', 'asc']])
                ->values(),
        ]);

        return [
            'birthdays' => $this->listing($birthdays),
            'anniversaries' => $this->listing($anniversaries),
            'service_years' => $byYears->map(fn (Collection $rows, int $years) => ['years' => $years] + $this->listing($rows))->values()->all(),
            'retirement' => $this->retirement($active, $asOf),
        ];
    }

    /**
     * Staff whose retirement date (date of birth + retirement age) falls between today and the end of the window, soonest
     * first, plus how many active staff are already past it.
     */
    protected function retirement(Builder $active, Carbon $asOf): array
    {
        $age = Employee::retirementAge();
        $months = max(1, (int) config('gwl.retirement_window_months', 12));
        $windowEnd = $asOf->copy()->addMonthsNoOverflow($months);

        // Born between (today - age) and (window end - age); a day either side is fetched and the exact retirement date is
        // checked below, so a 29 February birth can't slip across the edge.
        $candidates = (clone $active)->whereNotNull('date_of_birth')
            ->whereDate('date_of_birth', '>=', $asOf->copy()->subYearsNoOverflow($age)->subDay()->toDateString())
            ->whereDate('date_of_birth', '<=', $windowEnd->copy()->subYearsNoOverflow($age)->addDay()->toDateString())
            ->get(['id', 'staff_id', 'full_name', 'date_of_birth']);

        $approaching = $candidates
            ->map(fn (Employee $employee) => $this->person($employee) + ['date' => Employee::retirementDateFromBirthDate($employee->date_of_birth)->toDateString()])
            ->filter(fn (array $row) => $row['date'] >= $asOf->toDateString() && $row['date'] <= $windowEnd->toDateString())
            ->sortBy([['date', 'asc'], ['name', 'asc']])
            ->values();

        // Already past it: born clearly more than the retirement age ago, plus the two days at the edge checked exactly.
        $pastEdge = $asOf->copy()->subYearsNoOverflow($age);
        $overdue = (clone $active)->whereNotNull('date_of_birth')
            ->whereDate('date_of_birth', '<', $pastEdge->copy()->subDay()->toDateString())
            ->count()
            + (clone $active)->whereNotNull('date_of_birth')
                ->whereDate('date_of_birth', '>=', $pastEdge->copy()->subDay()->toDateString())
                ->whereDate('date_of_birth', '<=', $pastEdge->copy()->addDay()->toDateString())
                ->get(['date_of_birth'])
                ->filter(fn (Employee $employee) => Employee::retirementDateFromBirthDate($employee->date_of_birth)->lt($asOf))
                ->count();

        return [
            'age' => $age,
            'window_months' => $months,
            'window_end' => $windowEnd->toDateString(),
            'overdue' => $overdue,
        ] + $this->listing($approaching);
    }

    // ------------------------------------------------------------------ (b) headcount

    /**
     * @param  Collection<int, Employee>  $people  active staff (id, dob, joined...)
     */
    protected function headcount(Builder $staff, Collection $people, Carbon $asOf): array
    {
        $monthStart = $asOf->copy()->startOfMonth();
        $yearStart = $asOf->copy()->startOfYear();

        $hires = fn (Carbon $from) => (clone $staff)->whereNotNull('date_joined')
            ->whereDate('date_joined', '>=', $from->toDateString())->whereDate('date_joined', '<=', $asOf->toDateString())->count();

        $exits = fn (Carbon $from) => $this->departures($staff, $from, $asOf)
            ->where(fn (Builder $query) => $query->whereNull('deactivation_reason')->orWhere('deactivation_reason', '!=', Employee::EXIT_REASON_TRANSFER))
            ->count();

        $turnover = function (Carbon $from, int $exited) use ($staff, $asOf) {
            $average = ($this->headcountAt($staff, $from->copy()->subDay()) + $this->headcountAt($staff, $asOf)) / 2;

            return [
                'average_headcount' => round($average, 1),
                'rate' => $average > 0 ? round($exited / $average * 100, 1) : null,
            ];
        };

        $exitsMonth = $exits($monthStart);
        $exitsYear = $exits($yearStart);

        $tenures = $people->filter(fn (Employee $employee) => $employee->date_joined)
            ->map(fn (Employee $employee) => max(0, $employee->date_joined->diffInDays($asOf, false)) / 365.25);

        return [
            'total_active' => $people->count(),
            'new_hires_month' => $hires($monthStart),
            'new_hires_year' => $hires($yearStart),
            'exits_month' => $exitsMonth,
            'exits_year' => $exitsYear,
            'turnover_month' => $turnover($monthStart, $exitsMonth),
            'turnover_year' => $turnover($yearStart, $exitsYear),
            'average_tenure_years' => $tenures->isEmpty() ? null : round($tenures->avg(), 1),
            'tenure_unknown' => $people->count() - $tenures->count(),
        ];
    }

    /** Employees who left (any reason) between $from and $to, both inclusive. */
    protected function departures(Builder $staff, Carbon $from, Carbon $to): Builder
    {
        return (clone $staff)->where('is_active', false)->whereNotNull('deactivated_at')
            ->whereDate('deactivated_at', '>=', $from->toDateString())
            ->whereDate('deactivated_at', '<=', $to->toDateString());
    }

    /** Staff on the books at the end of $date: hired by then (no hire date counts as before) and not yet deactivated. */
    protected function headcountAt(Builder $staff, Carbon $date): int
    {
        return (clone $staff)
            ->where(fn (Builder $query) => $query->whereNull('date_joined')->orWhereDate('date_joined', '<=', $date->toDateString()))
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->orWhere(fn (Builder $left) => $left->whereNotNull('deactivated_at')->whereDate('deactivated_at', '>', $date->toDateString())))
            ->count();
    }

    /** Average age of active staff and how they fall into bands. */
    protected function ageProfile(Collection $people, Carbon $asOf): array
    {
        $ages = $people->filter(fn (Employee $employee) => $employee->date_of_birth)
            ->map(fn (Employee $employee) => (int) $employee->date_of_birth->diffInYears($asOf, false));

        $bands = collect(self::AGE_BANDS)->map(fn (array $range, string $label) => [
            'label' => $label,
            'count' => $ages->filter(fn (int $age) => $age >= $range[0] && $age <= $range[1])->count(),
        ])->values()->all();

        return [
            'average' => $ages->isEmpty() ? null : round($ages->avg(), 1),
            'unknown' => $people->count() - $ages->count(),
            'bands' => $bands,
        ];
    }

    // ------------------------------------------------------------------ (c) distribution

    protected function distribution(Builder $active, Collection $people): array
    {
        $departments = $this->grouped($active, 'department_id');
        $regions = $this->grouped($active, 'region_id');
        $districts = $this->grouped($active, 'district_id');

        $departmentNames = Department::query()->whereIn('id', $departments->keys()->filter())->pluck('department_name', 'id');
        $regionNames = Region::query()->whereIn('id', $regions->keys()->filter())->pluck('region_name', 'id');
        $districtNames = District::query()->whereIn('id', $districts->keys()->filter())->pluck('district_name', 'id');

        $categories = $people->countBy(fn (Employee $employee) => StaffGrade::reportCategory($employee->category) ?: 'Unassigned');
        $grades = $people->whereNotNull('grade')->countBy('grade');
        $locations = $people->countBy('location_type');

        return [
            'departments' => $this->rows($departments, fn ($id) => [$departmentNames[$id] ?? 'Unassigned', $id ? ['department_id' => $id] : []]),
            'regions' => $this->rows($regions, fn ($id) => [$regionNames[$id] ?? 'Unassigned', $id ? ['region_id' => $id] : []]),
            'districts' => $this->rows($districts, fn ($id) => [$districtNames[$id] ?? 'Unassigned', []], 15),
            'locations' => collect(['HeadOffice' => 'Head Office', 'Region' => 'Regional Office', 'District' => 'District'])
                ->map(fn (string $label, string $type) => ['label' => $label, 'count' => (int) ($locations[$type] ?? 0), 'filter' => ['location_type' => $type]])
                ->values()->all(),
            'categories' => collect(StaffGrade::categories())
                ->map(fn (string $category) => ['label' => $category, 'count' => (int) ($categories[$category] ?? 0), 'filter' => ['category' => $category]])
                ->values()->all(),
            'grades' => collect(StaffGrade::cases())
                ->map(fn (StaffGrade $grade) => ['label' => $grade->value, 'count' => (int) ($grades[$grade->value] ?? 0), 'filter' => ['grade' => $grade->value]])
                ->values()->all(),
            'employment' => [
                ['label' => 'Permanent', 'count' => $people->count() - (int) ($categories[StaffGrade::CATEGORY_CONTRACT] ?? 0), 'filter' => []],
                ['label' => 'Contract', 'count' => (int) ($categories[StaffGrade::CATEGORY_CONTRACT] ?? 0), 'filter' => ['category' => StaffGrade::CATEGORY_CONTRACT]],
            ],
        ];
    }

    /** Active staff counted per value of $column, in the database. @return Collection<int|string, int> */
    protected function grouped(Builder $active, string $column): Collection
    {
        return (clone $active)->selectRaw("{$column} as bucket, count(*) as total")->groupBy($column)->pluck('total', 'bucket')->map(fn ($count) => (int) $count);
    }

    /**
     * @param  Collection<int|string, int>  $counts
     * @param  callable(int|string|null): array{0: string, 1: array<string, int>}  $describe  label and staff-list filter
     * @return list<array{label: string, count: int, filter: array<string, int>}>
     */
    protected function rows(Collection $counts, callable $describe, ?int $limit = null): array
    {
        $rows = $counts->map(function (int $count, int|string|null $id) use ($describe) {
            [$label, $filter] = $describe($id === '' ? null : $id);

            return ['label' => $label, 'count' => $count, 'filter' => $filter];
        })->sortBy([['count', 'desc'], ['label', 'asc']])->values();

        return ($limit ? $rows->take($limit) : $rows)->all();
    }

    // ------------------------------------------------------------------ (d) exit reasons

    /**
     * Why people left so far this year. The percentages are of every departure including transfers (what the chart shows);
     * "exits" is the count that feeds turnover, without them.
     */
    protected function exitReasons(Builder $staff, Carbon $asOf): array
    {
        $byReason = $this->departures($staff, $asOf->copy()->startOfYear(), $asOf)
            ->selectRaw('deactivation_reason as reason, count(*) as total')->groupBy('deactivation_reason')->pluck('total', 'reason');

        $departures = (int) $byReason->sum();
        $rows = collect(Employee::EXIT_REASON_GROUPS)->map(function (array $reasons, string $label) use ($byReason, $departures) {
            // A departure with no reason recorded (the empty key) is "Other".
            $count = (int) $byReason->only($reasons)->sum() + ($label === 'Other' ? (int) $byReason->get('', 0) : 0);

            return [
                'label' => $label,
                'count' => $count,
                'percent' => $departures > 0 ? round($count / $departures * 100, 1) : 0.0,
                'counts_as_exit' => ! in_array(Employee::EXIT_REASON_TRANSFER, $reasons, true),
            ];
        })->values();

        return [
            'year' => $asOf->year,
            'departures' => $departures,
            'exits' => (int) $rows->where('counts_as_exit', true)->sum('count'),
            'rows' => $rows->all(),
        ];
    }

    // ------------------------------------------------------------------ (e) grades and entitlement

    /**
     * Staff with no grade yet, and this year's entitlement by category: the gross days, the compulsory days taken off and
     * what is left to take. Uses the stored entitlement where one exists and the calculator otherwise.
     *
     * @param  Collection<int, Employee>  $people
     */
    protected function gradeSummary(Collection $people, Carbon $asOf): array
    {
        $year = $asOf->year;
        $stored = LeaveEntitlement::query()->where('year', $year)->whereIn('employee_id', $people->pluck('id'))->get()->keyBy('employee_id');
        $periodDays = CompulsoryLeavePeriod::effectiveDaysFor($year);

        $categories = collect(StaffGrade::categories())->mapWithKeys(fn (string $category) => [$category => ['label' => $category, 'staff' => 0, 'gross' => 0, 'compulsory' => 0, 'net' => 0]]);

        foreach ($people as $employee) {
            $figures = ($row = $stored->get($employee->id))
                ? ['gross' => $row->gross_days, 'compulsory' => $row->compulsory_days, 'net' => $row->net_days]
                : $this->calculator->breakdown($employee, $year, $periodDays);
            $category = StaffGrade::reportCategory($employee->category) ?: StaffGrade::CATEGORY_SENIOR;
            $bucket = $categories->has($category) ? $category : StaffGrade::CATEGORY_SENIOR;

            $categories[$bucket] = [
                'label' => $bucket,
                'staff' => $categories[$bucket]['staff'] + 1,
                'gross' => $categories[$bucket]['gross'] + $figures['gross'],
                'compulsory' => $categories[$bucket]['compulsory'] + $figures['compulsory'],
                'net' => $categories[$bucket]['net'] + $figures['net'],
            ];
        }

        $rows = $categories->values();

        return [
            'missing_grade' => $people->filter(fn (Employee $employee) => blank($employee->grade))->count(),
            'year' => $year,
            'compulsory_days' => $periodDays,
            'entitlement' => $rows->all(),
            'entitlement_total' => [
                'label' => 'All staff',
                'staff' => (int) $rows->sum('staff'),
                'gross' => (int) $rows->sum('gross'),
                'compulsory' => (int) $rows->sum('compulsory'),
                'net' => (int) $rows->sum('net'),
            ],
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{id: int, staff_id: string, name: string} */
    protected function person(Employee $employee): array
    {
        return ['id' => (int) $employee->id, 'staff_id' => (string) $employee->staff_id, 'name' => (string) $employee->full_name];
    }

    /** @param  Collection<int, array<string, mixed>>  $rows  @return array{count: int, items: list<array<string, mixed>>} */
    protected function listing(Collection $rows): array
    {
        return ['count' => $rows->count(), 'items' => $rows->take(self::LIST_LIMIT)->values()->all()];
    }

    /** The day in $year a date (a birth or hire date) falls on: 29 February is the 28th in a year without one. */
    protected function onDayInYear(CarbonInterface $date, int $year): Carbon
    {
        $first = Carbon::create($year, $date->month, 1);

        return $first->copy()->day(min($date->day, $first->daysInMonth));
    }
}
