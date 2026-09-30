<?php

namespace App\Livewire\Letters;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\Region;
use App\Services\Letters\LetterScanService;
use App\Services\Letters\LetterWorkflowService;
use Livewire\Component;
use Livewire\WithFileUploads;

class NewLetter extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;

    public string $subject = '';
    public string $ref_no = '';
    public string $type = 'Internal';
    public int|string $memo_sender_id = '';
    public string $company_sender = '';
    public string $date_on_letter = '';
    public int|string $region_id = '';

    /** Optional scans of the hardcopy, attached after the letter is saved (only with gwl.letters_scans_enabled). */
    public array $scans = [];

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

        $scanService = app(LetterScanService::class);

        $validated = $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'ref_no' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:Internal,External'],
            'memo_sender_id' => ['required_if:type,Internal', 'nullable', 'exists:employees,id'],
            'company_sender' => ['required_if:type,External', 'nullable', 'string', 'max:500'],
            'date_on_letter' => ['required', 'date'],
            'region_id' => ['required', 'exists:regions,id'],
            'scans' => ['array', 'max:'.max(1, (int) config('gwl.letters_scan_max_files', 10))],
            'scans.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.max(1, (int) config('gwl.letters_scan_max_kb', 10240))],
        ], [
            'scans.*.mimes' => 'Scans must be PDF, JPG or PNG files.',
            'scans.*.max' => 'A file is too large (the limit is '.round(max(1, (int) config('gwl.letters_scan_max_kb', 10240)) / 1024, 1).' MB each).',
        ]);

        $letter = $workflow->create($employee, $validated);

        // The letter is saved either way; a scan that cannot be attached is reported, not allowed to lose the letter.
        $skipped = [];

        if ($scanService->enabled()) {
            foreach ($this->scans as $file) {
                try {
                    $scanService->add($letter, $employee, $file, 'original');
                } catch (\RuntimeException $e) {
                    $skipped[] = $file->getClientOriginalName().': '.$e->getMessage();
                }
            }
        }

        session()->flash('success', 'Letter created successfully.'.($skipped !== [] ? ' Some scans were not attached - '.implode(' ', $skipped) : ''));

        return redirect()->route('letters.active', ['letter' => $letter->id]);
    }

    public function render()
    {
        return view('livewire.letters.new-letter', [
            'regions' => Region::query()->orderBy('region_name')->get(),
            'senderOptions' => $this->employeeOptions(Employee::query()
                ->with(['department', 'region'])
                ->active()
                ->visibleInErp()
                ->orderBy('full_name')
                ->get()),
            'missingEmployee' => ! $this->employee(),
            'canCreate' => $this->canCreate(),
            'scansEnabled' => app(LetterScanService::class)->enabled(),
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

    protected function employeeOptions($employees): array
    {
        return $employees
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->full_name,
                'description' => collect([
                    $employee->staff_id,
                    $employee->department?->department_name,
                    $employee->region?->region_name,
                ])->filter()->join(' / '),
            ])
            ->all();
    }
}
