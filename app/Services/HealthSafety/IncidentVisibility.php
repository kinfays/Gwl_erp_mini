<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place that decides which incidents an actor may see and what they may see of each. The register, the detail
 * screen, the print copy / PDF, the notifications and (later) the exports all call it, so they cannot disagree about
 * who the reporter is or whether injury details are shown (design section 2.4).
 *
 * Three tiers of viewer:
 *  - the reporter (and whoever recorded it for them): status, severity, the timeline without notes, and the closure note
 *    once closed. Never the findings.
 *  - an entitled viewer (holds health_safety.view_incidents and the incident is in their scope): the whole file apart
 *    from injury details and, for a confidential report, the reporter.
 *  - injury details (persons affected, first-aider, lost time) need health_safety.view_injury_details on top.
 *
 * Scope: super_admin and hs_manager see every region; so does anyone at Head Office. hs_officer and
 * regional_chief_manager (and any other role holding view_incidents) see their own region; district_manager sees their
 * own district. Head Office is a district whose staff share the Head Office district's region_id, so it is recognised by
 * location_type, never by region alone.
 */
class IncidentVisibility
{
    /** Who may see the reporter of a confidential report (besides the reporter themselves). */
    public const CONFIDENTIAL_REPORTER_ROLES = ['hs_officer', 'hs_manager', 'super_admin'];

    public const SCOPE_ALL = ActorScope::ALL;
    public const SCOPE_REGION = ActorScope::REGION;
    public const SCOPE_DISTRICT = ActorScope::DISTRICT;
    public const SCOPE_NONE = ActorScope::NONE;

    public function can(User $user, string $slug): bool
    {
        return $user->hasRoles('super_admin') || $user->hasPermission($slug);
    }

    public function employeeOf(User $user): ?Employee
    {
        return $user->employee ?? $user->employeeByStaffId;
    }

    /**
     * The part of the register the user's role entitles them to, apart from reports they made themselves.
     *
     * @return array{level: string, id: int|null}
     */
    public function scopeOf(User $user): array
    {
        return (new ActorScope)->resolve($user, 'health_safety.view_incidents');
    }

    /** Whether the user sees every region (for pickers and filters). */
    public function seesAllRegions(User $user): bool
    {
        return $this->scopeOf($user)['level'] === self::SCOPE_ALL
            || $user->hasRoles('super_admin')
            || $this->employeeOf($user)?->location_type === 'HeadOffice';
    }

    public function isOwn(User $user, HsIncident $incident): bool
    {
        return ($incident->reported_by_user_id !== null && (int) $incident->reported_by_user_id === (int) $user->id)
            || ($incident->recorded_by_user_id !== null && (int) $incident->recorded_by_user_id === (int) $user->id);
    }

    /** The incident is inside the part of the register the user's role covers (not counting reports they made). */
    public function inScope(User $user, HsIncident $incident): bool
    {
        $scope = $this->scopeOf($user);

        return match ($scope['level']) {
            self::SCOPE_ALL => true,
            self::SCOPE_REGION => (int) $incident->region_id === $scope['id'],
            self::SCOPE_DISTRICT => $incident->district_id !== null && (int) $incident->district_id === $scope['id'],
            default => false,
        };
    }

    /** Entitled to the whole file (findings, internal notes, actions), as opposed to seeing only their own report. */
    public function isEntitled(User $user, HsIncident $incident): bool
    {
        return $this->inScope($user, $incident);
    }

    public function canView(User $user, HsIncident $incident): bool
    {
        return $this->isOwn($user, $incident) || $this->inScope($user, $incident);
    }

    /** Triage, investigate, act, close, cancel, reopen: the permission and the incident in scope. */
    public function canManage(User $user, HsIncident $incident): bool
    {
        return $this->can($user, 'health_safety.manage_incidents') && $this->inScope($user, $incident);
    }

    public function canApproveClosure(User $user, HsIncident $incident): bool
    {
        return $this->can($user, 'health_safety.approve_closure') && $this->inScope($user, $incident);
    }

    public function canSeeInjuryDetails(User $user, HsIncident $incident): bool
    {
        return $this->can($user, 'health_safety.view_injury_details') && $this->canView($user, $incident);
    }

    /** Findings, root cause, actions and the notes in the timeline. */
    public function canSeeInternal(User $user, HsIncident $incident): bool
    {
        return $this->isEntitled($user, $incident);
    }

    /** The closure note is the reporter's feedback: shown to anyone who can see the incident, once it is closed. */
    public function canSeeClosureNote(User $user, HsIncident $incident): bool
    {
        return $this->canView($user, $incident)
            && ($incident->status === HsIncident::STATUS_CLOSED || $this->canSeeInternal($user, $incident));
    }

    /** Every incident the user may see: their own reports plus their role's part of the register. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        $scope = $this->scopeOf($user);

        return $query->where(function (Builder $visible) use ($user, $scope, $query) {
            $visible->where($query->qualifyColumn('reported_by_user_id'), $user->id)
                ->orWhere($query->qualifyColumn('recorded_by_user_id'), $user->id);

            match ($scope['level']) {
                self::SCOPE_ALL => $visible->orWhereRaw('1 = 1'),
                self::SCOPE_REGION => $visible->orWhere($query->qualifyColumn('region_id'), $scope['id']),
                self::SCOPE_DISTRICT => $visible->orWhere($query->qualifyColumn('district_id'), $scope['id']),
                default => null,
            };
        });
    }

    /**
     * Only the part of the register the user's role entitles them to: no credit for reports they made themselves. This
     * is what the actions list and the overview's action counts use, since a reporter does not see an incident's actions.
     */
    public function scopeEntitled(Builder $query, User $user): Builder
    {
        $scope = $this->scopeOf($user);

        return match ($scope['level']) {
            self::SCOPE_ALL => $query,
            self::SCOPE_REGION => $query->where($query->qualifyColumn('region_id'), $scope['id']),
            self::SCOPE_DISTRICT => $query->where($query->qualifyColumn('district_id'), $scope['id']),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Incidents reported by (or recorded for) the user. */
    public function scopeOwn(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $own) => $own
            ->where($query->qualifyColumn('reported_by_user_id'), $user->id)
            ->orWhere($query->qualifyColumn('recorded_by_user_id'), $user->id));
    }

    /**
     * Who the viewer is told reported it. A confidential report hides the reporter from everyone but the reporter,
     * the person who recorded it, hs_officer, hs_manager and super_admin.
     *
     * @return array{name: string, recorded_by: string|null, hidden: bool}
     */
    public function reporterFor(User $viewer, HsIncident $incident): array
    {
        // A fully anonymous report has no reporter on record at all: there is no one to reveal, to anybody.
        if ($incident->is_anonymous) {
            return ['name' => 'Anonymous', 'recorded_by' => null, 'hidden' => true];
        }

        $reveal = ! $incident->is_confidential
            || $viewer->hasRoles(...self::CONFIDENTIAL_REPORTER_ROLES)
            || $this->isOwn($viewer, $incident);

        if (! $reveal) {
            return ['name' => 'Confidential', 'recorded_by' => null, 'hidden' => true];
        }

        $incident->loadMissing(['reporter', 'reporterEmployee', 'recorder']);

        return [
            'name' => $incident->reporter?->full_name
                ?? $incident->reporterEmployee?->full_name
                ?? $incident->reporter_name_raw
                ?? 'Unknown',
            'recorded_by' => $incident->recorded_by_user_id ? ($incident->recorder?->full_name ?? 'Unknown') : null,
            'hidden' => false,
        ];
    }

    /**
     * The name to show for a user who acted on the incident (its owner, whoever closed or approved it, a timeline entry).
     * If that user is the confidential reporter, or whoever recorded the report for them, they show as "Reporter" to
     * anyone not allowed to know who reported it: an officer who files a report in confidence and then owns or closes it
     * would otherwise be named by the very screens that hide the reporter.
     */
    public function nameFor(User $viewer, HsIncident $incident, ?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $reporterIds = array_filter([$incident->reported_by_user_id, $incident->recorded_by_user_id]);

        if (in_array($userId, $reporterIds, true) && $this->reporterFor($viewer, $incident)['hidden']) {
            return 'Reporter';
        }

        return User::query()->whereKey($userId)->value('full_name') ?? 'Unknown';
    }

    /**
     * Who closed (and approved the closure of) the incident, and when. Only those entitled to the whole file are told who:
     * the reporter just sees the closing date in the outcome. If the person who closed it is also the confidential
     * reporter (an officer closing their own report), they show as "Reporter" to anyone not allowed to know.
     *
     * @return array{closed_by: string|null, closed_at: \Illuminate\Support\Carbon|null, approved_by: string|null, approved_at: \Illuminate\Support\Carbon|null}|null
     */
    public function closureFor(User $viewer, HsIncident $incident): ?array
    {
        if (! $this->canSeeInternal($viewer, $incident) || $incident->closed_by === null) {
            return null;
        }

        $name = fn (?int $userId): ?string => $this->nameFor($viewer, $incident, $userId);

        return [
            'closed_by' => $name($incident->closed_by),
            'closed_at' => $incident->closed_at,
            'approved_by' => $incident->approved_by !== null && (int) $incident->approved_by !== (int) $incident->closed_by ? $name($incident->approved_by) : null,
            'approved_at' => $incident->approved_by !== null && (int) $incident->approved_by !== (int) $incident->closed_by ? $incident->approved_at : null,
        ];
    }

    /**
     * The timeline the viewer may see. An entitled viewer gets every row with its note and who made it; the reporter
     * gets only the moves between statuses, with no notes (a cancellation reason or a reopen reason is internal).
     *
     * @return Collection<int, array{at: \Illuminate\Support\Carbon|null, from: string|null, to: string, note: string|null, by: string|null}>
     */
    public function timeline(User $viewer, HsIncident $incident): Collection
    {
        $internal = $this->canSeeInternal($viewer, $incident);

        return $incident->statusLogs()
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->when(! $internal, fn (Collection $logs) => $logs->filter(fn ($log) => $log->changedStatus()))
            ->map(fn ($log) => [
                'at' => $log->created_at,
                'from' => $log->from_status,
                'to' => $log->to_status,
                'note' => $internal ? $log->note : null,
                // Whoever filed a confidential report stays "Reporter" to those who may not know who they are.
                'by' => $internal ? $this->nameFor($viewer, $incident, $log->user_id) : null,
            ])
            ->values();
    }

    /**
     * Everything the viewer may see of the incident, as plain data. The print copy and the PDF are built from this and
     * nothing else, so they never carry what the screen hides.
     *
     * @return array<string, mixed>
     */
    public function present(User $viewer, HsIncident $incident): array
    {
        $incident->loadMissing(['region', 'district', 'department', 'site', 'attachments']);

        $internal = $this->canSeeInternal($viewer, $incident);
        $injury = $this->canSeeInjuryDetails($viewer, $incident);

        $data = [
            'reference' => $incident->reference,
            'status' => $incident->status,
            'status_label' => HsIncident::STATUSES[$incident->status] ?? $incident->status,
            'type_label' => $incident->typeLabel(),
            'severity' => $incident->severity,
            'severity_label' => $incident->severity ? HsIncident::SEVERITIES[$incident->severity] : null,
            'context_label' => HsIncident::CONTEXTS[$incident->context] ?? $incident->context,
            'region' => $incident->region?->region_name,
            'place' => $incident->placeLabel(),
            'occurred_on' => $incident->occurred_on,
            'occurred_time' => $incident->occurred_time ? substr((string) $incident->occurred_time, 0, 5) : null,
            'reported_at' => $incident->created_at,
            'description' => $incident->description,
            'first_aid_label' => HsIncident::FIRST_AID[$incident->first_aid] ?? $incident->first_aid,
            'witness' => $incident->no_witness
                ? null
                : array_filter(['name' => $incident->witness_name, 'contact' => $incident->witness_contact]),
            'no_witness' => (bool) $incident->no_witness,
            'is_confidential' => (bool) $incident->is_confidential,
            'is_anonymous' => (bool) $incident->is_anonymous,
            'is_urgent' => (bool) $incident->is_urgent,
            'reporter' => $this->reporterFor($viewer, $incident),
            'photos' => $incident->attachments->map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name])->all(),
            'closure_note' => $this->canSeeClosureNote($viewer, $incident) ? $incident->closure_note : null,
            'closed_at' => $incident->status === HsIncident::STATUS_CLOSED ? $incident->closed_at : null,
            'closure' => $incident->status === HsIncident::STATUS_CLOSED ? $this->closureFor($viewer, $incident) : null,
            'internal' => $internal,
            'timeline' => $this->timeline($viewer, $incident),
            'root_cause' => null,
            'findings' => null,
            'actions' => null,
            'persons' => null,
        ];

        if ($internal) {
            $data['root_cause'] = $incident->root_cause_category ? HsIncident::ROOT_CAUSES[$incident->root_cause_category] : null;
            $data['findings'] = $incident->findings;
            $data['actions'] = $incident->actions()->with('assignee')->orderBy('due_on')->get()->map(fn ($action) => [
                'description' => $action->description,
                'assignee' => $action->assignee?->full_name,
                'due_on' => $action->due_on,
                'status' => $action->status,
            ])->all();
        }

        if ($injury) {
            $data['persons'] = $incident->persons()->with('employee')->get()->map(fn ($person) => [
                'name' => $person->displayName(),
                'type' => \App\Models\HsIncidentPerson::PERSON_TYPES[$person->person_type] ?? $person->person_type,
                'injury_type' => $person->injury_type,
                'body_part' => $person->body_part,
                'treatment' => \App\Models\HsIncidentPerson::TREATMENTS[$person->treatment] ?? $person->treatment,
                'first_aider' => $person->first_aider_name,
                'lost_time_days' => $person->lost_time_days,
                'returned_to_work_on' => $person->returned_to_work_on,
            ])->all();
        }

        return $data;
    }
}
