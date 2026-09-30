<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveApprovalChainResolver;
use Livewire\Component;
use Livewire\WithPagination;

class AllRequests extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $tab = 'pending'; // default: pending ✅

    public string $search = '';

    public string $leaveType = '';     // Annual/Casual/...

    public int|string $departmentId = ''; // department filter

    public string $dateFrom = '';

    public string $dateTo = '';

    public int $perPage = 15;

    public function mount(): void
    {
        // ✅ Livewire-level module enforcement (critical)
        $this->enforceLivewireModule('leave');
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function updating($name): void
    {
        if (in_array($name, ['tab', 'search', 'leaveType', 'departmentId', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        /** @var User|null $user */
        $user = auth()->guard()->user();
        $actor = $this->employee();

        $departments = Department::query()->orderBy('department_name')->get();

        // Base query
        $base = LeaveRequest::query()
            ->with(['requester', 'requester.region', 'requester.district', 'department'])
            ->when($this->search, function ($q) {
                $q->whereHas('requester', function ($qq) {
                    $qq->where('full_name', 'like', "%{$this->search}%")
                        ->orWhere('staff_id', 'like', "%{$this->search}%");
                });
            })
            ->when($this->leaveType, fn ($q) => $q->where('leave_type', $this->leaveType))
            ->when($this->departmentId !== '' && $this->departmentId !== 0, fn ($q) => $q->where('department_id', $this->departmentId))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('start_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('end_date', '<=', $this->dateTo));

        // Tabs
        if ($this->tab === 'pending') {
            $base->where('leave_status', 'Pending Approval');
        } elseif ($this->tab === 'approved') {
            $base->where('leave_status', 'Approved');
        } elseif ($this->tab === 'denied') {
            $base->where('leave_status', 'Denied');
        } else {
            // "all" tab: includes Planned + all others
        }

        /**
         * HR scoping (read-only): region-scoped, HO HR sees all
         */
        if ($user->hasRoles('super_admin', 'admin') || $user->isHrUser()) {
            if (! $user->isHeadOfficeHr()) {
                $base->when($user->isHrUser(), function ($query) use ($actor) {
                    abort_if(! $actor, 403, 'Employee profile is required for regional leave access.');

                    $query->where('region_id', $actor->region_id);
                });
            }

            $requests = $base->latest()->paginate($this->perPage)->withQueryString();

            return view('livewire.leave.all-requests', [
                'requests' => $requests,
                'departments' => $departments,
                'readOnly' => true,
                'tab' => $this->tab,
            ]);
        }

        /**
         * Managers/Chief/MD: only requests in their approval chain — ones routed to them, ones they have
         * decided, and pending ones they can act on (LeaveApprovalChainResolver::inChain()).
         */
        abort_if(! $actor, 403, 'Employee profile is required for leave approvals.');

        $resolver = app(LeaveApprovalChainResolver::class);

        $candidate = (clone $base)
            ->with(['requester.user.roles', 'requester.userByStaffId.roles'])
            ->where('requester_id', '!=', $actor->id)
            ->where(function ($q) use ($actor, $user) {
                $q->where('manager_id', $actor->id)
                    ->orWhere('approved_by_id', $actor->id)
                    ->orWhere('manager_user_id', $user->id)
                    ->orWhere('chief_user_id', $user->id)
                    ->orWhere('leave_status', 'Pending Approval');
            })
            ->get();

        $allowedIds = $resolver->scan(fn () => $candidate
            ->filter(fn (LeaveRequest $req) => $resolver->inChain($user, $actor, $req))
            ->pluck('id')
            ->toArray());

        $requests = LeaveRequest::query()
            ->with(['requester', 'requester.region', 'requester.district', 'department'])
            ->whereIn('id', $allowedIds)
            ->latest()
            ->paginate($this->perPage)
            ->withQueryString();

        return view('livewire.leave.all-requests', [
            'requests' => $requests,
            'departments' => $departments,
            'readOnly' => false,
            'tab' => $this->tab,
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = auth()->guard()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
