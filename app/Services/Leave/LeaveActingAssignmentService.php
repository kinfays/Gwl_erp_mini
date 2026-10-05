<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveActingAssignment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Acting in a final-approver post. An active assignment makes its user a valid approver for that post, inside its date
 * window and (when it names one) its region or department, alongside the substantive holder: the resolver treats them as
 * one more member of the approver set, so only resolved approvers may act, the first action wins, and anyone else is
 * refused. The window is inclusive on both days and nothing outside it counts.
 *
 * Who manages them: Head Office HR and Global Admin for any scope, regional HR only for a regional chief manager of their
 * own region, super_admin everywhere. Every change is audited.
 */
class LeaveActingAssignmentService
{
    public const PERMISSION = 'leave.manage_acting';

    /** The posts someone can act in: the final approvers. */
    public const ROLES = ['chief_manager', 'regional_chief_manager', 'managing_director'];

    protected function employeeOf(?User $user): ?Employee
    {
        return $user?->employee ?? $user?->employeeByStaffId;
    }

    // ------------------------------------------------------------------ the resolver's questions

    /**
     * Active users acting in $role for a request scoped by `region_id` / `department_id` (the resolver's approver scope).
     * An assignment limited to a region or department only counts for that one.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, User>
     */
    public function usersFor(string $role, array $scope, $date = null): Collection
    {
        if (! in_array($role, self::ROLES, true)) {
            return collect();
        }

        return LeaveActingAssignment::query()
            ->activeOn($date)
            ->where('acting_for_role', $role)
            ->where(fn ($query) => $query->whereNull('region_id')->when(! empty($scope['region_id']), fn ($q) => $q->orWhere('region_id', $scope['region_id'])))
            ->where(fn ($query) => $query->whereNull('department_id')->when(! empty($scope['department_id']), fn ($q) => $q->orWhere('department_id', $scope['department_id'])))
            ->with(['user.employee', 'user.employeeByStaffId'])
            ->orderBy('id')
            ->get()
            ->map->user
            ->filter(fn (?User $user) => $user && $user->is_active && $this->employeeOf($user)?->is_active !== false)
            ->unique('id')
            ->values();
    }

    /** Does $user hold an active acting assignment today (any post)? */
    public function hasActiveAssignment(User $user, $date = null): bool
    {
        return LeaveActingAssignment::query()->activeOn($date)->where('user_id', $user->id)->exists();
    }

    // ------------------------------------------------------------------ managing them

    public function canManage(User $user): bool
    {
        if ($user->hasRoles('super_admin', 'admin')) {
            return true;
        }

        return $user->hasRoles('hr_headoffice', 'hr_region') && $user->hasPermission(self::PERMISSION);
    }

    /** Regional HR: the one region they may set assignments for; null means every scope. */
    protected function regionLimit(User $user): ?int
    {
        if ($user->hasRoles('super_admin', 'admin', 'hr_headoffice')) {
            return null;
        }

        return (int) $this->employeeOf($user)?->region_id ?: 0;
    }

    public function canManageAssignment(User $user, ?LeaveActingAssignment $assignment): bool
    {
        if (! $this->canManage($user)) {
            return false;
        }

        $limit = $this->regionLimit($user);

        return $limit === null || ($assignment !== null && $limit > 0 && $assignment->acting_for_role === 'regional_chief_manager' && (int) $assignment->region_id === $limit);
    }

    /**
     * @param  array{user_id: int, acting_for_role: string, region_id?: ?int, department_id?: ?int, starts_on: string, ends_on: string}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function save(User $actor, array $data, ?LeaveActingAssignment $assignment = null): LeaveActingAssignment
    {
        $data['region_id'] = ($data['region_id'] ?? null) ?: null;
        $data['department_id'] = ($data['department_id'] ?? null) ?: null;

        if (! $this->canManage($actor) || ($assignment && ! $this->canManageAssignment($actor, $assignment))) {
            throw new AuthorizationException('You are not allowed to manage acting assignments.');
        }

        $this->validate($actor, $data);

        $old = $assignment?->only(['user_id', 'acting_for_role', 'region_id', 'department_id', 'starts_on', 'ends_on', 'is_active']);
        $assignment ??= new LeaveActingAssignment(['created_by' => $actor->id, 'is_active' => true]);
        $assignment->fill(collect($data)->only(['user_id', 'acting_for_role', 'region_id', 'department_id', 'starts_on', 'ends_on'])->all())->save();

        $this->audit($assignment->exists && $old ? 'leave_acting_assignment_updated' : 'leave_acting_assignment_created', $assignment, $old);

        return $assignment;
    }

    /** Switch an assignment off or on without deleting it (it keeps its history). */
    public function setActive(User $actor, LeaveActingAssignment $assignment, bool $active): LeaveActingAssignment
    {
        if (! $this->canManageAssignment($actor, $assignment)) {
            throw new AuthorizationException('You are not allowed to manage this acting assignment.');
        }

        $old = $assignment->only(['is_active']);
        $assignment->update(['is_active' => $active]);
        $this->audit($active ? 'leave_acting_assignment_enabled' : 'leave_acting_assignment_disabled', $assignment, $old);

        return $assignment;
    }

    public function delete(User $actor, LeaveActingAssignment $assignment): void
    {
        if (! $this->canManageAssignment($actor, $assignment)) {
            throw new AuthorizationException('You are not allowed to manage this acting assignment.');
        }

        $this->audit('leave_acting_assignment_deleted', $assignment, $assignment->only(['user_id', 'acting_for_role', 'region_id', 'department_id', 'starts_on', 'ends_on', 'is_active']));
        $assignment->delete();
    }

    /** @param  array<string, mixed>  $data */
    protected function validate(User $actor, array $data): void
    {
        $errors = [];

        if (! in_array($data['acting_for_role'] ?? null, self::ROLES, true)) {
            $errors['acting_for_role'] = 'Choose the post being covered.';
        }

        $acting = User::query()->where('is_active', true)->find($data['user_id'] ?? 0);

        if (! $acting || $this->employeeOf($acting)?->is_active === false) {
            $errors['user_id'] = 'Choose an active user to act.';
        }

        if (($data['acting_for_role'] ?? null) === 'regional_chief_manager' && empty($data['region_id'])) {
            $errors['region_id'] = 'A regional chief manager post belongs to a region: choose which one.';
        }

        if (($data['acting_for_role'] ?? null) === 'chief_manager' && empty($data['department_id'])) {
            $errors['department_id'] = 'A chief manager post belongs to a department: choose which one.';
        }

        if (empty($data['starts_on']) || empty($data['ends_on']) || $data['ends_on'] < $data['starts_on']) {
            $errors['ends_on'] = 'The assignment must end on or after the day it starts.';
        }

        // Regional HR only cover a regional chief manager of their own region.
        $limit = $this->regionLimit($actor);

        if ($limit !== null && ((int) ($data['region_id'] ?? 0) !== $limit || ($data['acting_for_role'] ?? null) !== 'regional_chief_manager')) {
            $errors['region_id'] = 'You can only set a regional chief manager acting assignment for your own region.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  array<string, mixed>|null  $old */
    protected function audit(string $action, LeaveActingAssignment $assignment, ?array $old): void
    {
        \App\Models\AuditLog::record(
            $action,
            'leave',
            'leave_acting_assignments',
            $assignment->id,
            $old === null ? null : array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $old),
            $assignment->exists ? array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $assignment->only(['user_id', 'acting_for_role', 'region_id', 'department_id', 'starts_on', 'ends_on', 'is_active'])) : null,
            ['acting_user_id' => $assignment->user_id]
        );
    }

    /** Employees can't be read here without the resolver: kept for the page's picker label. */
    public function labelFor(User $user): string
    {
        $employee = $this->employeeOf($user);

        return ($employee?->full_name ?? $user->full_name).' ('.($employee?->staff_id ?? $user->staff_id).')';
    }
}
