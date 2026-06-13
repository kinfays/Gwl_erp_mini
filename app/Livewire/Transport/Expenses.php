<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\Transport\TransportService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Expenses extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $receipt = null;

    public string $vehicleFilter = '';

    public string $typeFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public array $form = [
        'vehicle_id' => '',
        'expense_type' => 'fuel',
        'amount' => '',
        'currency' => 'GHS',
        'description' => '',
        'expense_date' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
        $this->form['expense_date'] = today()->toDateString();
    }

    public function save(TransportService $transport): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission('transport.manage_expenses'))) {
            abort(403);
        }

        $validated = $this->validate([
            'receipt' => ['nullable', 'image', 'max:4096'],
            'form.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'form.expense_type' => ['required', 'in:'.implode(',', VehicleExpense::TYPES)],
            'form.amount' => ['required', 'numeric', 'min:0.01'],
            'form.currency' => ['required', 'string', 'size:3'],
            'form.description' => ['nullable', 'string', 'max:5000'],
            'form.expense_date' => ['required', 'date'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($validated['form']['vehicle_id']);
        $payload = $validated['form'];
        unset($payload['vehicle_id']);

        $transport->recordExpense($vehicle, $payload, $this->receipt, auth()->id());
        $this->resetForm();

        $this->dispatch('toast', type: 'success', message: 'Expense recorded.');
    }

    protected function resetForm(): void
    {
        $this->form = [
            'vehicle_id' => '',
            'expense_type' => 'fuel',
            'amount' => '',
            'currency' => 'GHS',
            'description' => '',
            'expense_date' => today()->toDateString(),
        ];
        $this->receipt = null;
    }

    protected function expenseQuery()
    {
        return VehicleExpense::query()
            ->with(['vehicle', 'recorder'])
            ->when($this->vehicleFilter !== '', fn ($query) => $query->where('vehicle_id', (int) $this->vehicleFilter))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('expense_type', $this->typeFilter))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('expense_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('expense_date', '<=', $this->dateTo));
    }

    public function render()
    {
        $base = $this->expenseQuery();

        $records = (clone $base)->latest('expense_date')->paginate(12);
        $total = (clone $base)->sum('amount');
        $byType = (clone $base)
            ->selectRaw('expense_type, SUM(amount) as total')
            ->groupBy('expense_type')
            ->orderByDesc('total')
            ->get();
        $byVehicle = (clone $base)
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_expenses.vehicle_id')
            ->groupBy('vehicle_expenses.vehicle_id', 'vehicles.number_plate')
            ->orderByDesc(\DB::raw('SUM(vehicle_expenses.amount)'))
            ->limit(8)
            ->get(['vehicles.number_plate', \DB::raw('SUM(vehicle_expenses.amount) as total')]);

        return view('livewire.transport.expenses', [
            'records' => $records,
            'vehicles' => Vehicle::query()->orderBy('number_plate')->get(),
            'expenseTypes' => VehicleExpense::TYPES,
            'total' => $total,
            'byType' => $byType,
            'byVehicle' => $byVehicle,
        ]);
    }
}
