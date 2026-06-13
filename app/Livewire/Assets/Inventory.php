<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\Region;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Inventory extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $assetType = '';

    public string $districtId = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingAssetId = null;

    public bool $regionLocked = false;

    public array $form = [
        'asset_name' => '',
        'serial_number' => '',
        'asset_type' => '',
        'ict_asset_model_id' => null,
        'status' => 'Active',
        'assigned_to_employee_id' => null,
        'department_id' => null,
        'region_id' => null,
        'district_id' => null,
        'device_ip' => '',
        'hostname' => '',
        'mac_address' => '',
        'notes' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        $this->regionLocked = $this->actorIsRegionScopedIct();

        if ($this->regionLocked) {
            $this->form['region_id'] = $this->actorRegionId();
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'assetType', 'districtId', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorizeAction('assets.create');

        $this->editingAssetId = null;
        $this->showForm = true;
        $this->resetForm();
    }

    public function openEdit(int $assetId): void
    {
        $this->authorizeAction('assets.edit');

        $asset = $this->scopeAssetsForActor(IctAsset::query())->findOrFail($assetId);

        $this->editingAssetId = $asset->id;
        $this->showForm = true;
        $this->form = [
            'asset_name' => (string) $asset->asset_name,
            'serial_number' => (string) $asset->serial_number,
            'asset_type' => (string) $asset->asset_type,
            'ict_asset_model_id' => $asset->ict_asset_model_id,
            'status' => (string) $asset->status,
            'assigned_to_employee_id' => $asset->assigned_to_employee_id,
            'department_id' => $asset->department_id,
            'region_id' => $asset->region_id,
            'district_id' => $asset->district_id,
            'device_ip' => (string) $asset->device_ip,
            'hostname' => (string) $asset->hostname,
            'mac_address' => (string) $asset->mac_address,
            'notes' => (string) $asset->notes,
        ];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingAssetId = null;
        $this->resetForm();
    }

    public function save(): void
    {
        $permission = $this->editingAssetId ? 'assets.edit' : 'assets.create';
        $this->authorizeAction($permission);

        $validated = $this->validate($this->rules());
        $validated = $validated['form'];

        if ($this->actorIsRegionScopedIct()) {
            $validated['region_id'] = $this->actorRegionId();
        }

        if (! empty($validated['assigned_to_employee_id'])) {
            $employee = Employee::query()->find($validated['assigned_to_employee_id']);

            if ($employee) {
                $validated['department_id'] = $validated['department_id'] ?: $employee->department_id;
                $validated['district_id'] = $validated['district_id'] ?: $employee->district_id;
                $validated['region_id'] = $validated['region_id'] ?: $employee->region_id;
            }
        }

        if ($this->editingAssetId) {
            $asset = $this->scopeAssetsForActor(IctAsset::query())->findOrFail($this->editingAssetId);
            $oldAssigned = $asset->assigned_to_employee_id;
            $newAssigned = $validated['assigned_to_employee_id'] ?? null;

            if ($oldAssigned && $oldAssigned !== $newAssigned) {
                $validated['previous_assigned_to_employee_id'] = $oldAssigned;
            }

            $asset->update($validated);
            $message = 'Asset updated successfully.';
        } else {
            IctAsset::query()->create($validated);
            $message = 'Asset created successfully.';
        }

        $this->dispatch('toast', type: 'success', message: $message);
        $this->closeForm();
    }

    public function lastSeenState($timestamp): array
    {
        if (! $timestamp) {
            return ['dot' => '🔴', 'label' => 'Never'];
        }

        $seenAt = Carbon::parse($timestamp);
        $hours = $seenAt->diffInHours(now());

        if ($hours < 24) {
            return ['dot' => '🟢', 'label' => 'Active < 24h'];
        }

        if ($hours <= 24 * 7) {
            return ['dot' => '🟡', 'label' => 'Seen this week'];
        }

        return ['dot' => '🔴', 'label' => 'Stale > 7 days'];
    }

    protected function rules(): array
    {
        return [
            'form.asset_name' => ['required', 'string', 'max:255'],
            'form.serial_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('ict_assets', 'serial_number')->ignore($this->editingAssetId),
            ],
            'form.asset_type' => ['required', 'string', 'max:120'],
            'form.ict_asset_model_id' => ['nullable', 'integer', 'exists:ict_asset_models,id'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'form.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'form.region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'form.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'form.device_ip' => ['nullable', 'string', 'max:255'],
            'form.hostname' => ['nullable', 'string', 'max:255'],
            'form.mac_address' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string'],
        ];
    }

    protected function authorizeAction(string $permission): void
    {
        $user = $this->actor();

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission($permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    protected function resetForm(): void
    {
        $this->form = [
            'asset_name' => '',
            'serial_number' => '',
            'asset_type' => '',
            'ict_asset_model_id' => null,
            'status' => 'Active',
            'assigned_to_employee_id' => null,
            'department_id' => null,
            'region_id' => $this->regionLocked ? $this->actorRegionId() : null,
            'district_id' => null,
            'device_ip' => '',
            'hostname' => '',
            'mac_address' => '',
            'notes' => '',
        ];
    }

    public function render()
    {
        $assets = $this->scopeAssetsForActor(
            IctAsset::query()->with(['assetModel', 'assignedTo', 'district', 'region'])
        )
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('asset_name', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhere('hostname', 'like', $term)
                        ->orWhere('mac_address', 'like', $term)
                        ->orWhereHas('assignedTo', fn ($employee) => $employee->where('full_name', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->assetType, fn ($query) => $query->where('asset_type', $this->assetType))
            ->when($this->districtId !== '', fn ($query) => $query->where('district_id', (int) $this->districtId))
            ->latest()
            ->paginate($this->perPage);

        $statusOptions = $this->scopeAssetsForActor(IctAsset::query())
            ->select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $assetTypeOptions = $this->scopeAssetsForActor(IctAsset::query())
            ->select('asset_type')
            ->whereNotNull('asset_type')
            ->distinct()
            ->orderBy('asset_type')
            ->pluck('asset_type');

        $districts = District::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('district_name')
            ->get();

        $regions = Region::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->whereKey($this->actorRegionId()))
            ->orderBy('region_name')
            ->get();

        $models = IctAssetModel::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $employees = Employee::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('full_name')
            ->limit(500)
            ->get();

        return view('livewire.assets.inventory', [
            'assets' => $assets,
            'statusOptions' => $statusOptions,
            'assetTypeOptions' => $assetTypeOptions,
            'districts' => $districts,
            'regions' => $regions,
            'models' => $models,
            'departments' => Department::query()->orderBy('department_name')->get(),
            'employees' => $employees,
        ]);
    }
}
