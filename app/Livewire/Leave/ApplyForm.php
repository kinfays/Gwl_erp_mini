<?php

namespace App\Livewire\Leave;

use App\Models\CompulsoryLeaveDeduction;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveWorkflowService;
use App\Services\Leave\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithFileUploads;

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

    public array $compulsoryRanges = [];

    public function mount(LeaveBalanceService $balanceService): void
    {
        $this->editId = request()->integer('edit') ?: null;

        if ($this->editId) {
            $req = LeaveRequest::query()
                ->where('requester_id', $this->requester()->id)
                ->findOrFail($this->editId);

            if (! in_array($req->leave_status, ['Planned', 'Pending Approval'], true)) {
                abort(403, 'This request cannot be edited.');
            }

            $this->leave_type = $req->leave_type;
            $this->start_date = $req->start_date->toDateString();
            $this->end_date = $req->end_date->toDateString();
            $this->leave_details = $req->leave_details ?? '';
        } else {
            $this->start_date = today()->toDateString();
            $this->end_date = today()->toDateString();
        }

        $this->refreshBalances($balanceService);
        $this->refreshCompulsoryRanges();
        $this->working_days = $this->calculateWorkingDays();
    }

    public function updated($field, LeaveBalanceService $balanceService, LeaveWorkflowService $workflow): void
    {
        if ($field === 'start_date' && $this->start_date && (! $this->end_date || $this->end_date < $this->start_date)) {
            $this->end_date = $this->start_date;
        }

        if (in_array($field, ['leave_type', 'start_date', 'end_date'], true)) {
            $this->recalcWorkingDays($workflow);
            $this->refreshBalances($balanceService);
            $this->checkCompulsoryOverlap();
        }
    }

    public function savePlanned(LeaveWorkflowService $workflow)
    {
        $this->validateRequestDates();

        if ($this->blockIfCompulsoryOverlap()) {
            return;
        }

        $path = $this->file_attachment
            ? $this->file_attachment->store('leave_attachments', 'public')
            : null;

        if ($this->editId) {
            $req = LeaveRequest::query()
                ->where('requester_id', $this->requester()->id)
                ->findOrFail($this->editId);

            if (! in_array($req->leave_status, ['Planned', 'Pending Approval'], true)) {
                abort(403, 'This request cannot be edited.');
            }

            $req->update([
                'leave_type' => $this->leave_type,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'total_days_applied' => $this->calculateWorkingDays(),
                'leave_details' => $this->leave_details,
                'leave_status' => 'Planned',
                'file_attachment' => $path ?? $req->file_attachment,
            ]);

            session()->flash('success', 'Planned leave request updated.');

            return redirect()->route('leave.my-history');
        }

        $workflow->savePlanned($this->requester(), [
            'leave_type' => $this->leave_type,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'leave_details' => $this->leave_details,
            'file_attachment' => $path,
        ]);

        session()->flash('success', 'Saved as planned.');

        return redirect()->route('leave.my-history');
    }

    public function submit(LeaveWorkflowService $workflow)
    {
        $this->validateRequestDates();

        if ($this->blockIfCompulsoryOverlap()) {
            return;
        }

        $path = $this->file_attachment
            ? $this->file_attachment->store('leave_attachments', 'public')
            : null;

        if ($this->editId) {
            $req = LeaveRequest::query()
                ->where('requester_id', $this->requester()->id)
                ->findOrFail($this->editId);

            if (! in_array($req->leave_status, ['Planned', 'Pending Approval'], true)) {
                abort(403, 'This request cannot be submitted.');
            }

            try {
                $req->update([
                    'leave_type' => $this->leave_type,
                    'start_date' => $this->start_date,
                    'end_date' => $this->end_date,
                    'total_days_applied' => $this->calculateWorkingDays(),
                    'leave_details' => $this->leave_details,
                    'file_attachment' => $path ?? $req->file_attachment,
                ]);

                $workflow->submit($this->requester(), [
                    'leave_type' => $req->leave_type,
                    'start_date' => $req->start_date,
                    'end_date' => $req->end_date,
                    'leave_details' => $req->leave_details,
                    'file_attachment' => $req->file_attachment,
                ]);
            } catch (\RuntimeException $e) {
                $this->addError('leave_type', $e->getMessage());
                $this->dispatch('toast', type: 'error', message: $e->getMessage());

                return;
            }

            session()->flash('success', 'Leave request updated and submitted.');

            return redirect()->route('leave.my-history');
        }

        try {
            $workflow->submit($this->requester(), [
                'leave_type' => $this->leave_type,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'leave_details' => $this->leave_details,
                'file_attachment' => $path,
            ]);
        } catch (\RuntimeException $e) {
            $this->addError('leave_type', $e->getMessage());
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        session()->flash('success', 'Leave request submitted for approval.');

        return redirect()->route('leave.my-history');
    }

    public function render()
    {
        $this->refreshCompulsoryRanges();

        return view('livewire.leave.apply-form', [
            'compulsoryRanges' => $this->compulsoryRanges,
            'minDate' => today()->toDateString(),
            'minEndDate' => $this->start_date && $this->start_date > today()->toDateString()
                ? $this->start_date
                : today()->toDateString(),
        ]);
    }

    protected function requester(): Employee
    {
        return auth()->user()->employee ?? auth()->user()->employeeByStaffId;
    }

    protected function validateRequestDates(): void
    {
        $this->validate([
            'leave_type' => 'required',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
        ], [
            'start_date.after_or_equal' => 'The start date cannot be before today.',
            'end_date.after_or_equal' => 'The end date must be the same as or after the start date.',
        ]);
    }

    protected function recalcWorkingDays(LeaveWorkflowService $workflow): void
    {
        if (! $this->start_date || ! $this->end_date) {
            $this->working_days = 0;

            return;
        }

        $this->working_days = $this->calculateWorkingDays();
    }

    protected function calculateWorkingDays(): int
    {
        return app(WorkingDaysCalculator::class)->workingDays(
            Carbon::parse($this->start_date),
            Carbon::parse($this->end_date)
        );
    }

    protected function refreshBalances(LeaveBalanceService $balanceService): void
    {
        $employee = $this->requester();
        $year = (int) now()->format('Y');
        $types = ['Annual', 'Casual', 'Paternity', 'Maternity', 'Sick'];

        $this->balances = [];

        foreach ($types as $type) {
            $this->balances[$type] = $balanceService->getVirtualRemaining($employee, $type, $year);
        }
    }

    protected function refreshCompulsoryRanges(): void
    {
        $this->compulsoryRanges = $this->matchingCompulsoryDeductions()
            ->map(fn (CompulsoryLeaveDeduction $deduction) => [
                'start' => $deduction->start_date?->toDateString(),
                'end' => $deduction->end_date?->toDateString(),
                'label' => $deduction->start_date?->format('d M Y').' - '.$deduction->end_date?->format('d M Y'),
                'days' => (int) $deduction->deduction_days,
            ])
            ->values()
            ->all();
    }

    protected function matchingCompulsoryDeductions()
    {
        $employee = $this->requester();

        if (! Schema::hasTable('compulsory_leave_deductions')
            || ! Schema::hasColumn('compulsory_leave_deductions', 'start_date')
            || ! $employee?->category) {
            return collect();
        }

        return CompulsoryLeaveDeduction::query()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereJsonContains('applies_to_categories', $employee->category)
            ->where(function ($query) use ($employee) {
                $query->whereNull('excludes_location_type')
                    ->orWhere('excludes_location_type', '!=', $employee->location_type);
            })
            ->orderBy('start_date')
            ->get();
    }

    protected function blockIfCompulsoryOverlap(): bool
    {
        $this->refreshCompulsoryRanges();

        if (! $range = $this->selectedOverlappingCompulsoryRange()) {
            return false;
        }

        $this->addError('start_date', 'Selected leave dates overlap the compulsory leave window: '.$range['label'].'.');
        $this->dispatch('toast', type: 'error', message: 'Selected dates overlap compulsory leave.');

        return true;
    }

    protected function checkCompulsoryOverlap(): void
    {
        $this->resetErrorBag('start_date');

        if ($range = $this->selectedOverlappingCompulsoryRange()) {
            $this->addError('start_date', 'Selected leave dates overlap the compulsory leave window: '.$range['label'].'.');
        }
    }

    protected function selectedOverlappingCompulsoryRange(): ?array
    {
        if (! $this->start_date || ! $this->end_date) {
            return null;
        }

        try {
            $start = Carbon::parse($this->start_date)->startOfDay();
            $end = Carbon::parse($this->end_date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        foreach ($this->compulsoryRanges as $range) {
            if (! $range['start'] || ! $range['end']) {
                continue;
            }

            $rangeStart = Carbon::parse($range['start'])->startOfDay();
            $rangeEnd = Carbon::parse($range['end'])->startOfDay();

            if ($start->lte($rangeEnd) && $end->gte($rangeStart)) {
                return $range;
            }
        }

        return null;
    }
}
