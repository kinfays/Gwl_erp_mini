<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveType;
use App\Enums\LocationType;
use App\Models\CompulsoryLeaveDeduction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Services\Leave\LeaveEntitlementResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class CompulsoryLeave extends Component
{
    public int $year;
    public int $deductionDays;
    public array $categories = [];
    public ?string $excludeLocationType = LocationType::District->value;
    public ?string $notes = null;

    public bool $confirmOverride = false;

    public function mount()
    {
        $this->year = now()->year;
    }

    protected function rules(): array
    {
        return [
            'year' => ['required', 'integer'],
            'deductionDays' => ['required', 'integer', 'min:1', 'max:31'],
            'categories' => ['required', 'array'],
            'excludeLocationType' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'confirmOverride' => ['boolean'],
        ];
    }

    /* ===================== Helpers ===================== */

    public function existingDeduction()
    {
        return CompulsoryLeaveDeduction::where('year', $this->year)->exists();
    }

    public function getAffectedStaffCountProperty(): int
    {
        return $this->staffQuery()->count();
    }

    protected function staffQuery()
    {
        return Employee::query()
            ->whereIn('category', $this->categories)
            ->when($this->excludeLocationType === LocationType::District->value,
                fn ($q) => $q->whereNull('district_id')
            )
            ->when($this->excludeLocationType === LocationType::Region->value,
                fn ($q) => $q->whereNull('region_id')
            );
    }

    /* ===================== Action ===================== */

    public function apply()
    {
        $this->validate();

        if ($this->existingDeduction() && ! $this->confirmOverride) {
            $this->addError(
                'year',
                'A compulsory leave deduction already exists for this year. Please confirm override.'
            );
            return;
        }

        DB::transaction(function () {

            $resolver = app(LeaveEntitlementResolver::class);

            $employees = $this->staffQuery()->get();

            foreach ($employees as $employee) {

                $entitlement = $resolver->resolve(
                    $employee,
                    LeaveType::Annual,
                    $this->year
                );

                $balance = LeaveBalance::firstOrCreate(
                    [
                        'staff_id' => $employee->id,
                        'leave_type' => LeaveType::Annual,
                        'current_year' => $this->year,
                    ],
                    [
                        'entitle_days' => $entitlement['entitle_days'],
                        'carry_over_days' => $entitlement['carry_over_days'],
                        'carry_over_expired_date' =>
                            $entitlement['carry_over_expired_date'],
                        'used_days' => 0,
                        'remaining_days' =>
                            $entitlement['total_available_days'],
                        'region_id' => $employee->region_id,
                        'district_id' => $employee->district_id,
                    ]
                );

                // Deduct (never below zero)
                $balance->used_days += $this->deductionDays;
                $balance->remaining_days = max(
                    0,
                    $balance->remaining_days - $this->deductionDays
                );

                $balance->save();
            }

            CompulsoryLeaveDeduction::create([
                'year' => $this->year,
                'deduction_days' => $this->deductionDays,
                'applied_by_id' => Auth::user()->employee->id,
                'applies_to_categories' => $this->categories,
                'excludes_location_type' => $this->excludeLocationType,
                'notes' => $this->notes,
                'applied_at' => now(),
            ]);
        });

        session()->flash(
            'success',
            "Compulsory leave of {$this->deductionDays} days applied successfully."
        );

        return redirect()->route('leave.compulsory');
    }

    public function render()
    {
        return view('livewire.leave.compulsory-leave', [
            'history' => CompulsoryLeaveDeduction::latest()->get(),
        ]);
    }
}