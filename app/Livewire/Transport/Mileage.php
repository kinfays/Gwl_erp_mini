<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Repositories\Transport\VehicleRepository;
use App\Services\Transport\TransportService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class Mileage extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public ?int $vehicleId = null;

    public array $form = [
        'mileage_before' => 0,
        'mileage_after' => '',
        'trip_date' => '',
        'trip_purpose' => '',
    ];

    public function mount(VehicleRepository $vehicles): void
    {
        $this->enforceLivewireModule('transport');

        $assigned = auth()->user() ? $vehicles->assignedTo(auth()->user()) : null;
        $this->vehicleId = $assigned?->id;
        $this->form['mileage_before'] = $assigned?->current_mileage ?? 0;
        $this->form['trip_date'] = today()->toDateString();
    }

    public function updatedVehicleId(): void
    {
        $vehicle = $this->selectedVehicle();
        $this->form['mileage_before'] = $vehicle?->current_mileage ?? 0;
    }

    public function save(TransportService $transport): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission('transport.log_mileage'))) {
            abort(403);
        }

        $vehicle = $this->selectedVehicle();

        if (! $vehicle) {
            throw ValidationException::withMessages(['vehicleId' => 'Select an assigned vehicle first.']);
        }

        $validated = $this->validate([
            'form.mileage_before' => ['required', 'integer', 'min:0'],
            'form.mileage_after' => ['required', 'integer', 'min:1'],
            'form.trip_date' => ['required', 'date'],
            'form.trip_purpose' => ['required', 'string', 'max:255'],
        ]);

        $transport->logMileage($vehicle, $user, $validated['form']);
        $this->form['mileage_before'] = $vehicle->fresh()->current_mileage;
        $this->form['mileage_after'] = '';
        $this->form['trip_purpose'] = '';

        $this->dispatch('toast', type: 'success', message: 'Mileage logged.');
    }

    protected function selectedVehicle(): ?Vehicle
    {
        if (! $this->vehicleId) {
            return null;
        }

        return Vehicle::query()->find($this->vehicleId);
    }

    public function render(VehicleRepository $vehicles)
    {
        $user = auth()->user();
        $canManage = $user?->hasRoles('super_admin', 'transport_manager') || $user?->hasPermission('transport.view_vehicles');
        $assignedVehicle = $user ? $vehicles->assignedTo($user) : null;

        $availableVehicles = Vehicle::query()
            ->when(! $canManage, function ($query) use ($user): void {
                $query->where(function ($inner) use ($user): void {
                    $inner->where('assigned_user_id', $user?->id)
                        ->orWhere('assigned_driver_id', $user?->id);
                });
            })
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->orderBy('number_plate')
            ->get();

        $logs = MileageLog::query()
            ->with(['vehicle', 'driver'])
            ->when(! $canManage, function ($query) use ($user): void {
                $query->where('driver_id', $user?->id)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('assigned_user_id', $user?->id));
            })
            ->latest('trip_date')
            ->paginate(12);

        $selectedVehicle = $this->selectedVehicle() ?? $assignedVehicle;

        return view('livewire.transport.mileage', [
            'canManage' => $canManage,
            'availableVehicles' => $availableVehicles,
            'assignedVehicle' => $assignedVehicle,
            'selectedVehicle' => $selectedVehicle,
            'logs' => $logs,
        ]);
    }
}
