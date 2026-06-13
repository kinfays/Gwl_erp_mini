<?php

namespace App\Livewire\Transport;

use App\Imports\RawRowsImport;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Transport\TransportService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

class Vehicles extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $type = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public bool $showImport = false;

    public ?int $editingVehicleId = null;

    public ?int $selectedVehicleId = null;

    public ?TemporaryUploadedFile $photo = null;

    public ?TemporaryUploadedFile $importFile = null;

    public array $importErrors = [];

    public ?array $importSummary = null;

    public array $form = [
        'type' => 'sedan',
        'brand' => '',
        'model' => '',
        'color' => '',
        'number_plate' => '',
        'year_purchased' => null,
        'is_pool_car' => true,
        'assigned_user_id' => null,
        'department_id' => null,
        'driver_type' => 'self_drive',
        'assigned_driver_id' => null,
        'current_mileage' => 0,
        'maintenance_interval_km' => 5000,
        'insurance_expiry_date' => '',
        'road_worthiness_expiry_date' => '',
        'status' => 'active',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'type', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorizeAction('transport.create_vehicles');

        $this->editingVehicleId = null;
        $this->selectedVehicleId = null;
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $vehicleId): void
    {
        $this->authorizeAction('transport.edit_vehicles');

        $vehicle = Vehicle::query()->findOrFail($vehicleId);
        $this->editingVehicleId = $vehicle->id;
        $this->selectedVehicleId = $vehicle->id;
        $this->showForm = true;
        $this->form = [
            'type' => $vehicle->type,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'color' => $vehicle->color,
            'number_plate' => $vehicle->number_plate,
            'year_purchased' => $vehicle->year_purchased,
            'is_pool_car' => (bool) $vehicle->is_pool_car,
            'assigned_user_id' => $vehicle->assigned_user_id,
            'department_id' => $vehicle->department_id,
            'driver_type' => $vehicle->driver_type,
            'assigned_driver_id' => $vehicle->assigned_driver_id,
            'current_mileage' => $vehicle->current_mileage,
            'maintenance_interval_km' => $vehicle->maintenance_interval_km,
            'insurance_expiry_date' => $vehicle->insurance_expiry_date?->toDateString() ?? '',
            'road_worthiness_expiry_date' => $vehicle->road_worthiness_expiry_date?->toDateString() ?? '',
            'status' => $vehicle->status,
        ];
    }

    public function viewVehicle(int $vehicleId): void
    {
        $this->selectedVehicleId = $vehicleId;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingVehicleId = null;
        $this->photo = null;
        $this->resetForm();
    }

    public function save(TransportService $transport): void
    {
        $this->authorizeAction($this->editingVehicleId ? 'transport.edit_vehicles' : 'transport.create_vehicles');

        $validated = $this->validate($this->rules());
        $vehicle = $this->editingVehicleId ? Vehicle::query()->findOrFail($this->editingVehicleId) : null;

        $transport->saveVehicle($validated['form'], $vehicle, $this->photo);

        $this->dispatch('toast', type: 'success', message: $this->editingVehicleId ? 'Vehicle updated.' : 'Vehicle created.');
        $this->closeForm();
    }

    public function importVehicles(TransportService $transport): void
    {
        $this->authorizeAction('transport.import_vehicles');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
        ]);

        $import = new RawRowsImport;
        Excel::import($import, $this->importFile);

        $rows = $import->rows;
        $headings = collect($rows->shift() ?? [])
            ->map(fn ($value) => str((string) $value)->trim()->lower()->replace([' ', '-'], '_')->value())
            ->values()
            ->all();

        $created = 0;
        $updated = 0;
        $this->importErrors = [];

        foreach ($rows->values() as $index => $row) {
            $mapped = $this->mapImportRow($headings, $row);

            if (collect($mapped)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty()) {
                continue;
            }

            try {
                $vehicle = Vehicle::query()->where('number_plate', $mapped['number_plate'] ?? null)->first();
                $payload = $this->normalizeImportPayload($mapped);
                $saved = $transport->saveVehicle($payload, $vehicle);
                $saved->wasRecentlyCreated ? $created++ : $updated++;
            } catch (\Throwable $exception) {
                $this->importErrors[] = [
                    'row' => $index + 2,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $this->importSummary = [
            'created' => $created,
            'updated' => $updated,
            'failed' => count($this->importErrors),
        ];

        $this->importFile = null;
        $this->dispatch('toast', type: $this->importErrors ? 'warning' : 'success', message: 'Vehicle import processed.');
    }

    protected function rules(): array
    {
        return [
            'photo' => ['nullable', 'image', 'max:4096'],
            'form.type' => ['required', Rule::in(Vehicle::TYPES)],
            'form.brand' => ['required', 'string', 'max:255'],
            'form.model' => ['required', 'string', 'max:255'],
            'form.color' => ['nullable', 'string', 'max:255'],
            'form.number_plate' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vehicles', 'number_plate')->ignore($this->editingVehicleId),
            ],
            'form.year_purchased' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'form.is_pool_car' => ['boolean'],
            'form.assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'form.driver_type' => ['required', Rule::in(Vehicle::DRIVER_TYPES)],
            'form.assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.current_mileage' => ['required', 'integer', 'min:0'],
            'form.maintenance_interval_km' => ['required', 'integer', 'min:1', 'max:100000'],
            'form.insurance_expiry_date' => ['nullable', 'date'],
            'form.road_worthiness_expiry_date' => ['nullable', 'date'],
            'form.status' => ['required', Rule::in(Vehicle::STATUSES)],
        ];
    }

    protected function authorizeAction(string $permission): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission($permission))) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    protected function resetForm(): void
    {
        $this->form = [
            'type' => 'sedan',
            'brand' => '',
            'model' => '',
            'color' => '',
            'number_plate' => '',
            'year_purchased' => null,
            'is_pool_car' => true,
            'assigned_user_id' => null,
            'department_id' => null,
            'driver_type' => 'self_drive',
            'assigned_driver_id' => null,
            'current_mileage' => 0,
            'maintenance_interval_km' => 5000,
            'insurance_expiry_date' => '',
            'road_worthiness_expiry_date' => '',
            'status' => 'active',
        ];
    }

    protected function mapImportRow(array $headings, mixed $row): array
    {
        $values = collect($row instanceof Collection ? $row->all() : (array) $row)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->values()
            ->all();

        $mapped = [];

        foreach ($headings as $index => $heading) {
            if ($heading !== '') {
                $mapped[$heading] = $values[$index] ?? null;
            }
        }

        return $mapped;
    }

    protected function normalizeImportPayload(array $row): array
    {
        $assignedUser = $this->findUser($row['assigned_user_email'] ?? null);
        $assignedDriver = $this->findUser($row['assigned_driver_email'] ?? null);
        $department = $this->findDepartment($row['department_name'] ?? null);

        return [
            'type' => strtolower((string) ($row['type'] ?? Vehicle::TYPE_SEDAN)),
            'brand' => (string) ($row['brand'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'color' => $row['color'] ?? null,
            'number_plate' => strtoupper((string) ($row['number_plate'] ?? '')),
            'year_purchased' => $row['year_purchased'] ?? null,
            'is_pool_car' => $this->parseBoolean($row['is_pool_car'] ?? true),
            'assigned_user_id' => $assignedUser?->id,
            'department_id' => $department?->id,
            'driver_type' => $row['driver_type'] ?? Vehicle::DRIVER_SELF_DRIVE,
            'assigned_driver_id' => $assignedDriver?->id,
            'current_mileage' => (int) ($row['current_mileage'] ?? 0),
            'maintenance_interval_km' => (int) ($row['maintenance_interval_km'] ?? 5000),
            'insurance_expiry_date' => $row['insurance_expiry_date'] ?? null,
            'road_worthiness_expiry_date' => $row['road_worthiness_expiry_date'] ?? null,
            'status' => strtolower((string) ($row['status'] ?? Vehicle::STATUS_ACTIVE)),
        ];
    }

    protected function findUser(mixed $email): ?User
    {
        $email = trim((string) $email);

        return $email === '' ? null : User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    }

    protected function findDepartment(mixed $name): ?Department
    {
        $name = trim((string) $name);

        return $name === '' ? null : Department::query()->whereRaw('LOWER(department_name) = ?', [strtolower($name)])->first();
    }

    protected function parseBoolean(mixed $value): bool
    {
        return ! in_array(strtolower((string) $value), ['0', 'false', 'no', 'non-pool'], true);
    }

    public function render()
    {
        $user = auth()->user();
        $canManage = $user?->hasRoles('super_admin', 'transport_manager')
            || $user?->hasPermission('transport.edit_vehicles');

        $vehicles = Vehicle::query()
            ->with(['assignedUser', 'assignedDriver', 'department'])
            ->when(! $canManage, function ($query) use ($user): void {
                $query->where(function ($inner) use ($user): void {
                    $inner->where('assigned_user_id', $user?->id)
                        ->orWhere('assigned_driver_id', $user?->id);
                });
            })
            ->when($this->search, function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('number_plate', 'like', $term)
                        ->orWhere('brand', 'like', $term)
                        ->orWhere('model', 'like', $term)
                        ->orWhereHas('assignedUser', fn ($assigned) => $assigned->where('full_name', 'like', $term)->orWhere('email', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->type, fn ($query) => $query->where('type', $this->type))
            ->latest()
            ->paginate($this->perPage);

        $selectedVehicle = $this->selectedVehicleId
            ? Vehicle::query()
                ->with(['assignedUser', 'assignedDriver', 'department', 'assignmentHistories.user', 'assignmentHistories.driver', 'issues', 'maintenanceRecords'])
                ->find($this->selectedVehicleId)
            : null;

        return view('livewire.transport.vehicles', [
            'vehicles' => $vehicles,
            'selectedVehicle' => $selectedVehicle,
            'types' => Vehicle::TYPES,
            'statuses' => Vehicle::STATUSES,
            'driverTypes' => Vehicle::DRIVER_TYPES,
            'departments' => Department::query()->orderBy('department_name')->get(),
            'users' => User::query()->active()->visibleInErp()->orderBy('full_name')->limit(600)->get(),
            'drivers' => User::query()
                ->active()
                ->whereHas('roles', fn ($query) => $query->where('name', 'driver'))
                ->orderBy('full_name')
                ->limit(300)
                ->get(),
            'canManage' => $canManage,
        ]);
    }
}
