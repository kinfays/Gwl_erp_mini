<?php

namespace App\Services\Leave;

use App\Exceptions\Leave\ApproverNotFoundException;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who recommends and who finally approves a leave request, and who may act on it.
 *
 * Routing depends on where the applicant sits and on their own role (a manager applies to the level above):
 *
 *   District staff        district_manager (same district)           -> regional_chief_manager (same region)
 *   Regional-office staff departmental_manager (same dept + region)  -> regional_chief_manager (same region)
 *   district_manager / departmental_manager in a region/district     -> regional_chief_manager (single stage)
 *   Head Office, has unit unit manager (same dept + unit)            -> chief_manager (same department)
 *   Head Office, no unit  departmental_manager (same department)     -> chief_manager (same department)
 *   unit manager / departmental_manager at Head Office               -> chief_manager (single stage)
 *   chief_manager / regional_chief_manager                           -> managing_director (single stage)
 *
 * Only active users with an active employee record are considered, the applicant is never their own
 * recommender or approver, and every stage is a *set* (several people can hold a role in a scope).
 */
class LeaveApprovalChainResolver
{
    public const STAGE_RECOMMEND = 'recommend';

    public const STAGE_FINAL = 'final';

    /** Roles that recommend (or, for a manager's own leave, are skipped). */
    public const RECOMMENDER_ROLES = ['manager', 'departmental_manager', 'district_manager'];

    /** Roles that give the final approval. */
    public const APPROVER_ROLES = ['chief_manager', 'regional_chief_manager', 'managing_director'];

    public const ROLE_LABELS = [
        'manager' => 'Unit Manager',
        'departmental_manager' => 'Departmental Manager',
        'district_manager' => 'District Manager',
        'chief_manager' => 'Chief Manager',
        'regional_chief_manager' => 'Regional Chief Manager',
        'managing_director' => 'Managing Director',
    ];

    /**
     * Role-holder lookups cached for the length of one queue scan (many requests share a scope). Null
     * outside a scan: a long-lived instance must never serve stale roles.
     *
     * @var array<string, Collection<int, User>>|null
     */
    protected ?array $holders = null;

    /**
     * @throws ApproverNotFoundException when a stage the applicant needs has nobody to take it
     */
    public function route(Employee $applicant): LeaveApprovalRoute
    {
        $applicantUser = $this->userOf($applicant);
        $roles = $applicantUser?->roles->pluck('name')->all() ?? [];
        $is = fn (string ...$names) => array_intersect($names, $roles) !== [];

        if ($is('managing_director')) {
            throw new ApproverNotFoundException(
                'managing_director',
                'Your leave request cannot be submitted: there is no approver above the Managing Director. Please contact the system administrator.'
            );
        }

        // A chief manager / regional chief manager applies straight to the MD, wherever they sit.
        if ($is('chief_manager', 'regional_chief_manager')) {
            return $this->build($applicant, $applicantUser, null, [], 'managing_director', []);
        }

        return match ($applicant->location_type) {
            'HeadOffice' => $this->headOffice($applicant, $applicantUser, $is),
            'Region' => $this->region($applicant, $applicantUser, $is),
            'District' => $this->district($applicant, $applicantUser, $is),
            default => throw new ApproverNotFoundException(
                'location',
                'Your leave request cannot be submitted: your staff record has no valid location. Please contact HR.'
            ),
        };
    }

    /**
     * Compatibility for callers that want employees (the demo seeder): the first recommender's employee
     * (null when the route is single-stage) and the first approver's employee.
     *
     * @return array{0: ?Employee, 1: Employee}
     */
    public function resolve(Employee $applicant): array
    {
        $route = $this->route($applicant);

        return [$this->employeeOf($route->recommender()), $this->employeeOf($route->approver())];
    }

    /** The stage a request is waiting at, or null when nobody needs to act on it. */
    public function stageOf(LeaveRequest $request): ?string
    {
        if ($request->leave_status !== 'Pending Approval') {
            return null;
        }

        return match ($request->manager_recommendation) {
            'Pending' => self::STAGE_RECOMMEND,
            'Recommended' => self::STAGE_FINAL,
            default => null,
        };
    }

    /**
     * May $user act at $stage of $request?
     *
     * The applicant never can, not even a super_admin. Otherwise: a super_admin, the user snapshotted on the
     * request at submission (provided they are still active and still hold a role for that stage), or anyone
     * this resolver finds for the applicant right now (a second holder of the role, or a replacement).
     * A user who merely holds the role somewhere else does not qualify.
     */
    public function canAct(User $user, LeaveRequest $request, ?string $stage = null): bool
    {
        $stage ??= $this->stageOf($request);

        if ($stage === null || $this->isApplicant($user, $request)) {
            return false;
        }

        if ($user->hasRoles('super_admin')) {
            return true;
        }

        return $this->eligible($request, $stage)->contains('id', $user->id);
    }

    /**
     * Everyone who may act at $stage of $request: the snapshotted approver plus whoever the chain resolves
     * today. Notified together; whoever acts first wins.
     *
     * @return Collection<int, User>
     */
    public function eligible(LeaveRequest $request, string $stage): Collection
    {
        $pool = collect();

        if ($snapshot = $this->snapshotUser($request, $stage)) {
            $pool->push($snapshot);
        }

        try {
            $route = $this->route($request->requester);
            $pool = $pool->merge($stage === self::STAGE_RECOMMEND ? $route->recommenders : $route->approvers);
        } catch (ApproverNotFoundException) {
            // The chain has since broken (someone left); the snapshot alone is still valid.
        }

        return $pool
            ->reject(fn (User $user) => $this->isApplicant($user, $request))
            ->unique('id')
            ->values();
    }

    /**
     * Pending requests $user can act on right now (the approvals queue, the home-page count).
     *
     * @return Collection<int, LeaveRequest>
     */
    public function actionableRequests(User $user): Collection
    {
        if (! $user->hasRoles(...self::RECOMMENDER_ROLES, ...self::APPROVER_ROLES) && ! $this->isActing($user)) {
            return collect();
        }

        return $this->scan(fn () => LeaveRequest::query()
            ->with(['requester.user.roles', 'requester.userByStaffId.roles'])
            ->where('leave_status', 'Pending Approval')
            ->latest()
            ->get()
            ->filter(fn (LeaveRequest $request) => $this->canAct($user, $request))
            ->values());
    }

    /**
     * Is $request part of $user's chain — theirs to recommend or decide, or already decided by them? Backs
     * the "All requests" list, which also shows requests that are no longer pending.
     */
    public function inChain(User $user, Employee $employee, LeaveRequest $request): bool
    {
        if ($request->requester_id === $employee->id) {
            return false;
        }

        if ((int) $request->manager_id === $employee->id
            || (int) $request->approved_by_id === $employee->id
            || (int) $request->manager_user_id === $user->id
            || (int) $request->chief_user_id === $user->id) {
            return true;
        }

        if (! $user->hasRoles(...self::RECOMMENDER_ROLES, ...self::APPROVER_ROLES) && ! $this->isActing($user)) {
            return false;
        }

        // Not snapshotted (predates the snapshot, or a co-holder of the role): ask the chain as it is now.
        $stage = $request->manager_recommendation === 'Pending' ? self::STAGE_RECOMMEND : self::STAGE_FINAL;

        return $this->eligible($request, $stage)->contains('id', $user->id);
    }

    /** Runs $callback with role-holder lookups cached, for scanning many requests at once. */
    public function scan(callable $callback): mixed
    {
        $this->holders = [];

        try {
            return $callback();
        } finally {
            $this->holders = null;
        }
    }

    /** Does $user hold an active acting assignment today? Such a user may approve without holding the role. */
    public function isActing(User $user): bool
    {
        return app(LeaveActingAssignmentService::class)->hasActiveAssignment($user);
    }

    /**
     * The capacity $user acts in for the final stage of $request: `acting` when they are in the approver set only through an
     * acting assignment, otherwise `substantive` (the post-holder, a super_admin, or a chain that has since changed).
     *
     * @return 'substantive'|'acting'
     */
    public function capacityOf(User $user, LeaveRequest $request): string
    {
        try {
            return $this->route($request->requester)->isActing($user) ? 'acting' : 'substantive';
        } catch (ApproverNotFoundException) {
            return 'substantive';
        }
    }

    /** The role that recommends this applicant's leave, from where they sit (null: they apply straight to the final approver). */
    public function recommenderRoleFor(Employee $applicant, ?User $applicantUser = null): ?string
    {
        $roles = ($applicantUser ?? $this->userOf($applicant))?->roles->pluck('name')->all() ?? [];
        $is = fn (string ...$names) => array_intersect($names, $roles) !== [];

        if ($is('managing_director', 'chief_manager', 'regional_chief_manager')) {
            return null;
        }

        return match ($applicant->location_type) {
            'HeadOffice' => $is('manager', 'departmental_manager') ? null : (filled($applicant->unit) ? 'manager' : 'departmental_manager'),
            'Region' => $is('district_manager', 'departmental_manager') ? null : 'departmental_manager',
            default => $is('district_manager', 'departmental_manager') ? null : 'district_manager',
        };
    }

    /** The post that gives the final approval for this applicant. */
    public function approverRoleFor(Employee $applicant, ?User $applicantUser = null): string
    {
        $roles = ($applicantUser ?? $this->userOf($applicant))?->roles->pluck('name')->all() ?? [];

        if (array_intersect(['chief_manager', 'regional_chief_manager'], $roles) !== []) {
            return 'managing_director';
        }

        return $applicant->location_type === 'HeadOffice' ? 'chief_manager' : 'regional_chief_manager';
    }

    public function isApplicant(User $user, LeaveRequest $request): bool
    {
        $employee = $this->employeeOf($user);

        if ($employee && $employee->id === (int) $request->requester_id) {
            return true;
        }

        $requesterUser = $request->requester ? $this->userOf($request->requester) : null;

        return $requesterUser !== null && $requesterUser->id === $user->id;
    }

    public function employeeOf(?User $user): ?Employee
    {
        return $user?->employee ?? $user?->employeeByStaffId;
    }

    public function userOf(?Employee $employee): ?User
    {
        return $employee?->user ?? $employee?->userByStaffId;
    }

    public function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? str($role)->replace('_', ' ')->title()->toString();
    }

    protected function headOffice(Employee $applicant, ?User $applicantUser, callable $is): LeaveApprovalRoute
    {
        // Head Office is a district, so "in the department" must also mean "at Head Office": a departmental
        // manager of the same department at a regional office is not this applicant's manager.
        $chief = ['department_id' => $applicant->department_id];
        $atHeadOffice = ['department_id' => $applicant->department_id, 'location_type' => 'HeadOffice'];

        if ($is('manager', 'departmental_manager')) {
            return $this->build($applicant, $applicantUser, null, [], 'chief_manager', $chief);
        }

        return filled($applicant->unit)
            ? $this->build($applicant, $applicantUser, 'manager', [...$atHeadOffice, 'unit' => $applicant->unit], 'chief_manager', $chief)
            : $this->build($applicant, $applicantUser, 'departmental_manager', $atHeadOffice, 'chief_manager', $chief);
    }

    protected function region(Employee $applicant, ?User $applicantUser, callable $is): LeaveApprovalRoute
    {
        $chief = ['region_id' => $applicant->region_id];

        if ($is('district_manager', 'departmental_manager')) {
            return $this->build($applicant, $applicantUser, null, [], 'regional_chief_manager', $chief);
        }

        // `location_type = Region` keeps Head Office managers out: the Head Office district can sit in this region.
        return $this->build($applicant, $applicantUser, 'departmental_manager', [
            'department_id' => $applicant->department_id,
            'region_id' => $applicant->region_id,
            'location_type' => 'Region',
        ], 'regional_chief_manager', $chief);
    }

    protected function district(Employee $applicant, ?User $applicantUser, callable $is): LeaveApprovalRoute
    {
        $chief = ['region_id' => $applicant->region_id];

        if ($is('district_manager', 'departmental_manager')) {
            return $this->build($applicant, $applicantUser, null, [], 'regional_chief_manager', $chief);
        }

        return $this->build($applicant, $applicantUser, 'district_manager', ['district_id' => $applicant->district_id], 'regional_chief_manager', $chief);
    }

    /**
     * @param  array<string, mixed>  $recommenderScope
     * @param  array<string, mixed>  $approverScope
     */
    protected function build(
        Employee $applicant,
        ?User $applicantUser,
        ?string $recommenderRole,
        array $recommenderScope,
        string $approverRole,
        array $approverScope,
    ): LeaveApprovalRoute {
        $holders = $this->holdersOf($approverRole, $approverScope)
            ->reject(fn (User $user) => $this->isSelf($user, $applicant, $applicantUser))
            ->values();

        // Someone acting in the post (inside their window, and their region or department when they have one) is as
        // valid an approver as the holder, and is recorded as having acted in that capacity.
        $acting = app(LeaveActingAssignmentService::class)->usersFor($approverRole, $approverScope)
            ->reject(fn (User $user) => $this->isSelf($user, $applicant, $applicantUser))
            ->reject(fn (User $user) => $holders->contains('id', $user->id))
            ->values();

        $approvers = $holders->merge($acting)->values();

        if ($approvers->isEmpty()) {
            throw $this->missing($approverRole, $approverScope, 'approve');
        }

        $recommenders = collect();

        if ($recommenderRole !== null) {
            $holders = $this->holdersOf($recommenderRole, $recommenderScope);

            if ($holders->isEmpty()) {
                throw $this->missing($recommenderRole, $recommenderScope, 'recommend');
            }

            // If the applicant is the only holder, that stage would be themselves: skip it.
            $recommenders = $holders
                ->reject(fn (User $user) => $this->isSelf($user, $applicant, $applicantUser))
                ->values();
        }

        return new LeaveApprovalRoute($recommenders, $approvers, $recommenderRole, $approverRole, $acting);
    }

    /**
     * Active users holding $role whose active employee record matches $scope, in id order.
     *
     * @param  array<string, mixed>  $scope  employee column => value
     * @return Collection<int, User>
     */
    protected function holdersOf(string $role, array $scope): Collection
    {
        $key = $role.'|'.json_encode($scope);

        if ($this->holders !== null && isset($this->holders[$key])) {
            return $this->holders[$key];
        }

        $matchesScope = function ($employee) use ($scope) {
            $employee->where('is_active', true);

            foreach ($scope as $column => $value) {
                $employee->where($column, $value);
            }
        };

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', $role))
            ->where(fn ($query) => $query
                ->whereHas('employee', $matchesScope)
                ->orWhereHas('employeeByStaffId', $matchesScope))
            ->with(['employee', 'employeeByStaffId'])
            ->orderBy('id')
            ->get();

        if ($this->holders !== null) {
            $this->holders[$key] = $users;
        }

        return $users;
    }

    protected function isSelf(User $candidate, Employee $applicant, ?User $applicantUser): bool
    {
        return $candidate->id === $applicantUser?->id
            || $this->employeeOf($candidate)?->id === $applicant->id;
    }

    /** The user snapshotted for $stage, if still active, still holding a role for it, and not the applicant. */
    protected function snapshotUser(LeaveRequest $request, string $stage): ?User
    {
        $recommend = $stage === self::STAGE_RECOMMEND;
        $userId = $recommend ? $request->manager_user_id : $request->chief_user_id;

        // Requests submitted before the snapshot columns: the recommender is whoever manager_id names.
        if ($userId === null && $recommend && ! $request->is_single_stage && $request->manager_id) {
            $userId = User::query()
                ->where('employee_id', $request->manager_id)
                ->orWhereIn('staff_id', Employee::query()->whereKey($request->manager_id)->select('staff_id'))
                ->value('id');
        }

        if ($userId === null) {
            return null;
        }

        $user = User::query()->with(['employee', 'employeeByStaffId'])->find($userId);

        if (! $user
            || ! $user->is_active
            || $this->employeeOf($user)?->is_active === false
            || (! $user->hasRoles($recommend ? self::RECOMMENDER_ROLES : self::APPROVER_ROLES) && ($recommend || ! $this->isActing($user)))
            || $this->isApplicant($user, $request)) {
            return null;
        }

        return $user;
    }

    /** @param  array<string, mixed>  $scope */
    protected function missing(string $role, array $scope, string $verb): ApproverNotFoundException
    {
        $label = $this->roleLabel($role);
        $where = $this->describeScope($scope);

        return new ApproverNotFoundException($role, sprintf(
            'Your leave request cannot be submitted: no active %s is set up to %s it%s. Please contact HR.',
            $label,
            $verb,
            $where !== '' ? ' ('.$where.')' : ''
        ));
    }

    /** @param  array<string, mixed>  $scope */
    protected function describeScope(array $scope): string
    {
        $parts = [];

        if (! empty($scope['department_id'])) {
            $parts[] = Department::query()->whereKey($scope['department_id'])->value('department_name');
        }

        if (! empty($scope['unit'])) {
            $parts[] = 'unit '.$scope['unit'];
        }

        if (! empty($scope['district_id'])) {
            $parts[] = District::query()->whereKey($scope['district_id'])->value('district_name');
        } elseif (! empty($scope['region_id'])) {
            $parts[] = Region::query()->whereKey($scope['region_id'])->value('region_name');
        }

        return collect($parts)->filter()->join(', ');
    }
}
