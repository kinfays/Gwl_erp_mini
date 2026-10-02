<?php

namespace App\Services\Leave;

use App\Exceptions\Leave\ApproverNotFoundException;
use App\Exceptions\Leave\LeaveAlreadyActionedException;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LeaveWorkflowService
{
    public function __construct(
        protected WorkingDaysCalculator $daysCalc,
        protected LeaveBalanceService $balances,
        protected LeaveApprovalChainResolver $chain,
        protected LeaveNotificationService $notify
    ) {}

    /** A Planned draft has no approver yet: the chain is resolved when it is submitted. */
    public function savePlanned(Employee $requester, array $data): LeaveRequest
    {
        $this->guardEligible($requester);

        return $this->createOrUpdate($requester, $data, 'Planned', null);
    }

    /**
     * @throws ValidationException when nobody is set up to approve for this applicant
     * @throws RuntimeException when the casual-leave rule blocks it
     */
    public function submit(Employee $requester, array $data): LeaveRequest
    {
        $this->guardEligible($requester);
        $this->guardCasual($requester, $data);

        $request = $this->createOrUpdate($requester, $data, 'Pending Approval', $this->routeFor($requester, $data));

        $this->afterSubmit($request);

        return $request;
    }

    public function updatePlanned(Employee $requester, LeaveRequest $request, array $data): LeaveRequest
    {
        $this->guardEligible($requester);

        return $this->updateExisting($requester, $request, $data, 'Planned', null);
    }

    /**
     * @throws ValidationException when nobody is set up to approve for this applicant
     * @throws RuntimeException when the casual-leave rule blocks it
     */
    public function submitExisting(Employee $requester, LeaveRequest $request, array $data): LeaveRequest
    {
        $this->guardEligible($requester);
        $this->guardCasual($requester, $data);

        $request = $this->updateExisting($requester, $request, $data, 'Pending Approval', $this->routeFor($requester, $data));

        $this->afterSubmit($request);

        return $request;
    }

    /**
     * The manager stage: recommend the request to the final approver, or reject it (a rejection is the
     * final decision). Any eligible recommender may act; the first to do so wins.
     *
     * @throws AuthorizationException when the actor isn't a recommender for this request
     * @throws LeaveAlreadyActionedException when someone else already acted
     */
    public function recommend(Employee $managerActor, LeaveRequest $req, ?string $comments, bool $recommended): LeaveRequest
    {
        $req = DB::transaction(function () use ($managerActor, $req, $comments, $recommended) {
            $locked = $this->lock($req);
            $actor = $this->authorize($managerActor, $locked, LeaveApprovalChainResolver::STAGE_RECOMMEND);

            if ($this->chain->stageOf($locked) !== LeaveApprovalChainResolver::STAGE_RECOMMEND) {
                throw new LeaveAlreadyActionedException;
            }

            // Don't push a request on to a final stage nobody can take: it would sit there for ever.
            if ($recommended && $this->chain->eligible($locked, LeaveApprovalChainResolver::STAGE_FINAL)->isEmpty()) {
                throw new RuntimeException('No active approver is available for the final approval of this request. Please contact HR.');
            }

            $locked->manager_id = $managerActor->id;
            $locked->manager_user_id = $actor->id;
            $locked->manager_comments = filled($comments) ? trim($comments) : null;
            $locked->manager_recommendation = $recommended ? 'Recommended' : 'Rejected';
            $locked->recommended_at = now();

            if (! $recommended) {
                // A manager's rejection is also the final decision.
                $locked->leave_status = 'Denied';
                $locked->decided_at = $locked->recommended_at;
            }

            $locked->save();

            return $locked;
        });

        Audit::log(
            action: $recommended ? 'leave_recommended' : 'leave_rejected_by_manager',
            module: 'leave',
            targetType: 'leave_requests',
            targetId: $req->id,
            metadata: ['requester_id' => $req->requester_id, 'actor_employee_id' => $managerActor->id]
        );

        $recommended ? $this->notify->recommended($req) : $this->notify->denied($req, byManager: true);

        return $req;
    }

    /**
     * The final stage: approve or deny. For a single-stage request this is the only stage. Balance is
     * deducted here, and only here, in the same transaction as the status change.
     *
     * @throws AuthorizationException when the actor isn't a final approver for this request
     * @throws LeaveAlreadyActionedException when someone else already acted
     */
    public function finalDecision(Employee $chiefActor, LeaveRequest $req, ?string $comments, bool $approve): LeaveRequest
    {
        /** @var LeaveBalance|null $balance */
        $balance = null;

        $req = DB::transaction(function () use ($chiefActor, $req, $comments, $approve, &$balance) {
            $locked = $this->lock($req);
            $actor = $this->authorize($chiefActor, $locked, LeaveApprovalChainResolver::STAGE_FINAL);

            if ($locked->leave_status !== 'Pending Approval' || $locked->manager_recommendation === 'Rejected') {
                throw new LeaveAlreadyActionedException;
            }

            if ($locked->manager_recommendation !== 'Recommended') {
                throw new RuntimeException('Request must be recommended before final approval.');
            }

            $locked->chiefManager_comments = filled($comments) ? trim($comments) : null;
            $locked->approved_by_id = $chiefActor->id;
            $locked->chief_user_id = $actor->id;

            if ($locked->is_single_stage) {
                // manager_id is "the recommender, or the final approver when there is no recommender".
                $locked->manager_id = $chiefActor->id;
            }

            $locked->decided_at = now();
            $locked->leave_status = $approve ? 'Approved' : 'Denied';
            $locked->save();

            if ($approve) {
                // Create/fetch the balance on approval and deduct the days.
                $balance = $this->balances->getOrCreateForApproval($locked->requester, $locked->leave_type, (int) $locked->request_year);
                $this->balances->deduct($balance, (int) $locked->total_days_applied);
            }

            return $locked;
        });

        Audit::log(
            action: $approve ? 'leave_approved' : 'leave_denied',
            module: 'leave',
            targetType: 'leave_requests',
            targetId: $req->id,
            metadata: [
                'requester_id' => $req->requester_id,
                'actor_employee_id' => $chiefActor->id,
                'days' => (int) $req->total_days_applied,
                'single_stage' => (bool) $req->is_single_stage,
            ]
        );

        $approve ? $this->notify->approved($req, $balance) : $this->notify->denied($req);

        return $req;
    }

    public function reopen(Employee $requester, LeaveRequest $req): LeaveRequest
    {
        if ($req->requester_id !== $requester->id) {
            throw new RuntimeException('Only requester can reopen.');
        }

        if ($req->leave_status !== 'Denied') {
            throw new RuntimeException('Only denied requests can be reopened.');
        }

        $req->leave_status = 'Planned';
        $req->manager_recommendation = 'Pending';
        $req->manager_comments = null;
        $req->chiefManager_comments = null;
        $req->approved_by_id = null;
        // Back to a draft: a later submission resolves the approvers afresh.
        $req->manager_id = null;
        $req->manager_user_id = null;
        $req->chief_user_id = null;
        $req->is_single_stage = false;
        // Back to planning: a later submission starts a new cycle.
        $req->submitted_at = null;
        $req->recommended_at = null;
        $req->decided_at = null;
        $req->save();

        return $req;
    }

    /** Contract staff (Charwoman grade) have no leave entitlement, so nothing can be planned or submitted for them. */
    protected function guardEligible(Employee $requester): void
    {
        if (! app(LeaveEntitlementCalculator::class)->isEligible($requester)) {
            throw new RuntimeException('Contract staff are not eligible for leave, so a leave request cannot be made. Contact HR if this is a mistake.');
        }
    }

    protected function guardCasual(Employee $requester, array $data): void
    {
        // Casual restriction: block if Annual remaining > 0
        if (($data['leave_type'] ?? '') === 'Casual') {
            $annualRemaining = $this->balances->getVirtualRemaining($requester, 'Annual', (int) now()->format('Y'));

            if ($annualRemaining > 0) {
                throw new RuntimeException('Casual leave is not allowed while Annual leave balance is greater than 0.');
            }
        }
    }

    /**
     * The chain for a submission. When nobody can take a stage the applicant is told which role is missing
     * and the attempt is audited, so HR can see which chains need someone assigned.
     *
     * @throws ValidationException
     */
    protected function routeFor(Employee $requester, array $data): LeaveApprovalRoute
    {
        try {
            return $this->chain->route($requester);
        } catch (ApproverNotFoundException $e) {
            Audit::log(
                action: 'leave_submission_blocked',
                module: 'leave',
                targetType: 'employees',
                targetId: $requester->id,
                metadata: [
                    'missing_role' => $e->role,
                    'message' => $e->getMessage(),
                    'leave_type' => $data['leave_type'] ?? null,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                ]
            );

            throw ValidationException::withMessages(['leave_type' => $e->getMessage()]);
        }
    }

    protected function createOrUpdate(Employee $requester, array $data, string $status, ?LeaveApprovalRoute $route): LeaveRequest
    {
        return LeaveRequest::create([
            'requester_id' => $requester->id,
            'file_attachment' => $data['file_attachment'] ?? null,
            ...$this->attributes($requester, $data, $status, $route, null),
        ]);
    }

    protected function updateExisting(Employee $requester, LeaveRequest $request, array $data, string $status, ?LeaveApprovalRoute $route): LeaveRequest
    {
        if ($request->requester_id !== $requester->id) {
            throw new RuntimeException('Only the requester can edit this request.');
        }

        if (! $request->canBeEditedByRequester()) {
            throw new RuntimeException('This request can no longer be edited because a recommendation has already been given.');
        }

        $request->update([
            'file_attachment' => $data['file_attachment'] ?? $request->file_attachment,
            ...$this->attributes($requester, $data, $status, $route, $request),
        ]);

        return $request->refresh();
    }

    /**
     * The columns shared by a new and an edited request, including the approvers snapshotted from $route.
     * A draft (no route) carries none.
     */
    protected function attributes(Employee $requester, array $data, string $status, ?LeaveApprovalRoute $route, ?LeaveRequest $existing): array
    {
        $start = $data['start_date'];
        $end = $data['end_date'];

        $single = $route?->isSingleStage() ?? false;
        // A single-stage request has no recommender: it is marked recommended at once so it waits at the
        // final stage (nobody performs a recommendation step).
        $recommendation = $single ? 'Recommended' : 'Pending';

        // Editing a request that is already waiting keeps its place (and its submission time);
        // a planned request starts waiting when it is submitted.
        $submittedAt = $status === 'Pending Approval' ? ($existing?->submitted_at ?? now()) : null;

        return [
            'leave_type' => $data['leave_type'],
            'start_date' => $start,
            'end_date' => $end,
            'total_days_applied' => $this->daysCalc->workingDays(Carbon::parse($start), Carbon::parse($end)),
            'leave_details' => $data['leave_details'] ?? null,
            'manager_id' => $route ? $this->chain->employeeOf($single ? $route->approver() : $route->recommender())?->id : null,
            'manager_user_id' => $route?->recommender()?->id,
            'chief_user_id' => $route?->approver()->id,
            'is_single_stage' => $single,
            'manager_comments' => null,
            'manager_recommendation' => $recommendation,
            'leave_status' => $status,
            ...$this->stageTimestamps($submittedAt, $recommendation),
            'approved_by_id' => null,
            'chiefManager_comments' => null,
            'request_year' => (int) Carbon::parse($start)->format('Y'),
            'department_id' => $requester->department_id,
            'region_id' => $requester->region_id,
        ];
    }

    /**
     * Stage times for a request entering (or leaving) the queue. A request that skips the
     * manager is recommended at the moment it is submitted, so the final approver's time starts
     * there and no manager response is counted for it.
     */
    protected function stageTimestamps(?Carbon $submittedAt, string $recommendation): array
    {
        return [
            'submitted_at' => $submittedAt,
            'recommended_at' => $submittedAt && $recommendation === 'Recommended' ? $submittedAt : null,
            'decided_at' => null,
        ];
    }

    protected function afterSubmit(LeaveRequest $request): void
    {
        Audit::log(
            action: 'leave_submitted',
            module: 'leave',
            targetType: 'leave_requests',
            targetId: $request->id,
            metadata: [
                'requester_id' => $request->requester_id,
                'single_stage' => (bool) $request->is_single_stage,
                'manager_user_id' => $request->manager_user_id,
                'chief_user_id' => $request->chief_user_id,
            ]
        );

        $this->notify->submitted($request->loadMissing('requester'));
    }

    /**
     * Re-read the request under a row lock, inside the caller's transaction. Everything that decides whether
     * this action is still allowed happens on this copy, so two people acting at once can't both succeed.
     */
    protected function lock(LeaveRequest $req): LeaveRequest
    {
        return LeaveRequest::query()
            ->with('requester')
            ->whereKey($req->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The user behind $actor, once they are confirmed as allowed to act at $stage of $request: never the
     * applicant, and only a snapshotted or currently-resolved approver (or a super_admin). Holding the role
     * elsewhere is not enough.
     *
     * @throws AuthorizationException
     */
    protected function authorize(Employee $actor, LeaveRequest $request, string $stage): User
    {
        $user = $this->chain->userOf($actor);

        if (! $user || ! $this->chain->canAct($user, $request, $stage)) {
            throw new AuthorizationException('You are not allowed to '.($stage === LeaveApprovalChainResolver::STAGE_RECOMMEND ? 'recommend' : 'approve or deny').' this request.');
        }

        return $user;
    }
}
