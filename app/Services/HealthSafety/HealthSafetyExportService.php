<?php

namespace App\Services\HealthSafety;

use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\HsIncidentPerson;
use App\Models\HsPpeIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The tables behind every Health & Safety export (design 8.18 D). Each report is built from the SAME query object as the
 * screen it sits on, with the screen's current filters: RegisterQueries for the incident, action and PPE-issue lists,
 * EquipmentExpiryService for extinguishers and kits, PpeStockService / PpeComplianceService for stock and gaps and
 * ExpiryRegisterService for the register. So an export has exactly the rows its screen lists.
 *
 * The incident export carries NO free text: no description, no witness, no names of the people affected, no typed place or
 * type. Injury columns exist only for people holding view_injury_details, and a confidential reporter reads "Confidential"
 * for anyone not entitled (IncidentVisibility::reporterFor). A report above hs_export_max_rows is refused, never truncated.
 */
class HealthSafetyExportService
{
    /** report => [title, permissions (any one is needed, on top of export_reports)] */
    public const REPORTS = [
        'incidents' => ['title' => 'Incidents', 'permissions' => ['health_safety.view_incidents']],
        'actions' => ['title' => 'Safety actions', 'permissions' => ['health_safety.view_incidents']],
        'extinguishers' => ['title' => 'Fire extinguishers', 'permissions' => ['health_safety.view_equipment']],
        'kits' => ['title' => 'First aid kits', 'permissions' => ['health_safety.view_equipment']],
        'ppe-stock' => ['title' => 'PPE stock', 'permissions' => ['health_safety.view_equipment']],
        'ppe-issues' => ['title' => 'PPE issues', 'permissions' => ['health_safety.view_equipment']],
        'ppe-gaps' => ['title' => 'PPE gaps', 'permissions' => ['health_safety.view_equipment']],
        'expiry-register' => ['title' => 'Expiry register', 'permissions' => ['health_safety.view_equipment']],
        'expiry-register-pdf' => ['title' => 'Expiry register (site walk-round)', 'permissions' => ['health_safety.view_equipment']],
    ];

    /** A walk-round PDF is heavy to draw, so it has a lower ceiling than the Excel files. */
    public const PDF_MAX_ROWS = 1500;

    public function __construct(
        protected RegisterQueries $queries,
        protected EquipmentExpiryService $equipment,
        protected PpeStockService $stock,
        protected PpeComplianceService $compliance,
        protected ExpiryRegisterService $register,
        protected IncidentVisibility $visibility,
        protected EquipmentScope $scope,
    ) {}

    /** Whether the user may take this report at all: export_reports plus the permission of the screen it comes from. */
    public function allowed(User $user, string $report): bool
    {
        if (! isset(self::REPORTS[$report]) || ! $this->scope->can($user, 'health_safety.export_reports')) {
            return false;
        }

        return collect(self::REPORTS[$report]['permissions'])->contains(fn (string $slug) => $this->scope->can($user, $slug));
    }

    public static function maxRows(string $report): int
    {
        $cap = (int) HealthSafetySettings::value('hs_export_max_rows');

        return $report === 'expiry-register-pdf' ? min($cap, self::PDF_MAX_ROWS) : $cap;
    }

    /**
     * The report as a titled table. Returns null when it has more rows than the cap (so nothing is built); the caller says so.
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headings: list<string>, rows: list<list<string|int|float|null>>, count: int, notes: list<string>, groups?: array<string, list<array<string, mixed>>>}|array{over: int, cap: int}
     */
    public function build(User $actor, string $report, array $filters = []): array
    {
        $cap = self::maxRows($report);
        $title = self::REPORTS[$report]['title'];

        $table = match ($report) {
            'incidents' => $this->incidents($actor, $filters, $cap),
            'actions' => $this->actions($actor, $filters, $cap),
            'extinguishers' => $this->extinguishers($actor, $filters, $cap),
            'kits' => $this->kits($actor, $filters, $cap),
            'ppe-stock' => $this->ppeStock($actor, $filters),
            'ppe-issues' => $this->ppeIssues($actor, $filters, $cap),
            'ppe-gaps' => $this->ppeGaps($actor, $filters),
            default => $this->expiryRegister($actor, $filters),
        };

        if ($table['count'] > $cap) {
            return ['over' => $table['count'], 'cap' => $cap];
        }

        return ['title' => $title, 'notes' => $table['notes'] ?? []] + $table;
    }

    // ------------------------------------------------------------------ incidents and actions

    /** @param  array<string, mixed>  $filters */
    protected function incidents(User $actor, array $filters, int $cap): array
    {
        $query = $this->queries->incidents($actor, $filters);
        $count = (clone $query)->count();
        $injury = $this->visibility->can($actor, 'health_safety.view_injury_details');

        $headings = ['Reference', 'Type', 'Severity', 'Status', 'Occurred on', 'Reported on', 'Region', 'District', 'Where it happened', 'Site', 'First aid given', 'Urgent', 'Reporter', 'Acknowledged on', 'Closed on'];

        if ($injury) {
            array_push($headings, 'Injury types', 'Body parts', 'Highest treatment', 'Lost days');
        }

        $rows = [];

        if ($count <= $cap) {
            $query->with(['region', 'district', 'site', 'persons'])->orderByDesc('occurred_on')->orderByDesc('id')->get()->each(function (HsIncident $incident) use (&$rows, $actor, $injury) {
                $row = [
                    $incident->reference,
                    HsIncident::TYPES[$incident->incident_type] ?? $incident->incident_type,
                    $incident->severity ? (HsIncident::SEVERITIES[$incident->severity] ?? $incident->severity) : 'Not rated',
                    HsIncident::STATUSES[$incident->status] ?? $incident->status,
                    $incident->occurred_on?->format('Y-m-d'),
                    $incident->created_at?->format('Y-m-d H:i'),
                    $incident->region?->region_name,
                    $incident->district?->district_name,
                    HsIncident::CONTEXTS[$incident->context] ?? $incident->context,
                    $incident->site?->name,
                    HsIncident::FIRST_AID[$incident->first_aid] ?? $incident->first_aid,
                    $incident->is_urgent ? 'Yes' : 'No',
                    $this->visibility->reporterFor($actor, $incident)['name'],
                    $incident->acknowledged_at?->format('Y-m-d H:i'),
                    $incident->closed_at?->format('Y-m-d H:i'),
                ];

                if ($injury) {
                    // Per incident: only where the actor may see injury details for THAT incident.
                    array_push($row, ...($this->visibility->canSeeInjuryDetails($actor, $incident) ? $this->injurySummary($incident->persons) : ['', '', '', '']));
                }

                $rows[] = $row;
            });
        }

        return [
            'headings' => $headings,
            'rows' => $rows,
            'count' => $count,
            'notes' => [
                'No description, witness details or names of the people affected are included: open the incident (or its print copy) for those.',
                $injury ? 'Injury columns are included because you may see injury details; they stay empty for an incident you may not see them for.' : 'Injury details are not included: they need the injury-details permission.',
                'A confidential reporter shows as "Confidential", and an anonymous report as "Anonymous", unless you are entitled to know.',
            ],
        ];
    }

    /**
     * @param  Collection<int, HsIncidentPerson>  $persons
     * @return array{0: string, 1: string, 2: string, 3: int|string}
     */
    protected function injurySummary(Collection $persons): array
    {
        $order = array_flip(array_keys(HsIncidentPerson::TREATMENTS));
        $highest = $persons->pluck('treatment')->filter()->sortByDesc(fn ($treatment) => $order[$treatment] ?? -1)->first();

        return [
            $persons->pluck('injury_type')->filter()->unique()->implode(', '),
            $persons->pluck('body_part')->filter()->unique()->implode(', '),
            $highest ? (HsIncidentPerson::TREATMENTS[$highest] ?? $highest) : '',
            $persons->isEmpty() ? '' : (int) $persons->sum('lost_time_days'),
        ];
    }

    /** @param  array<string, mixed>  $filters */
    protected function actions(User $actor, array $filters, int $cap): array
    {
        $query = $this->queries->actions($actor, $filters);
        $count = (clone $query)->count();
        $rows = [];

        if ($count <= $cap) {
            $query->with(['incident:id,reference', 'assignee:id,full_name,staff_id'])->orderBy('due_on')->orderBy('id')->get()->each(function (HsIncidentAction $action) use (&$rows) {
                $rows[] = [
                    $action->incident?->reference,
                    $action->description,
                    $action->assignee ? $action->assignee->full_name.' ('.$action->assignee->staff_id.')' : '',
                    $action->due_on?->format('Y-m-d'),
                    HsIncidentAction::STATUSES[$action->status] ?? $action->status,
                    $action->status === HsIncidentAction::STATUS_OPEN && $action->due_on?->lt(today()) ? 'Yes' : 'No',
                    $action->completed_on?->format('Y-m-d'),
                    $action->verified_at?->format('Y-m-d'),
                ];
            });
        }

        return ['headings' => ['Incident', 'Action', 'Assigned to', 'Due on', 'Status', 'Overdue', 'Completed on', 'Verified on'], 'rows' => $rows, 'count' => $count];
    }

    // ------------------------------------------------------------------ equipment

    /** @param  array<string, mixed>  $filters */
    protected function extinguishers(User $actor, array $filters, int $cap): array
    {
        $query = $this->equipment->extinguishers($actor, $filters);
        $count = (clone $query)->count();
        $rows = [];

        if ($count <= $cap) {
            $query->with(['site', 'vehicle', 'region', 'district', 'responsible'])->get()->each(function (HsFireExtinguisher $unit) use (&$rows) {
                $state = $unit->state();

                $rows[] = [
                    $unit->asset_code,
                    $unit->typeLabel(),
                    $unit->capacity,
                    $unit->locationLabel(),
                    $unit->region?->region_name,
                    $unit->district?->district_name,
                    $unit->expiry_date?->format('Y-m-d'),
                    $unit->last_serviced_on?->format('Y-m-d'),
                    $unit->next_service_due?->format('Y-m-d'),
                    $unit->next_hydro_test_due?->format('Y-m-d'),
                    $unit->last_checked_on?->format('Y-m-d'),
                    $unit->last_check_result ? ucfirst($unit->last_check_result) : '',
                    $state ? HsFireExtinguisher::stateLabel($state) : 'Not evaluated',
                    HsFireExtinguisher::STATUSES[$unit->status] ?? $unit->status,
                    $unit->responsible?->full_name,
                    $unit->label_printed_at?->format('Y-m-d'),
                ];
            });
        }

        return [
            'headings' => ['Asset code', 'Type', 'Capacity', 'Where', 'Region', 'District', 'Expires', 'Last serviced', 'Next service due', 'Next hydrostatic test', 'Last check', 'Last check result', 'State', 'Status', 'Responsible', 'Label printed'],
            'rows' => $rows,
            'count' => $count,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    protected function kits(User $actor, array $filters, int $cap): array
    {
        $query = $this->equipment->kits($actor, $filters);
        $count = (clone $query)->count();
        $rows = [];

        if ($count <= $cap) {
            $query->with(['site', 'vehicle', 'region', 'district', 'responsible', 'items'])->get()->each(function (HsFirstAidKit $kit) use (&$rows) {
                $state = $kit->state();
                $expiries = $kit->items->pluck('expiry_date')->filter();

                $rows[] = [
                    $kit->asset_code,
                    $kit->typeLabel(),
                    $kit->locationLabel(),
                    $kit->region?->region_name,
                    $kit->district?->district_name,
                    $state ? HsFirstAidKit::stateLabel($state) : 'Not evaluated',
                    HsFirstAidKit::STATUSES[$kit->status] ?? $kit->status,
                    $kit->items->count(),
                    $kit->items->filter(fn ($item) => $item->isShort())->count(),
                    $kit->items->filter(fn ($item) => $item->isExpired())->count(),
                    $kit->items->filter(fn ($item) => $item->isExpiring())->count(),
                    $expiries->min()?->format('Y-m-d'),
                    $kit->last_checked_on?->format('Y-m-d'),
                    $kit->last_check_result ? ucfirst($kit->last_check_result) : '',
                    $kit->responsible?->full_name,
                    $kit->label_printed_at?->format('Y-m-d'),
                ];
            });
        }

        return [
            'headings' => ['Asset code', 'Type', 'Where', 'Region', 'District', 'State', 'Status', 'Items', 'Items short', 'Items expired', 'Items expiring', 'Earliest item expiry', 'Last check', 'Last check result', 'Responsible', 'Label printed'],
            'rows' => $rows,
            'count' => $count,
        ];
    }

    // ------------------------------------------------------------------ PPE

    /** @param  array<string, mixed>  $filters */
    protected function ppeStock(User $actor, array $filters): array
    {
        $matrix = $this->stock->matrix(
            $actor,
            filled($filters['store_id'] ?? null) ? (int) $filters['store_id'] : null,
            filled($filters['type_id'] ?? null) ? (int) $filters['type_id'] : null
        )->when(! empty($filters['low']), fn (Collection $rows) => $rows->filter(fn (array $row) => $row['low']));

        $rows = [];

        foreach ($matrix as $row) {
            foreach ($row['sizes'] as $size => $balance) {
                $rows[] = [$row['store']->name, $row['type']->name, $size === '' ? 'No sizes' : $size, $balance, $row['total'], $row['level'], $row['low'] ? 'Yes' : 'No'];
            }
        }

        return ['headings' => ['Store', 'PPE type', 'Size', 'Balance', 'Total of the type', 'Reorder level', 'Low'], 'rows' => $rows, 'count' => count($rows)];
    }

    /** @param  array<string, mixed>  $filters */
    protected function ppeIssues(User $actor, array $filters, int $cap): array
    {
        $query = $this->queries->ppeIssues($actor, $filters);
        $count = (clone $query)->count();
        $rows = [];

        if ($count <= $cap) {
            $query->with(['employee:id,staff_id,full_name,job_title_id', 'employee.jobTitle:id,job_title_name', 'type:id,name'])
                ->orderByRaw('replace_due_on is null')->orderBy('replace_due_on')->orderByDesc('id')
                ->get()->each(function (HsPpeIssue $issue) use (&$rows) {
                    $rows[] = [
                        $issue->employee?->staff_id,
                        $issue->employee?->full_name,
                        $issue->employee?->jobTitle?->job_title_name,
                        $issue->type?->name,
                        $issue->size,
                        $issue->quantity,
                        $issue->issued_on?->format('Y-m-d'),
                        $issue->replace_due_on?->format('Y-m-d'),
                        $issue->state() ? ucfirst(str_replace('_', ' ', $issue->state())) : '',
                        HsPpeIssue::STATUSES[$issue->status] ?? $issue->status,
                        $issue->closed_on?->format('Y-m-d'),
                        $issue->is_historic ? 'Yes' : 'No',
                        $issue->acknowledged_at ? 'Yes' : 'No',
                    ];
                });
        }

        return ['headings' => ['Staff ID', 'Name', 'Job title', 'PPE type', 'Size', 'Quantity', 'Issued on', 'Replace by', 'State', 'Status', 'Closed on', 'Already held', 'Receipt confirmed'], 'rows' => $rows, 'count' => $count];
    }

    /** @param  array<string, mixed>  $filters */
    protected function ppeGaps(User $actor, array $filters): array
    {
        $rows = $this->compliance->rows($actor, $filters)->map(fn (array $row) => [
            $row['staff_id'],
            $row['name'],
            $row['job_title'],
            $row['district'],
            $row['type'],
            $row['entitled'],
            $row['held'],
            $row['in_date'],
            $row['next_due']?->format('Y-m-d'),
            PpeComplianceService::STATES[$row['state']] ?? $row['state'],
        ])->all();

        return ['headings' => ['Staff ID', 'Name', 'Job title', 'District', 'PPE type', 'Entitled', 'Held', 'In date', 'Next due', 'State'], 'rows' => $rows, 'count' => count($rows)];
    }

    // ------------------------------------------------------------------ the register

    /** @param  array<string, mixed>  $filters */
    protected function expiryRegister(User $actor, array $filters): array
    {
        $register = $this->register->rows($actor, $filters);

        $rows = $register->map(fn (array $row) => [
            $row['what'],
            $row['type_label'],
            $row['site'],
            $row['where'],
            $row['due_on']->format('Y-m-d'),
            $row['days'],
            ExpiryRegisterService::BUCKETS[$row['bucket']],
            $row['responsible'],
        ])->all();

        return [
            'headings' => ['What', 'Type', 'Site', 'Where', 'Due on', 'Days to due', 'Bucket', 'Responsible'],
            'rows' => $rows,
            'count' => count($rows),
            'register' => $register,
        ];
    }
}
