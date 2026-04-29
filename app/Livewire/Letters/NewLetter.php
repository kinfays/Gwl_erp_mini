<?php

namespace App\Livewire\Letters;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\Region;
use App\Services\Letters\LetterWorkflowService;
use Livewire\Component;

class NewLetter extends Component
{
    use EnforcesModuleAccess;

    public string $subject = '';
    public string $ref_no = '';
    public string $type = 'Internal';
    public int|string $memo_sender_id = '';
    public string $company_sender = '';
    public string $date_on_letter = '';
    public int|string $region_id = '';
    public string $senderSearch = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('letters');

        $employee = $this->employee();
        $this->date_on_letter = today()->toDateString();
        $this->region_id = $employee?->region_id ?: '';
    }

    public function save(LetterWorkflowService $workflow)
    {
        $employee = $this->employee();

        abort_if(! $employee, 403, 'Your user account is not linked to an employee record.');
        abort_if(! $this->canCreate(), 403, 'You do not have permission to create letters.');

        $validated = $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'ref_no' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:Internal,External'],
            'memo_sender_id' => ['required_if:type,Internal', 'nullable', 'exists:employees,id'],
            'company_sender' => ['required_if:type,External', 'nullable', 'string', 'max:500'],
            'date_on_letter' => ['required', 'date'],
            'region_id' => ['required', 'exists:regions,id'],
        ]);

        $letter = $workflow->create($employee, $validated);

        session()->flash('success', 'Letter created successfully.');

        return redirect()->route('letters.active', ['letter' => $letter->id]);
    }

    public function render()
    {
        return view('livewire.letters.new-letter', [
            'regions' => Region::query()->orderBy('region_name')->get(),
            'senders' => Employee::query()
                ->visibleInErp()
                ->active()
                ->when($this->senderSearch, function ($query) {
                    $query->where(function ($searchQuery) {
                        $searchQuery
                            ->where('full_name', 'like', '%' . $this->senderSearch . '%')
                            ->orWhere('staff_id', 'like', '%' . $this->senderSearch . '%');
                    });
                })
                ->orderBy('full_name')
                ->limit(30)
                ->get(),
            'missingEmployee' => ! $this->employee(),
            'canCreate' => $this->canCreate(),
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }

    protected function canCreate(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('letters.create'));
    }
}
