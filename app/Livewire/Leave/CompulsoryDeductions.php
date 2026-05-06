<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CompulsoryLeaveDeduction;
use App\Models\Employee;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\WorkingDaysCalculator;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class CompulsoryDeductions extends Component
{
    use EnforcesModuleAccess;

    public int $year;

    public string $startDate = '';

    public string $endDate = '';

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

        $this->year = $this->currentLeaveYear();
        $this->syncRangeDefaults();
        $this->recalculateDeductionDays();
        $this->recalculateAffected();
    }

    public function updated($field): void
    {
        if ($field === 'year') {
            $this->year = $this->currentLeaveYear();
            $this->syncRangeDefaults();
            $this->recalculateDeductionDays();
        }

        if (in_array($field, ['startDate', 'endDate'], true)) {
            $this->recalculateDeductionDays();
        }

        if (in_array($field, ['year', 'categories', 'excludeLocationType'], true)) {
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

    protected function syncRangeDefaults(): void
    {
        $this->startDate = Carbon::create($this->year, 12, 1)->toDateString();
        $this->endDate = Carbon::create($this->year + 1, 1, 31)->toDateString();
    }

    protected function rangeStartLimit(): string
    {
        return Carbon::create($this->year, 12, 1)->toDateString();
    }

    protected function rangeEndLimit(): string
    {
        return Carbon::create($this->year + 1, 1, 31)->toDateString();
    }

    protected function currentLeaveYear(): int
    {
        return (int) now()->format('Y');
    }

    protected function recalculateDeductionDays(): void
    {
        if (! $this->startDate || ! $this->endDate) {
            $this->deductionDays = 0;

            return;
        }

        try {
            $this->deductionDays = app(WorkingDaysCalculator::class)->workingDays(
                Carbon::parse($this->startDate),
                Carbon::parse($this->endDate)
            );
        } catch (\Throwable) {
            $this->deductionDays = 0;
        }
    }

    public function apply(LeaveBalanceService $balances): void
    {
        $this->year = $this->currentLeaveYear();
        $this->recalculateDeductionDays();

        $this->validate([
            'year' => 'required|integer',
            'startDate' => 'required|date|after_or_equal:'.$this->rangeStartLimit().'|before_or_equal:'.$this->rangeEndLimit(),
            'endDate' => 'required|date|after_or_equal:startDate|before_or_equal:'.$this->rangeEndLimit(),
            'deductionDays' => 'required|integer|min:1|max:62',
            'categories' => 'required|array|min:1',
        ]);

        $existing = CompulsoryLeaveDeduction::where('year', $this->year)->first();
        if ($existing && ! $this->confirmOverride) {
            $this->addError('confirmOverride', 'A deduction for this year already exists. Confirm override.');
            $this->dispatch('toast', type: 'warning', message: 'Confirm override before applying this deduction.');

            return;
        }

        DB::transaction(function () use ($balances) {
            $deduction = CompulsoryLeaveDeduction::create([
                'year' => $this->year,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'deduction_days' => $this->deductionDays,
                'applied_by_id' => (auth()->user()->employee ?? auth()->user()->employeeByStaffId)->id,
                'applies_to_categories' => $this->categories,
                'excludes_location_type' => $this->excludeLocationType,
                'notes' => $this->notes,
                'applied_at' => now(),
            ]);

            $employees = Employee::query()
                ->whereIn('category', $this->categories)
                ->when($this->excludeLocationType, fn ($q) => $q->where('location_type', '!=', $this->excludeLocationType)
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
                    'start_date' => $this->startDate,
                    'end_date' => $this->endDate,
                    'categories' => $this->categories,
                    'excluded_location' => $this->excludeLocationType,
                    'affected' => $employees->count(),
                ]
            );
        });

        session()->flash('success', 'Compulsory leave deduction applied successfully.');
        $this->dispatch('toast', type: 'success', message: 'Compulsory leave deduction applied successfully.');
        $this->reset(['categories', 'notes', 'confirmOverride']);
        $this->syncRangeDefaults();
        $this->recalculateDeductionDays();
        $this->recalculateAffected();
    }

    public function render()
    {
        return view('livewire.leave.compulsory-deductions', [
            'availableCategories' => $this->availableCategories,
            'rangeStartLimit' => $this->rangeStartLimit(),
            'rangeEndLimit' => $this->rangeEndLimit(),
        ]);
    }
}
