<?php

namespace App\Services\HealthSafety;

use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\HsPpeIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filtered queries behind three screens (the incident register, the actions list and the PPE issues list), built in
 * ONE place so the screen and its export read the very same rows. (Equipment lists are EquipmentExpiryService, the PPE
 * gaps are PpeComplianceService, the expiry register is ExpiryRegisterService.) Each takes the signed-in user and the
 * screen's filters as an array and returns an unpaginated, unordered query.
 */
class RegisterQueries
{
    public function __construct(
        protected IncidentVisibility $visibility,
        protected EquipmentScope $equipment,
        protected HealthSafetySettings $settings,
    ) {}

    /**
     * The incident register after the filters. Filters: status (a status, "open" or "in_progress"), type, severity ("unrated"
     * for none), district_id, from, to (occurred on), search, overdue ("ack": not acknowledged in time; "investigation": past
     * its investigation due date).
     *
     * @param  array<string, mixed>  $filters
     */
    public function incidents(User $actor, array $filters = []): Builder
    {
        $status = (string) ($filters['status'] ?? '');
        $severity = (string) ($filters['severity'] ?? '');
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        $search = trim((string) ($filters['search'] ?? ''));

        return $this->visibility->scopeFor(HsIncident::query(), $actor)
            ->when($status !== '', fn (Builder $query) => match ($status) {
                'open' => $query->whereIn('status', HsIncident::OPEN_STATUSES),
                'in_progress' => $query->whereIn('status', [HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING]),
                default => $query->where('status', $status),
            })
            ->when(filled($filters['type'] ?? null), fn (Builder $query) => $query->where('incident_type', $filters['type']))
            ->when($severity !== '', fn (Builder $query) => $severity === 'unrated'
                ? $query->whereNull('severity')
                : $query->where('severity', $severity))
            ->when(filled($filters['district_id'] ?? null), fn (Builder $query) => $query->where('district_id', (int) $filters['district_id']))
            ->when($from !== '' && strtotime($from), fn (Builder $query) => $query->whereDate('occurred_on', '>=', $from))
            ->when($to !== '' && strtotime($to), fn (Builder $query) => $query->whereDate('occurred_on', '<=', $to))
            ->when(($filters['overdue'] ?? '') === 'ack', fn (Builder $query) => $query
                ->where('status', HsIncident::STATUS_REPORTED)
                ->where('hs_incidents.created_at', '<=', now()->subHours((int) $this->settings->get('hs_ack_hours'))))
            ->when(($filters['overdue'] ?? '') === 'investigation', fn (Builder $query) => $query
                ->whereIn('status', [HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING])
                ->whereNotNull('acknowledged_at')
                ->where('acknowledged_at', '<', today()->subDays((int) $this->settings->get('hs_investigation_due_days'))))
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = '%'.$search.'%';

                $query->where(fn (Builder $match) => $match
                    ->where('reference', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('site_name_raw', 'like', $term)
                    ->orWhere('location_detail', 'like', $term));
            });
    }

    /** Corrective and preventive actions the actor may see: those on incidents in their part of the register, and any assigned to them. */
    public function actionsVisibleTo(User $actor): Builder
    {
        $employeeId = $this->visibility->employeeOf($actor)?->id;
        $entitled = $this->visibility->scopeEntitled(HsIncident::query(), $actor)->select('hs_incidents.id');

        return HsIncidentAction::query()->where(fn (Builder $query) => $query
            ->whereIn('incident_id', $entitled)
            ->when($employeeId, fn (Builder $own) => $own->orWhere('assigned_to_employee_id', $employeeId)));
    }

    /**
     * Filters: filter ("open", "overdue", "done" = awaiting verification, "verified"), mine (true: only those assigned to the actor).
     *
     * @param  array<string, mixed>  $filters
     */
    public function actions(User $actor, array $filters = []): Builder
    {
        $employeeId = $this->visibility->employeeOf($actor)?->id;

        return $this->actionsVisibleTo($actor)
            ->when(! empty($filters['mine']), fn (Builder $query) => $query->where('assigned_to_employee_id', $employeeId ?? 0))
            ->when(filled($filters['filter'] ?? null), fn (Builder $query) => match ($filters['filter']) {
                'open' => $query->where('status', HsIncidentAction::STATUS_OPEN),
                'overdue' => $query->where('status', HsIncidentAction::STATUS_OPEN)->whereDate('due_on', '<', today()),
                'done' => $query->where('status', HsIncidentAction::STATUS_DONE),
                'verified' => $query->where('status', HsIncidentAction::STATUS_VERIFIED),
                default => $query,
            });
    }

    /**
     * The PPE issues list. Filters: status ("open", "all" or a closing status), state (overdue, replacement_due, ok), type_id, search
     * (the employee's name or staff ID prefix).
     *
     * @param  array<string, mixed>  $filters
     */
    public function ppeIssues(User $actor, array $filters = []): Builder
    {
        $status = (string) ($filters['status'] ?? 'open');
        $state = (string) ($filters['state'] ?? '');
        $search = trim((string) ($filters['search'] ?? ''));

        return $this->equipment->ppeIssues($actor)
            ->when($status === 'open', fn (Builder $query) => $query->open())
            ->when(! in_array($status, ['open', 'all', ''], true), fn (Builder $query) => $query->where('status', $status))
            ->when(in_array($state, [HsPpeIssue::STATE_OVERDUE, HsPpeIssue::STATE_REPLACEMENT_DUE, HsPpeIssue::STATE_OK], true), fn (Builder $query) => $query->withState($state))
            ->when(filled($filters['type_id'] ?? null), fn (Builder $query) => $query->where('ppe_type_id', (int) $filters['type_id']))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('employee', fn (Builder $employee) => $employee->where('full_name', 'like', '%'.$search.'%')->orWhere('staff_id', 'like', $search.'%')));
    }
}
