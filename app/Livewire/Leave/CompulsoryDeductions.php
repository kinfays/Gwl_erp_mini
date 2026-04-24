<?php

namespace App\Livewire\Leave;

use Livewire\Component;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\CompulsoryLeaveDeduction;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class CompulsoryDeductions extends Component
{
    use EnforcesModuleAccess;

    public int $year;
    public int $deductionDays = 0;
    public array $categories = [];
    public ?string $excludeLocationType = 'District';
    public string $notes = '';
    public int $affectedCount = 0;
    public bool $confirmOverride = false;

    protected array $availableCategories = [
        'Junior Staff',
        'Senior Staff',
        'Management',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('leave');

        if (! auth()->user()->hasPermission('leave.manage_compulsory')) {
            abort(403);
        }

        $this->year = (int) now()->format('Y');
        $this->recalculateAffected();
    }

    public function updated($field): void
    {
        if (in_array($field, ['year','categories','excludeLocationType'], true)) {
            $this->recalculateAffected();
        }
    }

    protected function recalculateAffected(): void
    {
        $query = Employee::query()->whereIn('category', $this->categories ?: []);

        if ($this->excludeLocationType) {
            $query->where('location_type', '!=', $this->excludeLocationType);
        }

        $this->affectedCount = $query->count();
    }

    public function apply(LeaveBalanceService $balances): void
    {
        $this->validate([
            'year' => 'required|integer',
            'deductionDays' => 'required|integer|min:1|max:31',
            'categories' => 'required|array|min:1',
        ]);

        $existing = CompulsoryLeaveDeduction::where('year', $this->year)->first();
        if ($existing && ! $this->confirmOverride) {
            $this->addError('confirmOverride', 'A deduction for this year already exists. Confirm override.');
            return;
        }

        DB::transaction(function () use ($balances) {
            $deduction = CompulsoryLeaveDeduction::create([
                'year' => $this->year,
                'deduction_days' => $this->deductionDays,
                'applied_by_id' => auth()->user()->employee->id,
                'applies_to_categories' => $this->categories,
                'excludes_location_type' => $this->excludeLocationType,
                'notes' => $this->notes,
                'applied_at' => now(),
            ]);

            $employees = Employee::query()
                ->whereIn('category', $this->categories)
                ->when($this->excludeLocationType, fn ($q) =>
                    $q->where('location_type','!=',$this->excludeLocationType)
                )
                ->get();

            foreach ($employees as $emp) {
                $balance = $balances->getOrCreateForApproval($emp, 'Annual', $this->year);
                $balances->deduct($balance, $this->deductionDays);
            }

            Audit::log(
                action: 'leave_compulsory_deduction',
                module: 'leave',
                targetType: 'year',
                targetId: $this->year,
                metadata: [
                    'days' => $this->deductionDays,
                    'categories' => $this->categories,
                    'excluded_location' => $this->excludeLocationType,
                    'affected' => $employees->count(),
                ]
            );
        });

        session()->flash('success', 'Compulsory leave deduction applied successfully.');
        $this->reset(['deductionDays','categories','notes','confirmOverride']);
        $this->recalculateAffected();
    }

    public function render()
    {
        return view('livewire.leave.compulsory-deductions', [
            'availableCategories' => $this->availableCategories,
        ]);
    }
}