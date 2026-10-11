<?php

namespace App\Livewire\Transport;

use App\Imports\RawRowsImport;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\District;
use App\Models\Region;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Transport\TransportScope;
use App\Services\Transport\TransportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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
        'region_id' => null,
        'district_id' => null,
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

    public function updatedFormRegionId(): void
    {
        // A district belongs to one region: drop the chosen one when the region changes.
        $this->form['district_id'] = null;
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

        $vehicle = $this->scope()->findVehicle($this->actor(), $vehicleId);
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
            'region_id' => $vehicle->region_id,
            'district_id' => $vehicle->district_id,
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
        $vehicle = $this->scope()->findVehicle($this->actor(), $vehicleId);
        Gate::forUser($this->actor())->authorize('view', $vehicle);

        $this->selectedVehicleId = $vehicle->id;
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
        $vehicle = $this->editingVehicleId ? $this->scope()->findVehicle($this->actor(), $this->editingVehicleId) : null;

        $transport->saveVehicle($validated['form'], $vehicle, $this->photo, $this->actor());

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
        $actor = $this->actor();

        foreach ($rows->values() as $index => $row) {
            $mapped = $this->mapImportRow($headings, $row);

            if (collect($mapped)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty()) {
                continue;
            }

            try {
                $plate = strtoupper(trim((string) ($mapped['number_plate'] ?? '')));
                $vehicle = $plate === '' ? null : $this->scope()->vehicles($actor)->where('vehicles.number_plate', $plate)->first();

                // The plate exists but is not this user's to touch: say it is taken, never whose it is.
                if ($plate !== '' && ! $vehicle && Vehicle::withTrashed()->where('number_plate', $plate)->exists()) {
                    throw new \RuntimeException('This number plate is already registered.');
                }

                $saved = $transport->saveVehicle($this->normalizeImportPayload($mapped), $vehicle, null, $actor);
                $saved->wasRecentlyCreated ? $created++ : $updated++;
            } catch (\Throwable $exception) {
                $this->importErrors[] = [
                    'row' => $index + 2,
                    'message' => $exception instanceof \Illuminate\Validation\ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage(),
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
            // Only super_admin chooses the place; for everyone else the service uses their own, whatever is posted.
            'form.region_id' => [$this->canPickLocation() ? 'required' : 'nullable', 'integer', 'exists:regions,id'],
            'form.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'form.driver_type' => ['required', Rule::in(Vehicle::DRIVER_TYPES)],
            'form.assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.current_mileage' => ['required', 'integer', 'min:0'],
            'form.maintenance_interval_km' => ['required', 'integer', 'min:1', 'max:100000'],
            'form.insurance_expiry_date' => ['nullable', 'date'],
            'form.road_worthiness_expiry_date' => ['nullable', 'date'],
            'form.status' => ['required', Rule::in(Vehicle::STATUSES)],
        ];
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

    protected function canPickLocation(): bool
    {
        return $this->scope()->seesAll($this->actor());
    }

    protected function authorizeAction(string $permission): void
    {
        $user = auth()->user();

        if (! $user || ! $this->scope()->can($user, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    protected function resetForm(): void
    {
        // Prefilled with the actor's own place; only super_admin may change it.
        $own = $this->canPickLocation() ? null : $this->scope()->locationOf($this->actor());

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
            'region_id' => $own['region_id'] ?? null,
            'district_id' => $own['district_id'] ?? null,
            'driver_type' => 'self_drive',
            'assigned_driver_id' => null,
            'current_mileage' => 0,
            'maintenance_interval_km' => 5000,
            'insurance_expiry_date' => '',
            'road_worthiness_expiry_date' => '',
            'status' => 'active',
        ];
    }

    /**
     * The place whose people may be put on the vehicle being edited. Decided on the server: super_admin's form choice,
     * otherwise the vehicle's own place (or the actor's, for a new one), never a value the browser can change.
     *
     * @return array{region_id: int|null, district_id: int|null}
     */
    protected function pickerLocation(): array
    {
        if ($this->canPickLocation()) {
            return [
                'region_id' => $this->form['region_id'] ? (int) $this->form['region_id'] : null,
                'district_id' => $this->form['district_id'] ? (int) $this->form['district_id'] : null,
            ];
        }

        if ($this->editingVehicleId) {
            $vehicle = $this->scope()->vehicles($this->actor())->whereKey($this->editingVehicleId)->first();

            return ['region_id' => $vehicle?->region_id, 'district_id' => $vehicle?->district_id];
        }

        return $this->scope()->locationOf($this->actor()) ?? ['region_id' => null, 'district_id' => null];
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
        $district = $this->findDistrict($row['district_name'] ?? null);
        $region = $district ? null : $this->findRegion($row['region_name'] ?? null);

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
            // Read by the service for super_admin only; everyone else's rows take the importer's own place.
            'region_id' => $district?->region_id ?? $region?->id,
            'district_id' => $district?->id,
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

    protected function findDistrict(mixed $name): ?District
    {
        $name = trim((string) $name);

        return $name === '' ? null : District::query()->whereRaw('LOWER(district_name) = ?', [strtolower($name)])->first();
    }

    protected function findRegion(mixed $name): ?Region
    {
        $name = trim((string) $name);

        return $name === '' ? null : Region::query()->whereRaw('LOWER(region_name) = ?', [strtolower($name)])->first();
    }

    protected function parseBoolean(mixed $value): bool
    {
        return ! in_array(strtolower((string) $value), ['0', 'false', 'no', 'non-pool'], true);
    }

    public function render()
    {
        $user = $this->actor();
        $scope = $this->scope();
        $canManage = $scope->can($user, 'transport.edit_vehicles');
        $canPickLocation = $this->canPickLocation();

        $vehicles = $scope->vehicles($user)
            ->with(['assignedUser', 'assignedDriver', 'department', 'region', 'district'])
            ->when(! $canManage, function ($query) use ($user): void {
                $query->where(function ($inner) use ($user): void {
                    $inner->where('vehicles.assigned_user_id', $user->id)
                        ->orWhere('vehicles.assigned_driver_id', $user->id);
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
            ? $scope->vehicles($user)
                ->with(['assignedUser', 'assignedDriver', 'department', 'assignmentHistories.user', 'assignmentHistories.driver', 'issues', 'maintenanceRecords'])
                ->when(! $canManage, fn ($query) => $query->where(function ($inner) use ($user): void {
                    $inner->where('vehicles.assigned_user_id', $user->id)->orWhere('vehicles.assigned_driver_id', $user->id);
                }))
                ->find($this->selectedVehicleId)
            : null;

        $users = collect();
        $drivers = collect();

        if ($this->showForm && $canManage) {
            $place = $this->pickerLocation();
            $people = fn () => $scope->peopleAt($place['region_id'], $place['district_id'])->active();

            $users = $people()->visibleInErp()->orderBy('full_name')->limit(600)->get();
            $drivers = $people()
                ->whereHas('roles', fn ($query) => $query->where('name', 'driver'))
                ->orderBy('full_name')
                ->limit(300)
                ->get();
        }

        $ownLocation = ! $canPickLocation ? $scope->locationOf($user) : null;

        return view('livewire.transport.vehicles', [
            'vehicles' => $vehicles,
            'selectedVehicle' => $selectedVehicle,
            'types' => Vehicle::TYPES,
            'statuses' => Vehicle::STATUSES,
            'driverTypes' => Vehicle::DRIVER_TYPES,
            'departments' => Department::query()->orderBy('department_name')->get(),
            'users' => $users,
            'drivers' => $drivers,
            'canManage' => $canManage,
            'canPickLocation' => $canPickLocation,
            'regions' => $canPickLocation && $this->showForm ? Region::query()->orderBy('region_name')->get() : collect(),
            'districts' => $canPickLocation && $this->showForm && $this->form['region_id']
                ? District::query()->where('region_id', $this->form['region_id'])->orderBy('district_name')->get()
                : collect(),
            // Read-only label of the place a new vehicle gets (everyone but super_admin), and of an edited one.
            'locationLabel' => $this->locationLabel($this->editingVehicleId ? $this->pickerLocation() : $ownLocation),
        ]);
    }

    /** @param array{region_id: int|null, district_id: int|null}|null $place */
    protected function locationLabel(?array $place): string
    {
        if (! $place || ! ($place['region_id'] ?? null)) {
            return 'No location (your account has no region)';
        }

        $region = Region::query()->whereKey($place['region_id'])->value('region_name');
        $district = ($place['district_id'] ?? null) ? District::query()->whereKey($place['district_id'])->value('district_name') : null;

        return trim($region.($district ? ' / '.$district : ''));
    }
}
