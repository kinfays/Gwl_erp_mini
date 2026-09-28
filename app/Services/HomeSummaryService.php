<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LetterNotification;
use App\Models\Permission;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Leave\LeaveApprovalChainResolver;
use App\Services\Leave\LeaveBalanceService;
use Throwable;

/**
 * Figures for the per-user summary on the landing page (/dashboard). Each one mirrors the screen
 * it links to, so the two never disagree: the Annual card on the leave home, the approvals queue
 * (App\Livewire\Leave\Approvals), the letters bell and today's visitor log. Read-only.
 */
class HomeSummaryService
{
    public const MANAGERIAL_ROLES = [
        'manager',
        'departmental_manager',
        'district_manager',
        'chief_manager',
        'regional_chief_manager',
    ];

    public function __construct(
        protected LeaveBalanceService $balances,
        protected LeaveApprovalChainResolver $resolver,
    ) {
    }

    /**
     * @return array{
     *     leave: array{remaining: int, total: int, used: int, year: int}|null,
     *     approvals: array{count: int, actionable: bool}|null,
     *     letters: array{unread: int}|null,
     *     visitors: array{today: int, on_site: int}|null
     * }
     */
    public function forUser(User $user): array
    {
        $employee = $user->employee ?? $user->employeeByStaffId;
        $modules = $user->getAccessibleModules();

        return [
            'leave' => $employee ? $this->annualLeave($employee) : null,
            'approvals' => $this->approvals($user, $employee),
            'letters' => $employee && in_array(Permission::MODULE_LETTERS, $modules, true)
                ? $this->unreadLetters($employee)
                : null,
            'visitors' => in_array(Permission::MODULE_VISITORS, $modules, true)
                ? $this->visitorsToday()
                : null,
        ];
    }

    /**
     * Same numbers as the "Annual Leave" card on the employee leave home.
     *
     * @return array{remaining: int, total: int, used: int, year: int}
     */
    public function annualLeave(Employee $employee): array
    {
        $year = (int) now()->format('Y');

        $used = (int) LeaveRequest::query()
            ->where('requester_id', $employee->id)
            ->where('leave_type', 'Annual')
            ->where('leave_status', 'Approved')
            ->where('request_year', $year)
            ->sum('total_days_applied');

        $remaining = $this->balances->getVirtualRemaining($employee, 'Annual', $year);

        return [
            'remaining' => $remaining,
            'total' => max((int) $employee->annual_leave_days, $used + $remaining),
            'used' => $used,
            'year' => $year,
        ];
    }

    /**
     * Pending leave requests for the approvals screen. Managers and chiefs get the requests waiting
     * on them (actionable); HR, admins and super admins get their zone's read-only pending count.
     * Null for everyone else.
     *
     * @return array{count: int, actionable: bool}|null
     */
    public function approvals(User $user, ?Employee $employee): ?array
    {
        if ($user->hasRoles('super_admin', 'admin') || $user->isHrUser()) {
            $query = LeaveRequest::query()->where('leave_status', 'Pending Approval');

            if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
                if (! $employee) {
                    return null;
                }

                $query->where('region_id', $employee->region_id);
            }

            return ['count' => $query->count(), 'actionable' => false];
        }

        if (! $employee || ! $user->hasRoles(...self::MANAGERIAL_ROLES)) {
            return null;
        }

        // The queue rules from Approvals::render(): the manager stage waits on manager_id, the chief
        // stage on whoever the approval chain resolves as chief.
        $count = LeaveRequest::query()
            ->with('requester')
            ->where('leave_status', 'Pending Approval')
            ->where(function ($query) use ($employee) {
                $query->where(function ($manager) use ($employee) {
                    $manager->where('manager_id', $employee->id)
                        ->where('manager_recommendation', 'Pending');
                })->orWhere('manager_recommendation', 'Recommended');
            })
            ->get()
            ->filter(function (LeaveRequest $request) use ($employee) {
                try {
                    [, $chief] = $this->resolver->resolve($request->requester);

                    return $request->manager_recommendation === 'Recommended'
                        ? $chief->id === $employee->id
                        : true;
                } catch (Throwable) {
                    return false;
                }
            })
            ->count();

        return ['count' => $count, 'actionable' => true];
    }

    /**
     * Same count as the letters notification bell.
     *
     * @return array{unread: int}
     */
    public function unreadLetters(Employee $employee): array
    {
        return [
            'unread' => LetterNotification::query()
                ->where('secretariat_id', $employee->id)
                ->where('is_read', false)
                ->count(),
        ];
    }

    /**
     * Same counts as the header of today's visitor log.
     *
     * @return array{today: int, on_site: int}
     */
    public function visitorsToday(): array
    {
        return [
            'today' => Visitor::query()->today()->count(),
            'on_site' => Visitor::query()->today()->inside()->count(),
        ];
    }
}
