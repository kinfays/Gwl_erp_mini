<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\Leave\AnnualEntitlementService;
use App\Services\Leave\LeaveBalanceService;
use Livewire\Component;

class EmployeeDashboard extends Component
{
    use EnforcesModuleAccess;

    public function mount(): void
    {
        $this->enforceLivewireModule('leave');
    }

    public function render()
    {
        $employee = $this->employee();

        abort_if(! $employee, 403, 'Employee profile is required for leave access.');

        return view('livewire.leave.employee-dashboard', [
            'employee' => $employee,
            'balanceCards' => $this->balanceCards($employee),
            'recentRequests' => $this->recentRequests($employee),
            'teamLeave' => $this->teamLeave($employee),
            'holidays' => Holiday::query()->upcoming()->limit(8)->get(),
        ]);
    }

    protected function balanceCards(Employee $employee): array
    {
        $year = (int) now()->format('Y');
        $balanceService = app(LeaveBalanceService::class);
        $isFemale = strcasecmp((string) $employee->gender, 'Female') === 0;
        $parentalType = $isFemale ? 'Maternity' : 'Paternity';
        $parentalDays = $isFemale ? 93 : 7;

        return collect([
            ['label' => 'Annual Leave', 'type' => 'Annual', 'entitled' => $employee->annual_leave_days],
            ['label' => 'Casual Leave', 'type' => 'Casual', 'entitled' => $employee->casual_leave_days],
            ['label' => 'Parental Leave', 'type' => $parentalType, 'entitled' => $parentalDays],
        ])->map(function (array $card) use ($employee, $year, $balanceService) {
            $used = (int) LeaveRequest::query()
                ->where('requester_id', $employee->id)
                ->where('leave_type', $card['type'])
                ->where('leave_status', 'Approved')
                ->where('request_year', $year)
                ->sum('total_days_applied');

            $available = $balanceService->getVirtualRemaining($employee, $card['type'], $year);
            $total = max((int) $card['entitled'], $used + $available);
            $usedPercent = $total > 0 ? min(100, (int) round(($used / $total) * 100)) : 0;

            return [
                'label' => $card['label'],
                'type' => $card['type'],
                'total' => $total,
                'available' => $available,
                'used' => $used,
                'used_percent' => $usedPercent,
                // Annual only: how the entitlement is made up (gross less the year's compulsory leave).
                'figures' => $card['type'] === 'Annual' ? app(AnnualEntitlementService::class)->figuresFor($employee, $year) : null,
            ];
        })->all();
    }

    protected function recentRequests(Employee $employee)
    {
        return LeaveRequest::query()
            ->where('requester_id', $employee->id)
            ->where('request_year', (int) now()->format('Y'))
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();
    }

    protected function teamLeave(Employee $employee)
    {
        $today = today();
        $hasTeamScope = $employee->department_id || $employee->unit || $employee->district_id || $employee->region_id;

        if (! $hasTeamScope) {
            return collect();
        }

        return LeaveRequest::query()
            ->with('requester')
            ->where('requester_id', '!=', $employee->id)
            ->whereIn('leave_status', ['Approved', 'Planned'])
            ->whereDate('end_date', '>=', $today)
            ->whereDate('start_date', '<=', $today->copy()->addDays(45))
            ->whereHas('requester', function ($query) use ($employee) {
                $query->when($employee->department_id, fn ($builder) => $builder->where('department_id', $employee->department_id));
                $query->when($employee->unit, fn ($builder) => $builder->where('unit', $employee->unit));
                $query->when($employee->district_id, fn ($builder) => $builder->where('district_id', $employee->district_id));
                $query->when(! $employee->district_id && $employee->region_id, fn ($builder) => $builder->where('region_id', $employee->region_id));
            })
            ->orderBy('start_date')
            ->limit(6)
            ->get();
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
