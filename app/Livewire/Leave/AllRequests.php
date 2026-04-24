<?php

namespace App\Livewire\Leave;

use Livewire\Component;
use Livewire\WithPagination;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveApprovalChainResolver;

class AllRequests extends Component
{
    use WithPagination;
    use EnforcesModuleAccess;

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
        if (in_array($name, ['tab','search','leaveType','departmentId','dateFrom','dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
       /** @var User|null $user */
        $user = auth()->guard()->user();
        $actor = $user->employee;

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
        if ($user->isHrUser()) {
            if (! $user->isHeadOfficeHr()) {
                $base->where('region_id', $actor->region_id);
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
         * Managers/Chief: only approval chain items
         * - direct manager queue: manager_id = actor.id
         * - chief queue: actor must be resolved chief for requester
         */
        $resolver = app(LeaveApprovalChainResolver::class);

        $candidate = (clone $base)
            ->where(function ($q) use ($actor) {
                $q->where('manager_id', $actor->id)
                  ->orWhere(function ($qq) {
                      $qq->where('manager_recommendation', 'Recommended');
                  });
            })
            ->get();

        $allowedIds = $candidate->filter(function ($req) use ($actor, $resolver) {
            try {
                [$mgr, $chief] = $resolver->resolve($req->requester);

                if ($req->manager_id === $actor->id) {
                    return true;
                }

                return $chief->id === $actor->id;
            } catch (\Throwable $e) {
                return false;
            }
        })->pluck('id')->toArray();

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

}