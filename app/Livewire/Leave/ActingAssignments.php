<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveActingAssignment;
use App\Models\Region;
use App\Models\User;
use App\Services\Leave\LeaveActingAssignmentService;
use App\Services\Leave\LeaveApprovalChainResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/**
 * Acting assignments for the final-approver posts. Head Office HR and Global Admin set any, regional HR only a regional chief
 * manager for their own region, super_admin everywhere (see LeaveActingAssignmentService).
 */
class ActingAssignments extends Component
{
    use EnforcesModuleAccess;

    public string $userId = '';

    public string $role = 'regional_chief_manager';

    public string $regionId = '';

    public string $departmentId = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public function mount(LeaveActingAssignmentService $acting): void
    {
        $this->enforceLivewireModule('leave');
        abort_unless($acting->canManage($this->user()), 403);

        $this->startsOn = today()->toDateString();
        $this->endsOn = today()->addDays(13)->toDateString();
        $this->lockToOwnRegion();
    }

    public function save(LeaveActingAssignmentService $acting): void
    {
        $this->validate([
            'userId' => ['required', 'integer'],
            'role' => ['required', 'in:'.implode(',', LeaveActingAssignmentService::ROLES)],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
        ], ['userId.required' => 'Choose who is acting.', 'endsOn.after_or_equal' => 'The assignment must end on or after the day it starts.']);

        try {
            $acting->save($this->user(), [
                'user_id' => (int) $this->userId,
                'acting_for_role' => $this->role,
                'region_id' => $this->regionId !== '' ? (int) $this->regionId : null,
                'department_id' => $this->departmentId !== '' ? (int) $this->departmentId : null,
                'starts_on' => $this->startsOn,
                'ends_on' => $this->endsOn,
            ]);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->userId = '';
        $this->notify('Acting assignment saved.');
    }

    public function toggle(int $id, LeaveActingAssignmentService $acting): void
    {
        $assignment = LeaveActingAssignment::query()->findOrFail($id);

        try {
            $acting->setActive($this->user(), $assignment, ! $assignment->is_active);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->notify($assignment->fresh()->is_active ? 'Assignment switched on.' : 'Assignment switched off.');
    }

    public function delete(int $id, LeaveActingAssignmentService $acting): void
    {
        try {
            $acting->delete($this->user(), LeaveActingAssignment::query()->findOrFail($id));
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->notify('Assignment deleted.');
    }

    public function render(LeaveActingAssignmentService $acting, LeaveApprovalChainResolver $chain)
    {
        $user = $this->user();
        $regionLimit = $this->ownRegionOnly();

        $assignments = LeaveActingAssignment::query()
            ->with(['user', 'region', 'department'])
            ->when($regionLimit !== null, fn ($q) => $q->where('region_id', $regionLimit)->where('acting_for_role', 'regional_chief_manager'))
            ->orderByDesc('starts_on')
            ->get();

        return view('livewire.leave.acting-assignments', [
            'assignments' => $assignments,
            'roles' => collect(LeaveActingAssignmentService::ROLES)->mapWithKeys(fn (string $role) => [$role => $chain->roleLabel($role)])->all(),
            'regionOptions' => $regionLimit !== null ? Region::query()->whereKey($regionLimit)->get() : Region::query()->orderBy('region_name')->get(),
            'departments' => Department::query()->orderBy('department_name')->get(),
            'people' => User::query()->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name', 'staff_id']),
            'ownRegionOnly' => $regionLimit !== null,
            'canManage' => fn (LeaveActingAssignment $assignment) => $acting->canManageAssignment($user, $assignment),
        ]);
    }

    /** Regional HR may only cover their own region; null for everyone who may cover any. */
    protected function ownRegionOnly(): ?int
    {
        $user = $this->user();

        if ($user->hasRoles('super_admin', 'admin', 'hr_headoffice')) {
            return null;
        }

        return (int) (($user->employee ?? $user->employeeByStaffId)?->region_id) ?: 0;
    }

    protected function lockToOwnRegion(): void
    {
        if (($region = $this->ownRegionOnly()) !== null) {
            $this->regionId = (string) $region;
            $this->role = 'regional_chief_manager';
        }
    }

    protected function notify(string $message): void
    {
        session()->flash('success', $message);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user, 403);

        return $user;
    }
}
