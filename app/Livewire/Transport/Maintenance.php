<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\Transport\TransportService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Maintenance extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $receipt = null;

    public string $vehicleFilter = '';

    public array $form = [
        'vehicle_id' => '',
        'maintenance_type' => 'routine_service',
        'description' => '',
        'performed_by' => '',
        'mileage_at_service' => '',
        'cost' => '',
        'service_date' => '',
        'next_service_date' => '',
        'next_service_mileage' => '',
    ];

    public array $renewal = [
        'vehicle_id' => '',
        'type' => 'insurance',
        'expiry_date' => '',
        'amount' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
        $this->form['service_date'] = today()->toDateString();
    }

    public function save(TransportService $transport): void
    {
        $this->authorizeManager('transport.manage_maintenance');

        $validated = $this->validate([
            'receipt' => ['nullable', 'image', 'max:4096'],
            'form.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'form.maintenance_type' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:5000'],
            'form.performed_by' => ['nullable', 'string', 'max:255'],
            'form.mileage_at_service' => ['nullable', 'integer', 'min:0'],
            'form.cost' => ['required', 'numeric', 'min:0'],
            'form.service_date' => ['required', 'date'],
            'form.next_service_date' => ['nullable', 'date'],
            'form.next_service_mileage' => ['nullable', 'integer', 'min:0'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($validated['form']['vehicle_id']);
        $payload = $validated['form'];
        unset($payload['vehicle_id']);

        $transport->recordMaintenance($vehicle, $payload, $this->receipt);
        $this->resetForm();

        $this->dispatch('toast', type: 'success', message: 'Maintenance record saved.');
    }

    public function renewDocument(TransportService $transport): void
    {
        $this->authorizeManager('transport.renew_documents');

        $validated = $this->validate([
            'renewal.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'renewal.type' => ['required', 'in:insurance,road_worthiness'],
            'renewal.expiry_date' => ['required', 'date'],
            'renewal.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $transport->renewDocument(
            Vehicle::query()->findOrFail($validated['renewal']['vehicle_id']),
            $validated['renewal']['type'],
            $validated['renewal']['expiry_date'],
            $validated['renewal']['amount'] === '' ? null : (float) $validated['renewal']['amount'],
            auth()->id()
        );

        $this->renewal = [
            'vehicle_id' => '',
            'type' => 'insurance',
            'expiry_date' => '',
            'amount' => '',
        ];

        $this->dispatch('toast', type: 'success', message: 'Document renewal recorded.');
    }

    protected function authorizeManager(string $permission): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission($permission))) {
            abort(403);
        }
    }

    protected function resetForm(): void
    {
        $this->form = [
            'vehicle_id' => '',
            'maintenance_type' => 'routine_service',
            'description' => '',
            'performed_by' => '',
            'mileage_at_service' => '',
            'cost' => '',
            'service_date' => today()->toDateString(),
            'next_service_date' => '',
            'next_service_mileage' => '',
        ];
        $this->receipt = null;
    }

    public function render()
    {
        $records = MaintenanceRecord::query()
            ->with('vehicle')
            ->when($this->vehicleFilter !== '', fn ($query) => $query->where('vehicle_id', (int) $this->vehicleFilter))
            ->latest('service_date')
            ->paginate(12);

        $vehicles = Vehicle::query()->orderBy('number_plate')->get();
        $upcoming = Vehicle::query()
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->get()
            ->sortBy('maintenance_remaining_km')
            ->take(8);

        return view('livewire.transport.maintenance', [
            'records' => $records,
            'vehicles' => $vehicles,
            'upcoming' => $upcoming,
        ]);
    }
}
