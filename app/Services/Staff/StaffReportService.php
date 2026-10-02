<?php

namespace App\Services\Staff;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class StaffLeaveReportService
{
    public function __construct(protected EmployeeDirectory $directory) {}

    public function resolveDateRange(string $preset, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = today();

        return match ($preset) {
            'last_3_months' => [$today->copy()->subMonthsNoOverflow(2)->startOfMonth(), $today->copy()->endOfMonth()],
            'last_6_months' => [$today->copy()->subMonthsNoOverflow(5)->startOfMonth(), $today->copy()->endOfMonth()],
            'last_12_months' => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today->copy()->endOfMonth()],
            'custom' => [
                $customFrom ? Carbon::parse($customFrom)->startOfDay() : $today->copy()->startOfMonth(),
                $customTo ? Carbon::parse($customTo)->endOfDay() : $today->copy()->endOfDay(),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };
    }

    public function reportPayload(
        User $user,
        Carbon $from,
        Carbon $to,
        ?int $departmentId = null,
        ?int $regionId = null,
        ?int $districtId = null
    ): array {
        return $this->remember('payload', [
            'user_id' => $user->id,
            'scope' => $this->scopeSignature($user),
            'from' => $from,
            'to' => $to,
            'department_id' => $departmentId,
            'region_id' => $regionId,
            'district_id' => $districtId,
        ], function () use ($user, $from, $to, $departmentId, $regionId, $districtId): array {
            $employees = $this->reportEmployeeQuery($user, $departmentId, $regionId, $districtId)
                ->with(['department', 'region', 'district.region'])
                ->get();

            $activeEmployees = $employees->where('is_active', true)->values();
            $activeEmployeeIds = $activeEmployees->pluck('id')->all();

            $currentLeaves = $this->currentLeaveQuery($activeEmployeeIds)
                ->with(['requester.department', 'requester.region', 'requester.district'])
                ->get();
            $currentLeaveEmployeeIds = $currentLeaves->pluck('requester_id')->unique()->values();

            $periodLeaves = $this->periodLeaveQuery($activeEmployeeIds, $from, $to)
                ->with(['requester.department', 'requester.region', 'requester.district'])
                ->get();

            return [
                'scopeLabel' => $this->scopeLabel($user, $regionId, $districtId),
                'statCards' => $this->statCards($activeEmployees, $currentLeaves, $periodLeaves),
                'genderDistribution' => $this->genderDistribution($activeEmployees),
                'staffByDistrict' => $this->staffByDistrict($activeEmployees, $currentLeaveEmployeeIds),
                'staffByRegion' => $this->staffByRegion($activeEmployees),
                'staffByDepartment' => $this->staffByDepartment($activeEmployees),
                'staffByCategory' => $this->staffByCategory($activeEmployees),
                'leaveStatusBreakdown' => $this->leaveStatusBreakdown($periodLeaves),
                'leaveTypeDays' => $this->leaveTypeDays($periodLeaves),
                'monthlyLeaveRequests' => $this->monthlyLeaveRequests($periodLeaves, $from, $to),
                'districtRows' => $this->districtRows($activeEmployees, $currentLeaveEmployeeIds),
                'currentlyOnLeave' => $this->currentlyOnLeave($currentLeaves),
            ];
        });
    }

    public function filterOptions(User $user, ?int $selectedRegionId = null): array
    {
        $baseQuery = $this->baseEmployeeQuery($user);

        $departmentIds = (clone $baseQuery)
            ->whereNotNull('department_id')
            ->distinct()
            ->pluck('department_id');
        $regionIds = (clone $baseQuery)
            ->whereNotNull('region_id')
            ->distinct()
            ->pluck('region_id');

        $districtQuery = (clone $baseQuery)
            ->whereNotNull('district_id')
            ->when($selectedRegionId, fn (Builder $query) => $query->where('region_id', $selectedRegionId));

        $districtIds = $districtQuery
            ->distinct()
            ->pluck('district_id');

        return [
            'departments' => Department::query()
                ->whereIn('id', $departmentIds)
                ->orderBy('department_name')
                ->get(),
            'regions' => Region::query()
                ->whereIn('id', $regionIds)
                ->orderBy('region_name')
                ->get(),
            'districts' => District::query()
                ->with('region')
                ->whereIn('id', $districtIds)
                ->orderBy('district_name')
                ->get(),
        ];
    }

    protected function reportEmployeeQuery(
        User $user,
        ?int $departmentId = null,
        ?int $regionId = null,
        ?int $districtId = null
    ): Builder {
        return $this->baseEmployeeQuery($user)
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($regionId, fn (Builder $query) => $query->where('region_id', $regionId))
            ->when($districtId, fn (Builder $query) => $query->where('district_id', $districtId));
    }

    protected function baseEmployeeQuery(User $user): Builder
    {
        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($user->hasRoles('hr_region') && ! $user->hasRoles('super_admin', 'hr_headoffice') && ! $employee?->region_id) {
            return Employee::query()
                ->visibleInErp()
                ->whereRaw('1 = 0');
        }

        $query = clone $this->directory->queryFor($user);
        $query->setEagerLoads([]);

        return $query;
    }

    protected function currentLeaveQuery(array $employeeIds): Builder
    {
        return LeaveRequest::query()
            ->whereIn('requester_id', $employeeIds ?: [0])
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today());
    }

    protected function periodLeaveQuery(array $employeeIds, Carbon $from, Carbon $to): Builder
    {
        return LeaveRequest::query()
            ->whereIn('requester_id', $employeeIds ?: [0])
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString());
    }

    protected function statCards(Collection $activeEmployees, Collection $currentLeaves, Collection $periodLeaves): array
    {
        $total = $activeEmployees->count();
        $genderCounts = $activeEmployees
            ->groupBy(fn (Employee $employee) => $this->normalizeGender($employee->gender))
            ->map->count();
        $approvedLeaves = $periodLeaves->where('leave_status', 'Approved');
        $pendingLeaves = $periodLeaves->filter(fn (LeaveRequest $request) => str_contains(strtolower((string) $request->leave_status), 'pending'));
        $districtCount = $activeEmployees->pluck('district_id')->filter()->unique()->count();

        return [
            [
                'label' => 'Total Staff',
                'value' => number_format($total),
                'badge' => $districtCount.' districts',
                'tone' => 'blue',
            ],
            [
                'label' => 'Female Staff',
                'value' => number_format((int) ($genderCounts['Female'] ?? 0)),
                'badge' => $this->percent((int) ($genderCounts['Female'] ?? 0), $total).' of staff',
                'tone' => 'green',
            ],
            [
                'label' => 'Male Staff',
                'value' => number_format((int) ($genderCounts['Male'] ?? 0)),
                'badge' => $this->percent((int) ($genderCounts['Male'] ?? 0), $total).' of staff',
                'tone' => 'blue',
            ],
            [
                'label' => 'On Leave Now',
                'value' => number_format($currentLeaves->pluck('requester_id')->unique()->count()),
                'badge' => $this->percent($currentLeaves->pluck('requester_id')->unique()->count(), $total).' of staff',
                'tone' => 'amber',
            ],
            [
                'label' => 'Pending Requests',
                'value' => number_format($pendingLeaves->count()),
                'badge' => number_format($approvedLeaves->sum('total_days_applied')).' approved days',
                'tone' => $pendingLeaves->isNotEmpty() ? 'red' : 'green',
            ],
        ];
    }

    protected function genderDistribution(Collection $employees): array
    {
        $counts = $employees
            ->groupBy(fn (Employee $employee) => $this->normalizeGender($employee->gender))
            ->map->count();

        $labels = ['Female', 'Male', 'Other / Unspecified'];

        return [
            'labels' => $labels,
            'data' => collect($labels)->map(fn (string $label) => (int) ($counts[$label] ?? 0))->all(),
        ];
    }

    protected function staffByDistrict(Collection $employees, Collection $currentLeaveEmployeeIds): array
    {
        $rows = collect($this->districtRows($employees, $currentLeaveEmployeeIds))
            ->sortByDesc('total')
            ->take(10)
            ->values();

        return [
            'labels' => $rows->pluck('district')->all(),
            'staff' => $rows->pluck('total')->all(),
            'onLeave' => $rows->pluck('on_leave')->all(),
            'rows' => $rows->all(),
        ];
    }

    protected function staffByRegion(Collection $employees): array
    {
        $rows = $employees
            ->groupBy(fn (Employee $employee) => $employee->region?->region_name ?: 'Unassigned')
            ->map(fn (Collection $items, string $region) => [
                'region' => $region,
                'count' => $items->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'labels' => $rows->pluck('region')->all(),
            'data' => $rows->pluck('count')->all(),
            'rows' => $rows->all(),
        ];
    }

    protected function staffByDepartment(Collection $employees): array
    {
        $rows = $employees
            ->groupBy(fn (Employee $employee) => $employee->department?->department_name ?: 'Unassigned')
            ->map(fn (Collection $items, string $department) => [
                'department' => $department,
                'count' => $items->count(),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values();

        return [
            'labels' => $rows->pluck('department')->all(),
            'data' => $rows->pluck('count')->all(),
            'rows' => $rows->all(),
        ];
    }

    protected function staffByCategory(Collection $employees): array
    {
        $rows = $employees
            ->groupBy(fn (Employee $employee) => $employee->category ?: 'Unassigned')
            ->map(fn (Collection $items, string $category) => [
                'category' => $category,
                'count' => $items->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'labels' => $rows->pluck('category')->all(),
            'data' => $rows->pluck('count')->all(),
            'rows' => $rows->all(),
        ];
    }

    protected function leaveStatusBreakdown(Collection $periodLeaves): array
    {
        $rows = $periodLeaves
            ->groupBy(fn (LeaveRequest $request) => $request->leave_status ?: 'Unknown')
            ->map(fn (Collection $items, string $status) => [
                'status' => $status,
                'count' => $items->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'labels' => $rows->pluck('status')->all(),
            'data' => $rows->pluck('count')->all(),
            'rows' => $rows->all(),
        ];
    }

    protected function leaveTypeDays(Collection $periodLeaves): array
    {
        $rows = $periodLeaves
            ->where('leave_status', 'Approved')
            ->groupBy(fn (LeaveRequest $request) => $request->leave_type ?: 'Unknown')
            ->map(fn (Collection $items, string $type) => [
                'type' => $type,
                'days' => round((float) $items->sum('total_days_applied'), 1),
            ])
            ->sortByDesc('days')
            ->values();

        return [
            'labels' => $rows->pluck('type')->all(),
            'data' => $rows->pluck('days')->all(),
            'rows' => $rows->all(),
        ];
    }

    protected function monthlyLeaveRequests(Collection $periodLeaves, Carbon $from, Carbon $to): array
    {
        $months = collect(CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to->copy()->startOfMonth()))
            ->map(fn (Carbon $month) => [
                'key' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
            ])
            ->values();

        $counts = $periodLeaves
            ->groupBy(fn (LeaveRequest $request) => $request->start_date?->format('Y-m'))
            ->map->count();

        return [
            'labels' => $months->pluck('label')->all(),
            'data' => $months->map(fn (array $month) => (int) ($counts[$month['key']] ?? 0))->all(),
        ];
    }

    /**
     * A plain array, not a Collection: this lands in the cached payload, and cached values are
     * unserialised without classes (config/cache.php serializable_classes), so any object in
     * there comes back as __PHP_Incomplete_Class.
     */
    protected function districtRows(Collection $employees, Collection $currentLeaveEmployeeIds): array
    {
        return $employees
            ->groupBy(fn (Employee $employee) => $employee->district_id ?: 'unassigned')
            ->map(function (Collection $items) use ($currentLeaveEmployeeIds) {
                $first = $items->first();
                $male = $items->filter(fn (Employee $employee) => $this->normalizeGender($employee->gender) === 'Male')->count();
                $female = $items->filter(fn (Employee $employee) => $this->normalizeGender($employee->gender) === 'Female')->count();

                return [
                    'district' => $first?->district?->district_name ?: 'Unassigned',
                    'region' => $first?->region?->region_name ?: $first?->district?->region?->region_name ?: 'Unassigned',
                    'total' => $items->count(),
                    'male' => $male,
                    'female' => $female,
                    'on_leave' => $items->whereIn('id', $currentLeaveEmployeeIds)->count(),
                ];
            })
            ->sortBy('district')
            ->values()
            ->all();
    }

    protected function currentlyOnLeave(Collection $currentLeaves): array
    {
        return $currentLeaves
            ->sortBy('end_date')
            ->take(8)
            ->map(fn (LeaveRequest $request) => [
                'employee' => $request->requester?->full_name ?: 'Unknown employee',
                'leave_type' => $request->leave_type ?: 'Leave',
                'district' => $request->requester?->district?->district_name ?: 'Unassigned',
                'region' => $request->requester?->region?->region_name ?: 'Unassigned',
                'start_date' => $request->start_date?->toDateString(),
                'end_date' => $request->end_date?->toDateString(),
                'days_remaining' => $request->end_date ? max(0, today()->diffInDays($request->end_date, false)) : 0,
            ])
            ->values()
            ->all();
    }

    protected function scopeLabel(User $user, ?int $regionId, ?int $districtId): string
    {
        if ($districtId) {
            return District::query()->find($districtId)?->district_name ?? 'Selected district';
        }

        if ($regionId) {
            return Region::query()->find($regionId)?->region_name ?? 'Selected region';
        }

        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($user->hasRoles('hr_region') && $employee?->region) {
            return $employee->region->region_name;
        }

        if ($user->hasRoles('super_admin', 'hr_headoffice')) {
            return 'All staff locations';
        }

        return 'Visible staff scope';
    }

    protected function scopeSignature(User $user): string
    {
        $employee = $user->employee ?? $user->employeeByStaffId;

        return implode(':', [
            $user->roles()->pluck('name')->sort()->join(','),
            $employee?->region_id ?: 'all',
            $employee?->district_id ?: 'all',
            $employee?->department_id ?: 'all',
            $employee?->unit ?: 'all',
        ]);
    }

    protected function normalizeGender(?string $gender): string
    {
        $value = strtolower(trim((string) $gender));

        return match ($value) {
            'female', 'f' => 'Female',
            'male', 'm' => 'Male',
            default => 'Other / Unspecified',
        };
    }

    protected function percent(int $value, int $total): string
    {
        if ($total < 1) {
            return '0%';
        }

        return round(($value / $total) * 100).'%';
    }

    protected function remember(string $method, array $params, Closure $callback): array
    {
        $normalized = collect($params)
            ->map(fn ($value) => $value instanceof Carbon ? $value->toDateString() : $value)
            ->all();

        return Cache::remember(
            'staff_leave_reports:'.$method.':'.md5(json_encode($normalized)),
            now()->addMinutes(5),
            $callback
        );
    }
}
