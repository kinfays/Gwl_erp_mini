<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\HsIncidentPerson;
use App\Models\Permission;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetySettings;
use App\Services\HealthSafety\IncidentWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One incident: the details as the reporter filed them, and, for those entitled to the whole file, triage,
 * investigation, persons affected, actions, attachments, closure and the timeline. What each viewer may see is decided
 * by IncidentVisibility; every change goes through IncidentWorkflowService, which authorises it again.
 *
 * Only the incident's id is kept on the component and the incident is read afresh on every request, so a call replayed
 * after the incident moved out of the user's reach is refused rather than trusted.
 */
class IncidentShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithFileUploads;

    #[Locked]
    public int $incidentId;

    // Triage
    public string $severity = '';

    public string $incidentType = '';

    public string $otherTypeText = '';

    public ?int $ownerUserId = null;

    // Investigation
    public string $rootCause = '';

    public string $findings = '';

    // Closure
    public string $closureNote = '';

    /** 'cancel', 'reopen' or 'return' while the reason box is open. */
    public string $reasonFor = '';

    public string $reason = '';

    /** @var array<string, mixed> */
    public array $person = [
        'person_type' => 'staff', 'staff_id' => '', 'name_raw' => '', 'injury_type' => '', 'body_part' => '',
        'treatment' => 'none', 'first_aider_name' => '', 'lost_time_days' => 0, 'returned_to_work_on' => '',
    ];

    /** @var array<string, mixed> */
    public array $newAction = ['description' => '', 'due_on' => '', 'assigned_to_employee_id' => null];

    /** What an assignee writes when marking an action done, keyed by action id. @var array<int, string> */
    public array $completionNotes = [];

    public string $assigneeSearch = '';

    public string $assigneeName = '';

    /** @var array<int, mixed> */
    public array $newPhotos = [];

    public function mount(HsIncident $incident): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident', 'health_safety.view_incidents');
        $this->abortUnlessIncidentVisible($incident);

        $this->incidentId = $incident->id;
        $this->syncForm($incident);
    }

    // ------------------------------------------------------------------ officer actions

    public function acknowledge(IncidentWorkflowService $workflow): void
    {
        $this->syncForm($workflow->acknowledge($this->incident(), $this->actor()));
        $this->toast('Incident acknowledged.');
    }

    public function startInvestigation(IncidentWorkflowService $workflow): void
    {
        $this->syncForm($workflow->startInvestigation($this->incident(), $this->actor()));
        $this->toast('Investigation started.');
    }

    public function saveTriage(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $incident = $workflow->triage($this->incident(), $this->actor(), [
            'severity' => $this->severity,
            'incident_type' => $this->incidentType,
            'other_type_text' => $this->otherTypeText,
            'owner_user_id' => $this->ownerUserId,
        ]);

        $this->syncForm($incident);
        $this->toast('Triage saved.');
    }

    public function saveInvestigation(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $this->syncForm($workflow->saveInvestigation($this->incident(), $this->actor(), $this->rootCause, $this->findings));
        $this->toast('Findings saved.');
    }

    public function close(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $this->syncForm($workflow->close($this->incident(), $this->actor(), $this->closureNote));
        $this->toast('Incident closed.');
    }

    public function sendForApproval(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $this->syncForm($workflow->sendForApproval($this->incident(), $this->actor(), $this->closureNote));
        $this->toast('Sent for approval.');
    }

    public function approve(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $this->syncForm($workflow->approveAndClose($this->incident(), $this->actor(), $this->closureNote));
        $this->toast('Incident approved and closed.');
    }

    public function openReason(string $for): void
    {
        abort_unless(in_array($for, ['cancel', 'reopen', 'return'], true), 422);

        $this->reasonFor = $for;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closeReason(): void
    {
        $this->reasonFor = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function submitReason(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $incident = match ($this->reasonFor) {
            'cancel' => $workflow->cancel($this->incident(), $this->actor(), $this->reason),
            'reopen' => $workflow->reopen($this->incident(), $this->actor(), $this->reason),
            'return' => $workflow->returnForRework($this->incident(), $this->actor(), $this->reason),
            default => abort(422),
        };

        $done = ['cancel' => 'Incident cancelled.', 'reopen' => 'Incident reopened.', 'return' => 'Returned to the officer.'][$this->reasonFor];

        $this->closeReason();
        $this->syncForm($incident);
        $this->toast($done);
    }

    // ------------------------------------------------------------------ persons, actions, photos

    public function addPerson(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $this->validate([
            'person.person_type' => ['required', Rule::in(array_keys(HsIncidentPerson::PERSON_TYPES))],
            'person.treatment' => ['required', Rule::in(array_keys(HsIncidentPerson::TREATMENTS))],
            'person.staff_id' => ['nullable', 'string', 'max:50'],
            'person.name_raw' => ['nullable', 'string', 'max:150'],
            'person.injury_type' => ['nullable', 'string', 'max:150'],
            'person.body_part' => ['nullable', 'string', 'max:150'],
            'person.first_aider_name' => ['nullable', 'string', 'max:150'],
            'person.lost_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'person.returned_to_work_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $workflow->addPerson($this->incident(), $this->actor(), $this->person);

        $this->reset('person');
        $this->toast('Person recorded.');
    }

    public function removePerson(int $personId, IncidentWorkflowService $workflow): void
    {
        $workflow->removePerson($this->incident(), $this->actor(), $personId);
        $this->toast('Person removed.');
    }

    public function chooseAssignee(int $employeeId): void
    {
        $employee = $this->employeeMatches($this->assigneeSearch, 50)->firstWhere('id', $employeeId);

        if ($employee) {
            $this->newAction['assigned_to_employee_id'] = $employee->id;
            $this->assigneeName = $employee->full_name.' ('.$employee->staff_id.')';
            $this->assigneeSearch = '';
        }
    }

    public function clearAssignee(): void
    {
        $this->newAction['assigned_to_employee_id'] = null;
        $this->assigneeName = '';
    }

    public function createAction(IncidentWorkflowService $workflow): void
    {
        $this->resetErrorBag();

        $this->validate([
            'newAction.description' => ['required', 'string', 'max:2000'],
            'newAction.due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'newAction.assigned_to_employee_id' => ['required', 'integer'],
        ], [
            'newAction.description.required' => 'Say what has to be done.',
            'newAction.due_on.required' => 'Set a due date.',
            'newAction.due_on.after_or_equal' => 'The due date cannot be in the past.',
            'newAction.assigned_to_employee_id.required' => 'Choose who it is assigned to.',
        ]);

        $workflow->createAction($this->incident(), $this->actor(), $this->newAction);

        $this->newAction = ['description' => '', 'due_on' => '', 'assigned_to_employee_id' => null];
        $this->assigneeName = '';
        $this->toast('Action assigned.');
    }

    public function completeAction(int $actionId, IncidentWorkflowService $workflow): void
    {
        $this->validate(['completionNotes.'.$actionId => ['nullable', 'string', 'max:1000']]);

        $workflow->completeAction($this->actionOf($actionId), $this->actor(), $this->completionNotes[$actionId] ?? null);

        unset($this->completionNotes[$actionId]);
        $this->toast('Action marked done.');
    }

    public function verifyAction(int $actionId, IncidentWorkflowService $workflow): void
    {
        $workflow->verifyAction($this->actionOf($actionId), $this->actor());
        $this->toast('Action verified.');
    }

    public function uploadPhotos(IncidentWorkflowService $workflow): void
    {
        $maxKb = (int) config('gwl.hs_attachment_max_mb') * 1024;

        $this->validate([
            'newPhotos' => ['required', 'array', 'min:1'],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ], [], ['newPhotos.*' => 'photo']);

        $workflow->addPhotos($this->incident(), $this->actor(), array_values($this->newPhotos));

        $this->reset('newPhotos');
        $this->toast('Photos added.');
    }

    // ------------------------------------------------------------------ internals

    /** The incident, read afresh and checked against the viewer on every request. */
    protected function incident(): HsIncident
    {
        $incident = HsIncident::query()->findOrFail($this->incidentId);
        $this->abortUnlessIncidentVisible($incident);

        return $incident;
    }

    protected function actionOf(int $actionId): HsIncidentAction
    {
        return HsIncidentAction::query()->where('incident_id', $this->incidentId)->findOrFail($actionId);
    }

    /** Load the form fields from the incident. Internal fields are only read for those entitled to see them. */
    protected function syncForm(HsIncident $incident): void
    {
        $this->severity = (string) $incident->severity;
        $this->incidentType = $incident->incident_type;
        $this->otherTypeText = (string) $incident->other_type_text;
        $this->ownerUserId = $incident->owner_user_id;

        if ($this->visibility()->canSeeInternal($this->actor(), $incident)) {
            $this->rootCause = (string) $incident->root_cause_category;
            $this->findings = (string) $incident->findings;
            $this->closureNote = (string) $incident->closure_note;
        }
    }

    protected function toast(string $message): void
    {
        $this->dispatch('toast', type: 'success', message: $message);
    }

    /** @return Collection<int, User> Officers and managers who could own this incident. */
    protected function ownerOptions(HsIncident $incident): Collection
    {
        return User::query()
            ->active()
            ->visibleInErp()
            ->whereHas('roles.permissions', fn ($query) => $query->where('name', 'health_safety.manage_incidents'))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'staff_id', 'employee_id'])
            ->filter(fn (User $user) => $this->visibility()->inScope($user, $incident))
            ->values();
    }

    public function render()
    {
        $user = $this->actor();
        $visibility = $this->visibility();
        $incident = $this->incident();
        $incident->load(['region', 'district', 'department', 'site', 'owner', 'attachments']);

        $internal = $visibility->canSeeInternal($user, $incident);
        $canManage = $visibility->canManage($user, $incident);
        $canSeeInjury = $visibility->canSeeInjuryDetails($user, $incident);

        return view('livewire.health_safety.incident-show', [
            'incident' => $incident,
            'reporter' => $visibility->reporterFor($user, $incident),
            'ownerName' => $visibility->nameFor($user, $incident, $incident->owner_user_id),
            'timeline' => $visibility->timeline($user, $incident),
            'internal' => $internal,
            'canManage' => $canManage,
            'canApprove' => $visibility->canApproveClosure($user, $incident),
            // With hs_require_second_approver on, the person who sent it for approval may not also approve it.
            'approverBlocked' => (bool) HealthSafetySettings::value('hs_require_second_approver')
                && $incident->status === HsIncident::STATUS_PENDING_CLOSURE
                && app(IncidentWorkflowService::class)->sentForApprovalBy($incident) === (int) $user->id,
            'closure' => $visibility->closureFor($user, $incident),
            'canSeeInjury' => $canSeeInjury,
            'canWriteInjury' => $canManage && $canSeeInjury,
            'canSeeClosureNote' => $visibility->canSeeClosureNote($user, $incident),
            'persons' => $canSeeInjury ? $incident->persons()->with('employee')->get() : collect(),
            'actions' => $internal ? $incident->actions()->with('assignee')->orderBy('due_on')->get() : collect(),
            'viewerEmployeeId' => $this->actorEmployee()?->id,
            'owners' => $canManage ? $this->ownerOptions($incident) : collect(),
            'assigneeMatches' => $canManage && $this->assigneeSearch !== '' ? $this->employeeMatches($this->assigneeSearch) : collect(),
            'statuses' => HsIncident::STATUSES,
            'types' => HsIncident::TYPES,
            'severities' => HsIncident::SEVERITIES,
            'rootCauses' => HsIncident::ROOT_CAUSES,
            'personTypes' => HsIncidentPerson::PERSON_TYPES,
            'treatments' => HsIncidentPerson::TREATMENTS,
            'needsInvestigation' => in_array($incident->incident_type, HsIncident::TYPES_NEEDING_INVESTIGATION, true),
            'maxPhotos' => (int) config('gwl.hs_attachments_per_incident'),
            'maxMb' => (int) config('gwl.hs_attachment_max_mb'),
        ]);
    }
}
