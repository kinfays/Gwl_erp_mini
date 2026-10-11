<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\MileageLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Repositories\Transport\VehicleRepository;
use App\Services\Transport\TransportScope;
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
        $user = $this->actor();

        if (! $this->scope()->can($user, 'transport.log_mileage')) {
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

        // Inside the user's reach and, unless they can view all vehicles, their own; anything else (a tampered id) is 404.
        return $this->availableVehicles($this->actor())->whereKey($this->vehicleId)->firstOrFail();
    }

    protected function scope(): TransportScope
    {
        return app(TransportScope::class);
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user, 403);

        return $user;
    }

    /** Vehicles the user may log mileage on: in their reach, not retired, and their own unless they can view all. */
    protected function availableVehicles(User $user)
    {
        return $this->scope()->vehicles($user)
            ->where('vehicles.status', '!=', Vehicle::STATUS_RETIRED)
            ->when(! $this->scope()->can($user, 'transport.view_vehicles'), function ($query) use ($user): void {
                $query->where(function ($inner) use ($user): void {
                    $inner->where('vehicles.assigned_user_id', $user->id)
                        ->orWhere('vehicles.assigned_driver_id', $user->id);
                });
            });
    }

    public function render(VehicleRepository $vehicles)
    {
        $user = $this->actor();
        $canManage = $this->scope()->can($user, 'transport.view_vehicles');
        $assignedVehicle = $vehicles->assignedTo($user);

        $availableVehicles = $this->availableVehicles($user)->orderBy('number_plate')->get();

        $logs = MileageLog::query()
            ->visibleTo($user)
            ->with(['vehicle', 'driver'])
            ->when(! $canManage, function ($query) use ($user): void {
                // Grouped, so the OR cannot escape the location scope above it.
                $query->where(function ($inner) use ($user): void {
                    $inner->where('driver_id', $user->id)
                        ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('assigned_user_id', $user->id));
                });
            })
            ->latest('trip_date')
            ->paginate(12);

        $selectedVehicle = ($this->vehicleId ? $this->availableVehicles($user)->whereKey($this->vehicleId)->first() : null) ?? $assignedVehicle;

        return view('livewire.transport.mileage', [
            'canManage' => $canManage,
            'availableVehicles' => $availableVehicles,
            'assignedVehicle' => $assignedVehicle,
            'selectedVehicle' => $selectedVehicle,
            'logs' => $logs,
        ]);
    }
}
