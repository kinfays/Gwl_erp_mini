<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsPpeEntitlement;
use App\Models\HsPpeIssue;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Who is missing PPE they are entitled to. THE evaluator: the gaps screen, My PPE and the overview counts all call it, and
 * there is deliberately no SQL twin of it, so they cannot disagree.
 *
 * It looks at every ACTIVE employee in the actor's part of the register whose job title has entitlement rows, and for each
 * entitled PPE type compares what the entitlement says with what the person holds in OPEN issues. "In date" means an open
 * issue with no replacement date, or one that has not passed. State (design 8.9), first match wins:
 *
 *   missing           nothing issued at all
 *   overdue           something issued, none of it in date
 *   short             in-date quantity below the entitled quantity
 *   replacement_due   the earliest in-date replacement date is within the warning window (hs_expiry_warning_days)
 *   ok
 *
 * A stale open row beside in-date items does not make someone "overdue" (they still hold something in date); it shows as
 * an overdue ISSUE on the issues list, where it can be closed. Job titles with no entitlement rows are not evaluated; they are
 * counted so the screen can say so.
 */
class PpeComplianceService
{
    public const MISSING = 'missing';
    public const OVERDUE = 'overdue';
    public const SHORT = 'short';
    public const REPLACEMENT_DUE = 'replacement_due';
    public const OK = 'ok';

    public const STATES = [
        self::MISSING => 'Missing',
        self::OVERDUE => 'Overdue',
        self::SHORT => 'Short',
        self::REPLACEMENT_DUE => 'Replacement due',
        self::OK => 'OK',
    ];

    /** The states that count as a gap: someone does not hold what they should. Replacement due is a warning, not a gap. */
    public const GAP_STATES = [self::MISSING, self::OVERDUE, self::SHORT];

    public function __construct(protected EquipmentScope $scope) {}

    /**
     * One entitlement against what is held. Pure: no queries.
     *
     * @param  Collection<int, HsPpeIssue>  $open  the person's open issues of the type
     * @return array{state: string, entitled: int, held: int, in_date: int, next_due: \Illuminate\Support\Carbon|null}
     */
    public function evaluate(int $entitled, Collection $open): array
    {
        $window = today()->addDays((int) HealthSafetySettings::value('hs_expiry_warning_days'));

        $held = (int) $open->sum('quantity');
        $current = $open->filter(fn ($issue) => $issue->replace_due_on === null || $issue->replace_due_on->copy()->startOfDay()->gte(today()));
        $inDate = (int) $current->sum('quantity');
        $nextDue = $open->pluck('replace_due_on')->filter()->sort()->first();
        $nextInDate = $current->pluck('replace_due_on')->filter()->sort()->first();

        $state = match (true) {
            $held === 0 => self::MISSING,
            $inDate === 0 => self::OVERDUE,
            $inDate < $entitled => self::SHORT,
            $nextInDate !== null && $nextInDate->copy()->startOfDay()->lte($window) => self::REPLACEMENT_DUE,
            default => self::OK,
        };

        return ['state' => $state, 'entitled' => $entitled, 'held' => $held, 'in_date' => $inDate, 'next_due' => $nextDue];
    }

    /**
     * Every employee x entitled type for the actor, worst first.
     *
     * @param  array{district_id?: int|string|null, job_title_id?: int|string|null, state?: string|null, type_id?: int|string|null, search?: string|null}  $filters  state may also be "gap" (missing, overdue or short)
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(User $actor, array $filters = []): Collection
    {
        $entitlements = $this->entitlements();

        if ($entitlements->isEmpty()) {
            return collect();
        }

        $employees = $this->scope->employees($actor)
            ->whereIn('job_title_id', $entitlements->keys())
            ->when(filled($filters['district_id'] ?? null), fn ($query) => $query->where('district_id', (int) $filters['district_id']))
            ->when(filled($filters['job_title_id'] ?? null), fn ($query) => $query->where('job_title_id', (int) $filters['job_title_id']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $term = trim((string) $filters['search']);

                $query->where(fn ($match) => $match->where('full_name', 'like', '%'.$term.'%')->orWhere('staff_id', 'like', $term.'%'));
            })
            ->with(['jobTitle:id,job_title_name', 'district:id,district_name'])
            ->orderBy('full_name')
            ->get(['id', 'staff_id', 'full_name', 'job_title_id', 'district_id', 'region_id']);

        return $this->build($employees, $entitlements, $filters);
    }

    /** One employee's own rows (My PPE): the same evaluation, outside any actor's scope. */
    public function rowsForEmployee(Employee $employee): Collection
    {
        $entitlements = $this->entitlements()->only([$employee->job_title_id]);

        if (! $employee->is_active || $entitlements->isEmpty()) {
            return collect();
        }

        $employee->loadMissing(['jobTitle:id,job_title_name', 'district:id,district_name']);

        return $this->build(collect([$employee]), $entitlements, []);
    }

    /**
     * The figures the overview shows, and the same ones the gaps screen lists.
     *
     * @return array{rows: int, gap_rows: int, gap_employees: int, by_state: array<string, int>, titles_without_entitlements: int}
     */
    public function summary(User $actor): array
    {
        $rows = $this->rows($actor);
        $byState = array_fill_keys(array_keys(self::STATES), 0);

        foreach ($rows as $row) {
            $byState[$row['state']]++;
        }

        $gaps = $rows->filter(fn (array $row) => in_array($row['state'], self::GAP_STATES, true));

        return [
            'rows' => $rows->count(),
            'gap_rows' => $gaps->count(),
            'gap_employees' => $gaps->pluck('employee_id')->unique()->count(),
            'by_state' => $byState,
            'titles_without_entitlements' => $this->titlesWithoutEntitlements($actor),
        ];
    }

    /** Job titles that have active staff in the actor's scope but no entitlement rows: not evaluated, so counted. */
    public function titlesWithoutEntitlements(User $actor): int
    {
        return $this->scope->employees($actor)
            ->whereNotIn('job_title_id', $this->entitlements()->keys())
            ->distinct()
            ->count('job_title_id');
    }

    public function paginate(Collection $rows, int $perPage, int $page, string $path): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $path]
        );
    }

    // ------------------------------------------------------------------ internals

    /** Entitlements of active PPE types, keyed by job title. @return Collection<int, Collection<int, HsPpeEntitlement>> */
    protected function entitlements(): Collection
    {
        return HsPpeEntitlement::query()
            ->with('type:id,name,is_active')
            ->where('quantity', '>=', 1)
            ->whereHas('type', fn ($query) => $query->where('is_active', true))
            ->get()
            ->groupBy('job_title_id')
            // A plain collection: on an Eloquent one, only() and keys() would treat the job-title ids as model keys.
            ->toBase();
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Collection<int, HsPpeEntitlement>>  $entitlements
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function build(Collection $employees, Collection $entitlements, array $filters): Collection
    {
        $issues = collect();

        foreach ($employees->pluck('id')->chunk(500) as $ids) {
            $issues = $issues->concat(
                HsPpeIssue::query()->open()->whereIn('employee_id', $ids)->get(['id', 'employee_id', 'ppe_type_id', 'quantity', 'replace_due_on'])
            );
        }

        $held = $issues->groupBy(fn ($issue) => $issue->employee_id.'|'.$issue->ppe_type_id);
        $wantedState = $filters['state'] ?? null;
        $wantedType = filled($filters['type_id'] ?? null) ? (int) $filters['type_id'] : null;
        $rows = collect();

        foreach ($employees as $employee) {
            foreach ($entitlements[$employee->job_title_id] ?? [] as $entitlement) {
                if ($wantedType !== null && (int) $entitlement->ppe_type_id !== $wantedType) {
                    continue;
                }

                $evaluation = $this->evaluate($entitlement->quantity, $held[$employee->id.'|'.$entitlement->ppe_type_id] ?? collect());

                if ($wantedState === 'gap' ? ! in_array($evaluation['state'], self::GAP_STATES, true) : (filled($wantedState) && $evaluation['state'] !== $wantedState)) {
                    continue;
                }

                $rows->push([
                    'employee_id' => $employee->id,
                    'staff_id' => $employee->staff_id,
                    'name' => $employee->full_name,
                    'job_title_id' => $employee->job_title_id,
                    'job_title' => $employee->jobTitle?->job_title_name,
                    'district' => $employee->district?->district_name,
                    'ppe_type_id' => $entitlement->ppe_type_id,
                    'type' => $entitlement->type->name,
                    ...$evaluation,
                ]);
            }
        }

        $order = array_flip(array_keys(self::STATES));

        return $rows->sortBy([
            fn (array $a, array $b) => $order[$a['state']] <=> $order[$b['state']],
            fn (array $a, array $b) => strcmp($a['name'], $b['name']),
            fn (array $a, array $b) => strcmp($a['type'], $b['type']),
        ])->values();
    }
}
