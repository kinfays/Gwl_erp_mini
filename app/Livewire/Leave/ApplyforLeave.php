<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveApprovalEngine;
use App\Services\Leave\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Exports\Leave\LeaveRequestsExport;
use Maatwebsite\Excel\Facades\Excel;


class ApplyForLeave extends Component
{
    use WithFileUploads;

    /* ===================== Form Fields ===================== */

    public int $requesterId;
    public string $leaveType = '';
    public string $startDate = '';
    public string $endDate = '';
    public ?string $reason = null;
    public $attachment;

    public int $workingDays = 0;

    /* ===================== Boot ===================== */

    public function mount()
    {
        $this->requesterId = Auth::user()->employee->id;
    }

    /* ===================== Validation ===================== */

    protected function rules(): array
    {
        return [
            'requesterId' => ['required', 'exists:employees,id'],
            'leaveType' => ['required', Rule::enum(LeaveType::class)],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ];
    }

    /* ===================== Reactive Hooks ===================== */

    public function updated($property)
    {
        if (in_array($property, ['startDate', 'endDate'])) {
            $this->recalculateWorkingDays();
        }

        if ($property === 'leaveType') {
            $this->validateCasualRestriction();
        }
    }

    protected function recalculateWorkingDays(): void
    {
        if (! $this->startDate || ! $this->endDate) {
            $this->workingDays = 0;
            return;
        }

        $calculator = app(WorkingDaysCalculator::class);

        $this->workingDays = $calculator->calculate(
            Carbon::parse($this->startDate),
            Carbon::parse($this->endDate)
        );
    }

    protected function validateCasualRestriction(): void
    {
        if ($this->leaveType !== LeaveType::Casual->value) {
            return;
        }

        $annualBalance = LeaveBalance::where('staff_id', $this->requesterId)
            ->where('leave_type', LeaveType::Annual)
            ->where('current_year', now()->year)
            ->first();

        if ($annualBalance && $annualBalance->remaining_days > 0) {
            $this->addError(
                'leaveType',
                'Casual leave cannot be requested while Annual Leave balance exists.'
            );
        }
    }

    /* ===================== Actions ===================== */

    public function saveAsPlanned()
    {
        $this->save(LeaveStatus::Planned);
    }

    public function submit()
    {
        $this->save(LeaveStatus::PendingApproval);
    }

    protected function save(LeaveStatus $targetStatus)
    {
        $this->validate();

        if ($this->workingDays <= 0) {
            $this->addError('startDate', 'No working days selected.');
            return;
        }

        $employee = Employee::findOrFail($this->requesterId);

        $path = null;
        if ($this->attachment) {
            $path = $this->attachment->store('leave', 'public');
        }

        $request = LeaveRequest::create([
            'requester_id' => $employee->id,
            'leave_type' => $this->leaveType,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'total_days_applied' => $this->workingDays,
            'leave_details' => $this->reason,
            'leave_status' => LeaveStatus::Planned,
            'request_year' => Carbon::parse($this->startDate)->year,
            'department_id' => $employee->department_id,
            'region_id' => $employee->region_id,
            'file_attachment' => $path,
        ]);

        if ($targetStatus === LeaveStatus::PendingApproval) {
            app(LeaveApprovalEngine::class)->submit($request);
        }

        session()->flash('success', 'Leave request saved successfully.');

        return redirect()->route('leave.my-history');
    }

    /* ===================== Data for View ===================== */

    public function getBalancesProperty()
    {
        return LeaveBalance::where('staff_id', $this->requesterId)
            ->where('current_year', now()->year)
            ->get();
    }

    public function render()
    {
        return view('livewire.leave.apply-for-leave', [
            'leaveTypes' => LeaveType::cases(),
            'employees' => $this->availableRequesters(),
        ]);
    }

    protected function availableRequesters()
    {
        // Managers can apply for team members
        if (Auth::user()->can('leave.apply_for_team')) {
            return Employee::where('manager_id', Auth::user()->employee->id)->get();
        }

        return Employee::where('id', $this->requesterId)->get();
    }

    public function export()
{
    return Excel::download(
        new LeaveRequestsExport($this->requests),
        'leave_requests.xlsx'
    );
}
}
