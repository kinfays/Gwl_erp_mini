<?php

namespace App\Services\HealthSafety;

use App\Events\HealthSafety\IncidentReported;
use App\Events\HealthSafety\IncidentSeverityRaised;
use App\Events\HealthSafety\IncidentStatusChanged;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Employee;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\HsIncidentAttachment;
use App\Models\HsIncidentPerson;
use App\Models\HsIncidentStatusLog;
use App\Models\HsSite;
use App\Models\Region;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every change to an incident goes through here: who may do it, which states it is allowed from, the rules for closing,
 * the timeline row and the audit entry. Livewire components only collect input.
 *
 * Lifecycle (design section 2.3):
 *
 *   reported -> acknowledged -> investigating --(Low / Medium)------------------> closed
 *                                             \-(High / Critical)-> pending_closure -> closed   (approver)
 *   any open status -> cancelled (reason)         closed -> investigating (reopen, reason)
 *
 * Each action re-reads the incident with a lock inside its transaction and authorises on that copy, so two people
 * acting at once cannot both succeed against a stale status.
 */
class IncidentWorkflowService
{
    /** The private disk photos are kept on. */
    public const DISK = 'local';

    public function __construct(
        protected IncidentVisibility $visibility,
        protected IncidentReferenceGenerator $references,
    ) {}

    // ------------------------------------------------------------------ reporting

    /**
     * File a report. $data is already validated by the form; what is re-checked here is what makes it safe: who may
     * record on behalf of someone, and which region the report belongs to.
     *
     * With $data['anonymous'] nothing is stored that links the report to $reporter: not the report, not the first timeline
     * row, not the audit entry (written with no user and no IP), not the photos (re-encoded to drop their metadata and
     * renamed). $reporter is then used only to work out the region. Anonymous and on-behalf do not mix.
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $photos
     */
    public function submit(User $reporter, array $data, array $photos = []): HsIncident
    {
        $anonymous = (bool) ($data['anonymous'] ?? false);
        $onBehalf = ! $anonymous && (bool) ($data['on_behalf'] ?? false);

        if ($onBehalf) {
            abort_unless($this->visibility->can($reporter, 'health_safety.record_on_behalf'), 403, 'You may not record a report for someone else.');
        }

        $this->guardPhotoCount(0, count($photos));

        $employee = $this->visibility->employeeOf($reporter);
        $site = filled($data['site_id'] ?? null) ? HsSite::query()->active()->find($data['site_id']) : null;
        $district = filled($data['district_id'] ?? null) ? District::query()->find($data['district_id']) : null;
        $context = $data['context'];

        // Region follows the place: the district or pay point chosen, else the reporter's own (regional office).
        $regionId = match ($context) {
            HsIncident::CONTEXT_REGIONAL_OFFICE => $employee?->region_id,
            default => $district?->region_id ?? $site?->region_id ?? $employee?->region_id,
        };

        if (! $regionId) {
            throw ValidationException::withMessages(['context' => 'Your account has no region, so the place of this report cannot be placed. Contact an administrator.']);
        }

        $region = Region::query()->findOrFail($regionId);

        // A regional-office report belongs to the reporter's own district (their office), when it is in that region.
        if ($context === HsIncident::CONTEXT_REGIONAL_OFFICE && $employee?->district_id && (int) $employee->region_id === (int) $regionId) {
            $district = $employee->district;
        }

        [$reportedByUserId, $reportedByEmployeeId, $reporterNameRaw] = $anonymous
            ? [null, null, null]
            : $this->resolveReporter($reporter, $employee, $data, $onBehalf);

        $attributes = [
            'incident_type' => $data['incident_type'],
            'other_type_text' => $data['incident_type'] === HsIncident::TYPE_OTHER ? ($data['other_type_text'] ?? null) : null,
            'context' => $context,
            'region_id' => $regionId,
            'district_id' => $district?->id,
            'department_id' => $context === HsIncident::CONTEXT_REGIONAL_OFFICE ? ($data['department_id'] ?? null) : null,
            'site_id' => $context === HsIncident::CONTEXT_PAY_POINT ? $site?->id : null,
            'site_name_raw' => $context === HsIncident::CONTEXT_PAY_POINT && ! $site ? ($data['site_name_raw'] ?? null) : null,
            'location_detail' => $data['location_detail'] ?? null,
            'occurred_on' => $data['occurred_on'],
            'occurred_time' => filled($data['occurred_time'] ?? null) ? $data['occurred_time'] : null,
            'description' => trim($data['description']),
            'first_aid' => $data['first_aid'],
            'no_witness' => (bool) ($data['no_witness'] ?? false),
            'witness_name' => ($data['no_witness'] ?? false) ? null : ($data['witness_name'] ?? null),
            'witness_contact' => ($data['no_witness'] ?? false) ? null : ($data['witness_contact'] ?? null),
            'reported_by_user_id' => $reportedByUserId,
            'reported_by_employee_id' => $reportedByEmployeeId,
            'reporter_name_raw' => $reporterNameRaw,
            'recorded_by_user_id' => $onBehalf ? $reporter->id : null,
            // Anonymous already hides the reporter from everyone, so the confidential tick means nothing with it.
            'is_confidential' => ! $anonymous && (bool) ($data['is_confidential'] ?? false),
            'is_urgent' => (bool) ($data['is_urgent'] ?? false),
            'is_anonymous' => $anonymous,
            'status' => HsIncident::STATUS_REPORTED,
        ];

        $incident = $this->createWithReference($region, $attributes, $anonymous ? null : $reporter);

        if ($photos !== []) {
            $this->storePhotos($incident, $anonymous ? null : $reporter, $photos, $anonymous);
        }

        $this->audit('health_safety.incident_reported', $incident, [
            'reference' => $incident->reference,
            'type' => $incident->incident_type,
            'context' => $incident->context,
            'on_behalf' => $onBehalf,
            'anonymous' => $anonymous,
        ], $anonymous);

        IncidentReported::dispatch($incident);

        return $incident;
    }

    // ------------------------------------------------------------------ officer work

    public function acknowledge(HsIncident $incident, User $actor): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor) {
            $this->guardManage($actor, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_REPORTED], 'Only a newly reported incident can be acknowledged.');

            $this->acknowledgeNow($locked, $actor);
            $this->move($locked, HsIncident::STATUS_ACKNOWLEDGED, $actor);

            return 'incident_acknowledged';
        });
    }

    /**
     * Set severity, reclassify the type and choose the owner. A severity of High or Critical (newly set or raised) tells
     * the regional chief manager and the Health & Safety Manager. (Tracking an outside notification was dropped, section
     * 10 question 5: the hs_incidents columns for it are left unused.)
     *
     * @param  array<string, mixed>  $data  severity (required), incident_type, other_type_text, owner_user_id
     */
    public function triage(HsIncident $incident, User $actor, array $data): HsIncident
    {
        $raised = false;

        $result = $this->transition($incident, $actor, function (HsIncident $locked) use ($actor, $data, &$raised) {
            $this->guardManage($actor, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_REPORTED, HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING], 'This incident can no longer be triaged. Return it for rework or reopen it first.');

            $severity = $data['severity'] ?? null;

            if (! array_key_exists((string) $severity, HsIncident::SEVERITIES)) {
                throw ValidationException::withMessages(['severity' => 'Choose a severity.']);
            }

            $type = $data['incident_type'] ?? $locked->incident_type;

            if (! array_key_exists($type, HsIncident::TYPES)) {
                throw ValidationException::withMessages(['incident_type' => 'Choose a type.']);
            }

            $ownerId = $data['owner_user_id'] ?? $locked->owner_user_id;

            if (filled($ownerId)) {
                $owner = User::query()->active()->find($ownerId);

                if (! $owner || ! $this->visibility->can($owner, 'health_safety.manage_incidents')) {
                    throw ValidationException::withMessages(['owner_user_id' => 'The owner must be a Health & Safety officer.']);
                }
            }

            $changes = [];
            $before = $locked->severity;

            if ($locked->severity !== $severity) {
                $changes[] = 'Severity: '.($before ? HsIncident::SEVERITIES[$before] : 'not set').' -> '.HsIncident::SEVERITIES[$severity];
            }

            if ($locked->incident_type !== $type) {
                $changes[] = 'Type: '.HsIncident::TYPES[$locked->incident_type].' -> '.HsIncident::TYPES[$type];
            }

            if ((int) $locked->owner_user_id !== (int) $ownerId && filled($ownerId)) {
                // No name here: the timeline note is read by people who may not be allowed to know the owner is the reporter.
                $changes[] = 'Owner changed';
            }

            $locked->fill([
                'severity' => $severity,
                'incident_type' => $type,
                'other_type_text' => $type === HsIncident::TYPE_OTHER ? ($data['other_type_text'] ?? $locked->other_type_text) : null,
                'owner_user_id' => filled($ownerId) ? $ownerId : $actor->id,
            ]);

            if ($locked->status === HsIncident::STATUS_REPORTED) {
                $this->acknowledgeNow($locked, $actor);
                $this->move($locked, HsIncident::STATUS_ACKNOWLEDGED, $actor, $changes ? implode('; ', $changes) : null);
            } else {
                $locked->save();

                if ($changes) {
                    $this->log($locked, $locked->status, $locked->status, $actor, implode('; ', $changes));
                }
            }

            $raised = in_array($severity, HsIncident::SEVERITIES_NEEDING_APPROVAL, true)
                && ! in_array($before, HsIncident::SEVERITIES_NEEDING_APPROVAL, true);

            return ($changes || $locked->wasChanged('acknowledged_at')) ? 'incident_triaged' : null;
        });

        if ($raised) {
            IncidentSeverityRaised::dispatch($result);
        }

        return $result;
    }

    public function startInvestigation(HsIncident $incident, User $actor): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor) {
            $this->guardManage($actor, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_REPORTED, HsIncident::STATUS_ACKNOWLEDGED], 'The investigation can only be started on a reported or acknowledged incident.');

            if ($locked->status === HsIncident::STATUS_REPORTED) {
                $this->acknowledgeNow($locked, $actor);
            }

            $this->move($locked, HsIncident::STATUS_INVESTIGATING, $actor);

            return 'incident_investigation_started';
        });
    }

    /** Save the investigation's root cause and findings (internal: the reporter never sees them). */
    public function saveInvestigation(HsIncident $incident, User $actor, ?string $rootCause, ?string $findings): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor, $rootCause, $findings) {
            $this->guardManage($actor, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING], 'Acknowledge the incident before recording findings; a closed one must be reopened first.');

            if (filled($rootCause) && ! array_key_exists($rootCause, HsIncident::ROOT_CAUSES)) {
                throw ValidationException::withMessages(['root_cause_category' => 'Choose a root cause category.']);
            }

            $locked->fill([
                'root_cause_category' => filled($rootCause) ? $rootCause : null,
                'findings' => filled($findings) ? trim($findings) : null,
            ])->save();

            return 'incident_investigation_saved';
        });
    }

    // ------------------------------------------------------------------ closing

    /** Close a Low / Medium incident. */
    public function close(HsIncident $incident, User $actor, ?string $closureNote): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor, $closureNote) {
            $this->guardManage($actor, $locked);
            $this->guardClosable($locked, $closureNote);

            if ($locked->needsApprovalToClose()) {
                throw ValidationException::withMessages(['severity' => 'High and Critical incidents are closed by an approver. Send it for approval instead.']);
            }

            $locked->closure_note = trim((string) $closureNote);
            $locked->closed_at = now();
            $locked->closed_by = $actor->id;
            $this->move($locked, HsIncident::STATUS_CLOSED, $actor);

            return 'incident_closed';
        });
    }

    /** A High / Critical incident goes to an approver with the closure note it will be closed with. */
    public function sendForApproval(HsIncident $incident, User $actor, ?string $closureNote): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor, $closureNote) {
            $this->guardManage($actor, $locked);
            $this->guardClosable($locked, $closureNote);

            if (! $locked->needsApprovalToClose()) {
                throw ValidationException::withMessages(['severity' => 'Only High and Critical incidents need approval. Close this one directly.']);
            }

            $locked->closure_note = trim((string) $closureNote);
            $this->move($locked, HsIncident::STATUS_PENDING_CLOSURE, $actor);

            return 'incident_closure_requested';
        });
    }

    public function approveAndClose(HsIncident $incident, User $approver, ?string $closureNote = null): HsIncident
    {
        return $this->transition($incident, $approver, function (HsIncident $locked) use ($approver, $closureNote) {
            $this->guardApprover($approver, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_PENDING_CLOSURE], 'Only an incident that is waiting for approval can be approved.');
            $this->guardSecondApprover($approver, $locked);

            if (filled($closureNote)) {
                $locked->closure_note = trim($closureNote);
            }

            $locked->closed_at = now();
            $locked->closed_by = $approver->id;
            $locked->approved_at = now();
            $locked->approved_by = $approver->id;
            $this->move($locked, HsIncident::STATUS_CLOSED, $approver);

            return 'incident_closed';
        });
    }

    /** The approver sends it back to the officer, with a reason. */
    public function returnForRework(HsIncident $incident, User $approver, string $reason): HsIncident
    {
        return $this->transition($incident, $approver, function (HsIncident $locked) use ($approver, $reason) {
            $this->guardApprover($approver, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_PENDING_CLOSURE], 'Only an incident that is waiting for approval can be returned.');

            $this->move($locked, HsIncident::STATUS_INVESTIGATING, $approver, 'Returned for rework: '.$this->requireReason($reason));

            return 'incident_closure_returned';
        });
    }

    public function cancel(HsIncident $incident, User $actor, string $reason): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor, $reason) {
            $this->guardManage($actor, $locked);
            $this->guardStatus($locked, HsIncident::OPEN_STATUSES, 'Only an open incident can be cancelled.');

            $locked->cancel_reason = $this->requireReason($reason);
            $this->move($locked, HsIncident::STATUS_CANCELLED, $actor, $locked->cancel_reason);

            return 'incident_cancelled';
        });
    }

    public function reopen(HsIncident $incident, User $actor, string $reason): HsIncident
    {
        return $this->transition($incident, $actor, function (HsIncident $locked) use ($actor, $reason) {
            $this->guardManage($actor, $locked);
            $this->guardStatus($locked, [HsIncident::STATUS_CLOSED], 'Only a closed incident can be reopened.');

            $note = $this->requireReason($reason);
            $locked->reopened_count++;
            $locked->closed_at = null;
            $locked->closed_by = null;
            $locked->approved_at = null;
            $locked->approved_by = null;
            $this->move($locked, HsIncident::STATUS_INVESTIGATING, $actor, 'Reopened: '.$note);

            return 'incident_reopened';
        });
    }

    // ------------------------------------------------------------------ persons affected

    /**
     * @param  array<string, mixed>  $data  person_type, name_raw, staff_id, injury_type, body_part, treatment, first_aider_name, lost_time_days, returned_to_work_on
     */
    public function addPerson(HsIncident $incident, User $actor, array $data): HsIncidentPerson
    {
        $this->guardManage($actor, $incident);
        abort_unless($this->visibility->canSeeInjuryDetails($actor, $incident), 403, 'You may not record injury details.');

        return DB::transaction(function () use ($incident, $actor, $data) {
            $locked = $this->lock($incident);
            $this->guardStatus($locked, [HsIncident::STATUS_REPORTED, HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING], 'Injury details can only be changed while the incident is being worked on.');

            $employee = filled($data['staff_id'] ?? null) ? Employee::query()->where('staff_id', trim($data['staff_id']))->first() : null;

            if (filled($data['staff_id'] ?? null) && ! $employee) {
                throw ValidationException::withMessages(['person.staff_id' => 'No member of staff has that staff ID.']);
            }

            if (! $employee && blank($data['name_raw'] ?? null)) {
                throw ValidationException::withMessages(['person.name_raw' => 'Enter the person\'s name or staff ID.']);
            }

            $person = $locked->persons()->create([
                'employee_id' => $employee?->id,
                'name_raw' => $employee ? null : trim($data['name_raw']),
                'person_type' => $employee ? 'staff' : ($data['person_type'] ?? 'staff'),
                'injury_type' => $data['injury_type'] ?? null,
                'body_part' => $data['body_part'] ?? null,
                'treatment' => $data['treatment'] ?? 'none',
                'first_aider_name' => $data['first_aider_name'] ?? null,
                'lost_time_days' => (int) ($data['lost_time_days'] ?? 0),
                'returned_to_work_on' => filled($data['returned_to_work_on'] ?? null) ? $data['returned_to_work_on'] : null,
            ]);

            Audit::log('health_safety.person_saved', 'health_safety', 'hs_incidents', $locked->id, ['reference' => $locked->reference]);

            return $person;
        });
    }

    public function removePerson(HsIncident $incident, User $actor, int $personId): void
    {
        $this->guardManage($actor, $incident);
        abort_unless($this->visibility->canSeeInjuryDetails($actor, $incident), 403, 'You may not change injury details.');

        DB::transaction(function () use ($incident, $personId) {
            $locked = $this->lock($incident);
            $this->guardStatus($locked, [HsIncident::STATUS_REPORTED, HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING], 'Injury details can only be changed while the incident is being worked on.');

            $locked->persons()->whereKey($personId)->delete();

            Audit::log('health_safety.person_removed', 'health_safety', 'hs_incidents', $locked->id, ['reference' => $locked->reference]);
        });
    }

    // ------------------------------------------------------------------ actions

    /** @param  array<string, mixed>  $data  description, assigned_to_employee_id, due_on */
    public function createAction(HsIncident $incident, User $actor, array $data): HsIncidentAction
    {
        $this->guardManage($actor, $incident);

        $action = DB::transaction(function () use ($incident, $actor, $data) {
            $locked = $this->lock($incident);

            if ($locked->status === HsIncident::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['action.description' => 'A cancelled incident cannot take new actions.']);
            }

            $employee = Employee::query()->find($data['assigned_to_employee_id'] ?? null);

            if (! $employee) {
                throw ValidationException::withMessages(['action.assigned_to_employee_id' => 'Choose who the action is assigned to.']);
            }

            $action = $locked->actions()->create([
                'description' => trim($data['description']),
                'assigned_to_employee_id' => $employee->id,
                'due_on' => $data['due_on'],
                'status' => HsIncidentAction::STATUS_OPEN,
                'created_by' => $actor->id,
            ]);

            Audit::log('health_safety.action_created', 'health_safety', 'hs_incident_actions', $action->id, [
                'reference' => $locked->reference,
                'due_on' => $action->due_on->toDateString(),
            ]);

            return $action;
        });

        app(IncidentNotificationService::class)->actionAssigned($action);

        return $action;
    }

    /** The person it is assigned to may mark their own action done; so may anyone who manages the incident. */
    public function completeAction(HsIncidentAction $action, User $actor, ?string $note = null): HsIncidentAction
    {
        return DB::transaction(function () use ($action, $actor, $note) {
            $locked = HsIncidentAction::query()->lockForUpdate()->with('incident')->findOrFail($action->id);

            $employeeId = $this->visibility->employeeOf($actor)?->id;
            $own = $employeeId !== null && (int) $employeeId === (int) $locked->assigned_to_employee_id;

            abort_unless($own || $this->visibility->canManage($actor, $locked->incident), 403, 'This action is not assigned to you.');

            if ($locked->status !== HsIncidentAction::STATUS_OPEN) {
                throw ValidationException::withMessages(['action' => 'This action is already done.']);
            }

            $locked->fill([
                'status' => HsIncidentAction::STATUS_DONE,
                'completed_on' => today(),
                'completion_note' => filled($note) ? trim($note) : null,
            ])->save();

            Audit::log('health_safety.action_completed', 'health_safety', 'hs_incident_actions', $locked->id, ['reference' => $locked->incident->reference]);

            return $locked;
        });
    }

    public function verifyAction(HsIncidentAction $action, User $actor): HsIncidentAction
    {
        return DB::transaction(function () use ($action, $actor) {
            $locked = HsIncidentAction::query()->lockForUpdate()->with('incident')->findOrFail($action->id);

            $this->guardManage($actor, $locked->incident);

            if ($locked->status !== HsIncidentAction::STATUS_DONE) {
                throw ValidationException::withMessages(['action' => 'Only an action that is marked done can be verified.']);
            }

            $locked->fill([
                'status' => HsIncidentAction::STATUS_VERIFIED,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ])->save();

            Audit::log('health_safety.action_verified', 'health_safety', 'hs_incident_actions', $locked->id, ['reference' => $locked->incident->reference]);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ photos

    /** @param  list<UploadedFile>  $photos */
    public function addPhotos(HsIncident $incident, User $actor, array $photos): void
    {
        $this->guardManage($actor, $incident);

        DB::transaction(function () use ($incident, $actor, $photos) {
            $locked = $this->lock($incident);
            $this->guardPhotoCount($locked->attachments()->count(), count($photos));
            $this->storePhotos($locked, $actor, $photos);
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * Run a state change inside a transaction on a locked copy of the incident, save it, and report the result to the
     * audit log. The callback returns the audit action name (without the module prefix), or null when nothing changed.
     * The status event fires after the commit, so a notification never describes a change that was rolled back.
     */
    protected function transition(HsIncident $incident, User $actor, callable $change): HsIncident
    {
        $from = null;

        $locked = DB::transaction(function () use ($incident, $change, &$from) {
            $locked = $this->lock($incident);
            $from = $locked->status;
            $audit = $change($locked);
            $locked->save();

            if ($audit) {
                Audit::log('health_safety.'.$audit, 'health_safety', 'hs_incidents', $locked->id, [
                    'reference' => $locked->reference,
                    'status' => $locked->status,
                    'severity' => $locked->severity,
                ]);
            }

            return $locked;
        });

        if ($locked->status !== $from) {
            IncidentStatusChanged::dispatch($locked, $from, $locked->status);
        }

        return $locked;
    }

    protected function lock(HsIncident $incident): HsIncident
    {
        return HsIncident::query()->lockForUpdate()->findOrFail($incident->id);
    }

    protected function guardManage(User $actor, HsIncident $incident): void
    {
        abort_unless($this->visibility->canManage($actor, $incident), 403, 'You may not manage this incident.');
    }

    protected function guardApprover(User $actor, HsIncident $incident): void
    {
        abort_unless($this->visibility->canApproveClosure($actor, $incident), 403, 'You may not approve the closure of this incident.');
    }

    /**
     * With hs_require_second_approver on, whoever sent the incident for approval cannot also approve it. super_admin is
     * not exempt: the point is that two different people look at a High or Critical incident before it is closed.
     */
    protected function guardSecondApprover(User $approver, HsIncident $incident): void
    {
        if (HealthSafetySettings::value('hs_require_second_approver') && $this->sentForApprovalBy($incident) === (int) $approver->id) {
            throw ValidationException::withMessages(['status' => 'You sent this incident for approval, so someone else has to approve it.']);
        }
    }

    /** The user who last sent the incident for approval, or null if nobody has (or the row has no user). */
    public function sentForApprovalBy(HsIncident $incident): ?int
    {
        $userId = HsIncidentStatusLog::query()
            ->where('incident_id', $incident->id)
            ->where('to_status', HsIncident::STATUS_PENDING_CLOSURE)
            ->latest('id')
            ->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    /** @param  list<string>  $allowed */
    protected function guardStatus(HsIncident $incident, array $allowed, string $message): void
    {
        if (! in_array($incident->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * What an incident must have before it can be closed or sent for approval: it was acknowledged, has a severity (so
     * a High incident cannot slip past approval for want of one), a closure note for the reporter, and, for every type
     * but Near miss and Other, a root cause and findings. Open actions do not block closure.
     */
    protected function guardClosable(HsIncident $incident, ?string $closureNote): void
    {
        $this->guardStatus($incident, [HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING], 'Acknowledge the incident before closing it.');

        $errors = [];

        if (blank($incident->severity)) {
            $errors['severity'] = 'Set a severity (triage) before closing.';
        }

        if (blank($closureNote)) {
            $errors['closure_note'] = 'Write the closure note the reporter will see.';
        }

        if (in_array($incident->incident_type, HsIncident::TYPES_NEEDING_INVESTIGATION, true)) {
            if (blank($incident->root_cause_category)) {
                $errors['root_cause_category'] = 'Record the root cause category before closing this type of incident.';
            }

            if (blank($incident->findings)) {
                $errors['findings'] = 'Record the findings before closing this type of incident.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return mb_substr($reason, 0, 1000);
    }

    protected function acknowledgeNow(HsIncident $incident, User $actor): void
    {
        $incident->acknowledged_at = now();
        $incident->acknowledged_by = $actor->id;
        $incident->owner_user_id ??= $actor->id;
    }

    /** Change the status (the caller saves) and write the timeline row. */
    protected function move(HsIncident $incident, string $to, User $actor, ?string $note = null): void
    {
        $from = $incident->status;
        $incident->status = $to;
        $incident->save();

        $this->log($incident, $from, $to, $actor, $note);
    }

    protected function log(HsIncident $incident, ?string $from, string $to, ?User $actor, ?string $note = null): void
    {
        HsIncidentStatusLog::query()->create([
            'incident_id' => $incident->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createWithReference(Region $region, array $attributes, ?User $reporter): HsIncident
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($region, $attributes, $reporter) {
                    $incident = HsIncident::query()->create([...$attributes, 'reference' => $this->references->next($region)]);
                    $this->log($incident, null, HsIncident::STATUS_REPORTED, $reporter);

                    return $incident;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Another report took the number between reading the highest and writing: ask again.
                if (++$attempts >= 5) {
                    throw $exception;
                }
            }
        }
    }

    /** @return array{0: int|null, 1: int|null, 2: string|null} user id, employee id, name in words */
    protected function resolveReporter(User $submitter, ?Employee $employee, array $data, bool $onBehalf): array
    {
        if (! $onBehalf) {
            return [$submitter->id, $employee?->id, null];
        }

        $person = filled($data['behalf_employee_id'] ?? null) ? Employee::query()->find($data['behalf_employee_id']) : null;

        if ($person) {
            $user = User::query()
                ->where(fn ($query) => $query->where('employee_id', $person->id)->orWhere('staff_id', $person->staff_id))
                ->first();

            return [$user?->id, $person->id, $user ? null : $person->full_name];
        }

        if (blank($data['behalf_name'] ?? null)) {
            throw ValidationException::withMessages(['behalfName' => 'Say who this report is for.']);
        }

        return [null, null, trim($data['behalf_name'])];
    }

    protected function guardPhotoCount(int $existing, int $adding): void
    {
        $limit = (int) config('gwl.hs_attachments_per_incident');

        if ($existing + $adding > $limit) {
            throw ValidationException::withMessages(['photos' => "An incident can hold at most {$limit} photos."]);
        }
    }

    /**
     * @param  list<UploadedFile>  $photos
     * @param  bool  $anonymous  re-encode each photo (dropping the GPS position, device and time a phone writes into it),
     *                           give it a neutral name, and record nobody as the uploader
     */
    protected function storePhotos(HsIncident $incident, ?User $actor, array $photos, bool $anonymous = false): void
    {
        foreach ($photos as $index => $photo) {
            if ($anonymous) {
                [$content, $mime, $extension] = $this->withoutMetadata($photo);
                $path = 'health_safety/incidents/'.$incident->id.'/'.Str::random(40).'.'.$extension;
                $name = 'photo-'.($index + 1).'.'.$extension;
                $size = strlen($content);

                if (! Storage::disk(self::DISK)->put($path, $content)) {
                    $path = null;
                }
            } else {
                $path = $photo->store('health_safety/incidents/'.$incident->id, self::DISK);
                $name = mb_substr($photo->getClientOriginalName(), 0, 255);
                $mime = $photo->getClientMimeType();
                $size = $photo->getSize() ?: 0;
            }

            if (! $path) {
                throw ValidationException::withMessages(['photos' => 'A photo could not be saved. Try again.']);
            }

            HsIncidentAttachment::query()->create([
                'incident_id' => $incident->id,
                'path' => $path,
                'original_name' => $name,
                'mime' => $mime,
                'size' => $size,
                'uploaded_by' => $anonymous ? null : $actor?->id,
            ]);
        }

        $this->audit('health_safety.attachment_added', $incident, [
            'reference' => $incident->reference,
            'count' => count($photos),
        ], $anonymous);
    }

    /**
     * The picture alone, with everything else a phone writes into the file left behind: GPS position, device, time, name.
     * It is decoded and written out again (turned upright first, since the orientation lives in that metadata).
     *
     * @return array{0: string, 1: string, 2: string} bytes, mime type, file extension
     */
    protected function withoutMetadata(UploadedFile $photo): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['photos' => 'Photos cannot be attached to an anonymous report on this server, because their hidden details cannot be removed here.']);
        }

        $bytes = (string) file_get_contents($photo->getRealPath());
        $info = @getimagesizefromstring($bytes);
        $image = $info ? @imagecreatefromstring($bytes) : false;

        if (! $image) {
            throw ValidationException::withMessages(['photos' => 'A photo could not be read. Try another.']);
        }

        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($photo->getRealPath())['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0))) {
                $image = $rotated;
            }
        }

        ob_start();

        $written = match ($info['mime']) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => $this->pngWithAlpha($image),
            'image/webp' => imagewebp($image, null, 90),
            default => false,
        };

        $content = (string) ob_get_clean();
        imagedestroy($image);

        if (! $written || $content === '') {
            throw ValidationException::withMessages(['photos' => 'Only JPG, PNG and WebP photos can be attached to an anonymous report.']);
        }

        return [$content, $info['mime'], ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']]];
    }

    protected function pngWithAlpha(\GdImage $image): bool
    {
        imagesavealpha($image, true);

        return imagepng($image);
    }

    /**
     * Write an audit entry. An anonymous one is written with no user, no IP and a neutral name, because the usual helper
     * records who is signed in and where from, which is exactly what an anonymous report must not keep.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(string $action, HsIncident $incident, array $metadata, bool $anonymous): void
    {
        if (! $anonymous) {
            Audit::log($action, 'health_safety', 'hs_incidents', $incident->id, $metadata);

            return;
        }

        AuditLog::query()->create([
            'user_id' => null,
            'user_name' => 'Anonymous',
            'action' => $action,
            'module' => 'health_safety',
            'target_type' => 'hs_incidents',
            'target_id' => $incident->id,
            'metadata' => $metadata,
            'ip_address' => null,
            'actor_is_super_admin' => false,
        ]);
    }

    public function disk()
    {
        return Storage::disk(self::DISK);
    }
}
