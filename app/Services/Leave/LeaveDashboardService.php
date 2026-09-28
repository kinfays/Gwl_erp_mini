<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only aggregates for the charts and lists on the HR leave dashboard. Scoping matches the rest
 * of that dashboard (App\Livewire\Leave\HrDashboard): regional HR sees its own region, head-office
 * HR, admins and super admins see every region.
 */
class LeaveDashboardService
{
    public const STATUSES = ['Approved', 'Pending Approval', 'Planned', 'Denied'];

    public function scopedRequests(User $user, ?Employee $actor): Builder
    {
        $query = LeaveRequest::query();

        if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
            $query->where('region_id', $actor?->region_id);
        }

        return $query;
    }

    /**
     * Approved leave days per month of `$year`, counted in the month each leave starts. The current
     * year stops at the current month.
     *
     * @return array{labels: array<int, string>, data: array<int, int>}
     */
    public function approvedDaysByMonth(User $user, ?Employee $actor, int $year): array
    {
        $totals = $this->scopedRequests($user, $actor)
            ->where('leave_status', 'Approved')
            ->whereYear('start_date', $year)
            ->get(['start_date', 'total_days_applied'])
            ->groupBy(fn (LeaveRequest $request) => (int) Carbon::parse($request->start_date)->format('n'))
            ->map(fn (Collection $requests) => (int) $requests->sum('total_days_applied'));

        $lastMonth = $year === (int) now()->format('Y') ? (int) now()->format('n') : 12;
        $months = range(1, $lastMonth);

        return [
            'labels' => array_map(fn (int $month) => Carbon::create($year, $month, 1)->format('M'), $months),
            'data' => array_map(fn (int $month) => (int) ($totals[$month] ?? 0), $months),
        ];
    }

    /**
     * Leave requests starting in `$year`, counted by status (every status listed, zeros included).
     *
     * @return array{labels: array<int, string>, data: array<int, int>}
     */
    public function requestsByStatus(User $user, ?Employee $actor, int $year): array
    {
        $counts = $this->scopedRequests($user, $actor)
            ->whereYear('start_date', $year)
            ->get(['leave_status'])
            ->countBy('leave_status');

        return [
            'labels' => self::STATUSES,
            'data' => array_map(fn (string $status) => (int) ($counts[$status] ?? 0), self::STATUSES),
        ];
    }

    /**
     * Approved leave that starts within the next `$days` days (from tomorrow), soonest first.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function upcomingAbsences(User $user, ?Employee $actor, int $days = 14, int $limit = 8): Collection
    {
        return $this->scopedRequests($user, $actor)
            ->with(['requester.department', 'requester.district'])
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '>', today())
            ->whereDate('start_date', '<=', today()->addDays($days))
            ->orderBy('start_date')
            ->limit($limit)
            ->get();
    }
}
