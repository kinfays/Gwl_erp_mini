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
use App\Services\Assets\AssetRecordService;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class AssetsList extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public const CATEGORY = IctAsset::DEVICE_CATEGORY_ASSET;

    public string $search = '';

    public string $status = '';

    public string $assetType = '';

    public string $districtId = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingAssetId = null;

    public bool $regionLocked = false;

    public ?string $previousAssignedLabel = null;

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
        'purchased_at' => null,
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

        $asset = $this->scopeAssetsForActor(IctAsset::query())
            ->where('device_category', self::CATEGORY)
            ->with('previousAssignedTo')
            ->findOrFail($assetId);

        $this->editingAssetId = $asset->id;
        $this->showForm = true;
        $this->previousAssignedLabel = $asset->previousAssignedTo?->full_name;
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
            'purchased_at' => $asset->purchased_at?->toDateString(),
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

        $validated = $this->validate($this->rules())['form'];

        if ($this->actorIsRegionScopedIct()) {
            $validated['region_id'] = $this->actorRegionId();
        }

        if (! empty($validated['assigned_to_employee_id'])) {
            $employee = Employee::query()->find($validated['assigned_to_employee_id']);

            if ($employee) {
                $validated['district_id'] = $validated['district_id'] ?: $employee->district_id;
                $validated['region_id'] = $validated['region_id'] ?: $employee->region_id;
            }
        }

        $existing = $this->editingAssetId
            ? $this->scopeAssetsForActor(IctAsset::query())->where('device_category', self::CATEGORY)->findOrFail($this->editingAssetId)
            : null;

        app(AssetRecordService::class)->save($validated, self::CATEGORY, $existing);

        $this->dispatch('toast', type: 'success', message: $existing ? 'Asset updated successfully.' : 'Asset created successfully.');
        $this->closeForm();
    }

    protected function rules(): array
    {
        return [
            'form.asset_name' => ['required', 'string', 'max:255'],
            'form.serial_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ict_assets', 'serial_number')->ignore($this->editingAssetId),
            ],
            'form.asset_type' => ['required', Rule::in(array_keys(IctAsset::ASSET_TYPES[self::CATEGORY]))],
            'form.ict_asset_model_id' => ['nullable', 'integer', 'exists:ict_asset_models,id'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'form.department_id' => ['required', 'integer', 'exists:departments,id'],
            'form.region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'form.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'form.purchased_at' => ['nullable', 'date'],
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
        $this->previousAssignedLabel = null;
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
            'purchased_at' => null,
            'notes' => '',
        ];
    }

    public function render()
    {
        $assets = $this->scopeAssetsForActor(
            IctAsset::query()->with(['assetModel', 'assignedTo', 'district', 'region'])
        )
            ->where('device_category', self::CATEGORY)
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('asset_name', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhereHas('assignedTo', fn ($employee) => $employee->where('full_name', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->assetType, fn ($query) => $query->where('asset_type', $this->assetType))
            ->when($this->districtId !== '', fn ($query) => $query->where('district_id', (int) $this->districtId))
            ->latest()
            ->paginate($this->perPage);

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
            ->when($this->form['asset_type'], fn ($query) => $query->where('category', $this->form['asset_type']))
            ->orderBy('name')
            ->get();

        $employees = Employee::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('full_name')
            ->limit(500)
            ->get();

        return view('livewire.assets.assets-list', [
            'assets' => $assets,
            'assetTypes' => IctAsset::ASSET_TYPES[self::CATEGORY],
            'statusOptions' => [IctAsset::STATUS_ACTIVE, IctAsset::STATUS_IN_REPAIR, IctAsset::STATUS_RETIRED, IctAsset::STATUS_LOST],
            'districts' => $districts,
            'regions' => $regions,
            'models' => $models,
            'departments' => Department::query()->orderBy('department_name')->get(),
            'employees' => $employees,
        ]);
    }
}
