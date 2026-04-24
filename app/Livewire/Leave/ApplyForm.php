<?php

namespace App\Livewire\Leave;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\Employee;
use App\Services\Leave\LeaveWorkflowService;
use App\Services\Leave\LeaveBalanceService;

class ApplyForm extends Component
{
    use WithFileUploads;

    public ?int $editId = null;
    public string $leave_type = 'Annual';
    public string $start_date = '';
    public string $end_date = '';
    public string $leave_details = '';
    public $file_attachment;

    public int $working_days = 0;
    public array $balances = [];

    public function mount(LeaveBalanceService $balanceService): void
{
    $this->editId = request()->integer('edit') ?: null;

    if ($this->editId) {
        $req = \App\Models\LeaveRequest::query()
            ->where('requester_id', auth()->user()->employee->id)
            ->findOrFail($this->editId);

        if (! in_array($req->leave_status, ['Planned','Pending Approval'], true)) {
            abort(403, 'This request cannot be edited.');
        }

        $this->leave_type = $req->leave_type;
        $this->start_date = $req->start_date->toDateString();
        $this->end_date = $req->end_date->toDateString();
        $this->leave_details = $req->leave_details ?? '';
    }

    $this->refreshBalances($balanceService);
}


    public function updated($field, LeaveBalanceService $balanceService, LeaveWorkflowService $workflow)
    {
        if (in_array($field, ['leave_type', 'start_date', 'end_date'], true)) {
            $this->recalcWorkingDays($workflow);
            $this->refreshBalances($balanceService);
        }
    }

    protected function requester(): Employee
    {
        return auth()->user()->employee;
    }

    protected function recalcWorkingDays(LeaveWorkflowService $workflow): void
    {
        if (!$this->start_date || !$this->end_date) {
            $this->working_days = 0;
            return;
        }

        // workflow uses calculator internally when creating request, but UI needs preview.
        // We'll calculate by creating a temp calculation directly:
        $calc = app(\App\Services\Leave\WorkingDaysCalculator::class);
        $this->working_days = $calc->workingDays(
            \Carbon\Carbon::parse($this->start_date),
            \Carbon\Carbon::parse($this->end_date)
        );
    }

    protected function refreshBalances(LeaveBalanceService $balanceService): void
    {
        $emp = $this->requester();
        $year = (int) now()->format('Y');

        $types = ['Annual','Casual','Paternity','Maternity','Sick'];

        $this->balances = [];
        foreach ($types as $t) {
            $this->balances[$t] = $balanceService->getVirtualRemaining($emp, $t, $year);
        }
    }

  public function savePlanned(LeaveWorkflowService $workflow)
{
    $this->validate([
        'leave_type'  => 'required',
        'start_date'  => 'required|date',
        'end_date'    => 'required|date|after_or_equal:start_date',
    ]);

    $path = $this->file_attachment
        ? $this->file_attachment->store('leave_attachments', 'public')
        : null;

    // ✅ EDIT MODE: update existing request
    if ($this->editId) {
        $req = \App\Models\LeaveRequest::query()
            ->where('requester_id', $this->requester()->id)
            ->findOrFail($this->editId);

        if (! in_array($req->leave_status, ['Planned', 'Pending Approval'], true)) {
            abort(403, 'This request cannot be edited.');
        }

        // Recalculate working days
        $calc = app(\App\Services\Leave\WorkingDaysCalculator::class);
        $days = $calc->workingDays(
            \Carbon\Carbon::parse($this->start_date),
            \Carbon\Carbon::parse($this->end_date),
        );

        $req->update([
            'leave_type'          => $this->leave_type,
            'start_date'          => $this->start_date,
            'end_date'            => $this->end_date,
            'total_days_applied'  => $days,
            'leave_details'       => $this->leave_details,
            'leave_status'        => 'Planned',
            'file_attachment'     => $path ?? $req->file_attachment,
        ]);

        session()->flash('success', 'Planned leave request updated.');
        return redirect()->route('leave.my-history');
    }

    // ✅ CREATE MODE: original behavior
    $workflow->savePlanned($this->requester(), [
        'leave_type'      => $this->leave_type,
        'start_date'      => $this->start_date,
        'end_date'        => $this->end_date,
        'leave_details'   => $this->leave_details,
        'file_attachment' => $path,
    ]);

    session()->flash('success', 'Saved as planned.');
    return redirect()->route('leave.my-history');
}


    public function submit(LeaveWorkflowService $workflow)
{
    $this->validate([
        'leave_type' => 'required',
        'start_date' => 'required|date',
        'end_date'   => 'required|date|after_or_equal:start_date',
    ]);

    $path = $this->file_attachment
        ? $this->file_attachment->store('leave_attachments', 'public')
        : null;

    // ✅ EDIT MODE → update then submit
    if ($this->editId) {
        $req = \App\Models\LeaveRequest::query()
            ->where('requester_id', $this->requester()->id)
            ->findOrFail($this->editId);

        if (! in_array($req->leave_status, ['Planned', 'Pending Approval'], true)) {
            abort(403, 'This request cannot be submitted.');
        }

        // Enforce Casual restriction via workflow service
        try {
            // Update fields first
            $calc = app(\App\Services\Leave\WorkingDaysCalculator::class);
            $days = $calc->workingDays(
                \Carbon\Carbon::parse($this->start_date),
                \Carbon\Carbon::parse($this->end_date),
            );

            $req->update([
                'leave_type'         => $this->leave_type,
                'start_date'         => $this->start_date,
                'end_date'           => $this->end_date,
                'total_days_applied' => $days,
                'leave_details'      => $this->leave_details,
                'file_attachment'    => $path ?? $req->file_attachment,
            ]);

            // Transition to Pending Approval using workflow rules
            $workflow->submit($this->requester(), [
                'leave_type'      => $req->leave_type,
                'start_date'      => $req->start_date,
                'end_date'        => $req->end_date,
                'leave_details'   => $req->leave_details,
                'file_attachment' => $req->file_attachment,
            ]);
        } catch (\RuntimeException $e) {
            $this->addError('leave_type', $e->getMessage());
            return;
        }

        session()->flash('success', 'Leave request updated and submitted.');
        return redirect()->route('leave.my-history');
    }

    // ✅ CREATE MODE → original behavior
    try {
        $workflow->submit($this->requester(), [
            'leave_type'      => $this->leave_type,
            'start_date'      => $this->start_date,
            'end_date'        => $this->end_date,
            'leave_details'   => $this->leave_details,
            'file_attachment' => $path,
        ]);
    } catch (\RuntimeException $e) {
        $this->addError('leave_type', $e->getMessage());
        return;
    }

    session()->flash('success', 'Leave request submitted for approval.');
    return redirect()->route('leave.my-history');
}

    public function render()
    {
        return view('livewire.leave.apply-form');
    }
}