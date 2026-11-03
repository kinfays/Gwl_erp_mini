<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ExportsAssetLists;
use App\Livewire\Assets\Concerns\FiltersByAnalytics;
use App\Livewire\Assets\Concerns\FiltersByAssetTypes;
use App\Livewire\Assets\Concerns\ResetsModelOnTypeChange;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Concerns\HasSelectableColumns;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Services\Assets\AssetDashboardService;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use App\Services\Assets\AssetRecordService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AssetsList extends Component
{
    use EnforcesModuleAccess;
    use ExportsAssetLists;
    use FiltersByAnalytics;
    use FiltersByAssetTypes;
    use HasSelectableColumns;
    use ResetsModelOnTypeChange;
    use ScopesAssetsByActor;
    use WithPagination;

    public const CATEGORY = IctAsset::DEVICE_CATEGORY_ASSET;

    /** Bound to ?q= so summary pages can link straight to one device. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $status = '';

    public string $districtId = '';

    /** ?ups= has_ups | no_ups | spoiled (AssetDashboardService::UPS_FILTERS); anything else means all. */
    #[Url(as: 'ups', except: '')]
    public string $ups = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingAssetId = null;

    public ?string $previousAssignedLabel = null;

    /**
     * form.region_id is display-only. It has no validation rule, so it never
     * reaches save(), which always stamps the actor's own region instead.
     */
    public array $form = [
        'asset_name' => '',
        'serial_number' => '',
        'asset_type' => '',
        'ict_asset_model_id' => null,
        'status' => 'Active',
        'status_reason' => '',
        'has_ups' => false,
        'ups_status' => '',
        'ups_notes' => '',
        'cpu_name' => '',
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

        $this->form['region_id'] = $this->actorRegionId();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'assetType', 'districtId', 'ups', 'perPage', ...$this->analyticsFilterProperties()], true)) {
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
            'status_reason' => (string) $asset->status_reason,
            'has_ups' => (bool) $asset->has_ups,
            'ups_status' => (string) $asset->ups_status,
            'ups_notes' => (string) $asset->ups_notes,
            'cpu_name' => (string) $asset->cpu_name,
            'assigned_to_employee_id' => $asset->assigned_to_employee_id,
            'department_id' => $asset->department_id,
            'region_id' => $this->actorRegionId(),
            'district_id' => $asset->district_id,
            'purchased_at' => $asset->purchased_at?->toDateString(),
            'notes' => (string) $asset->notes,
        ];
    }

    /** Read-only transfer history for the asset being edited; limited to assets the actor can view. */
    protected function historyFor(int $assetId)
    {
        $asset = $this->scopeAssetsForViewing(IctAsset::query())
            ->where('device_category', self::CATEGORY)
            ->find($assetId);

        return $asset
            ? $asset->transfers()->with(['fromEmployee', 'toEmployee', 'fromDistrict', 'toDistrict', 'relatedAsset'])->limit(50)->get()
            : collect();
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

        $regionId = $this->requireActorRegionId();

        $validated = $this->validate($this->rules())['form'];
        $validated['region_id'] = $regionId;

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
            'form.ict_asset_model_id' => ['required', 'integer', 'exists:ict_asset_models,id'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.status_reason' => [Rule::requiredIf(fn () => ($this->form['status'] ?? null) === IctAsset::STATUS_DAMAGED), 'nullable', 'string', 'max:1000'],
            'form.has_ups' => ['boolean'],
            'form.ups_status' => [
                Rule::requiredIf(fn () => $this->formSupportsUps() && (bool) ($this->form['has_ups'] ?? false)),
                'nullable',
                Rule::in(IctAsset::UPS_STATUSES),
            ],
            'form.ups_notes' => ['nullable', 'string', 'max:1000'],
            'form.cpu_name' => ['nullable', 'string', 'max:255'],
            'form.assigned_to_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'form.department_id' => ['required', 'integer', 'exists:departments,id'],
            'form.district_id' => ['required', 'integer', $this->actorRegionDistrictRule()],
            'form.purchased_at' => ['nullable', 'date'],
            'form.notes' => ['nullable', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.district_id.exists' => 'The selected location is not in your region.',
            'form.ups_status.required' => 'Say whether the UPS is working or spoiled.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.asset_name' => 'asset name',
            'form.serial_number' => 'serial number',
            'form.asset_type' => 'type',
            'form.ict_asset_model_id' => 'model',
            'form.status' => 'status',
            'form.status_reason' => 'reason',
            'form.ups_status' => 'UPS condition',
            'form.ups_notes' => 'UPS notes',
            'form.cpu_name' => 'processor',
            'form.assigned_to_employee_id' => 'assigned to',
            'form.department_id' => 'department',
            'form.district_id' => 'location',
            'form.purchased_at' => 'date',
        ];
    }

    /** The UPS section applies only to the types in IctAsset::UPS_ASSET_TYPES (not laptops). */
    protected function formSupportsUps(): bool
    {
        return IctAsset::supportsUps(self::CATEGORY, (string) ($this->form['asset_type'] ?? ''));
    }

    /** The processor field applies only to the types in IctAsset::PROCESSOR_ASSET_TYPES. */
    protected function formSupportsProcessor(): bool
    {
        return IctAsset::supportsProcessor(self::CATEGORY, (string) ($this->form['asset_type'] ?? ''));
    }

    /** Picking a type that carries no UPS (or no processor) drops whatever was entered for one. */
    public function updatedForm($value, $key): void
    {
        if ($key !== 'asset_type') {
            return;
        }

        if (! $this->formSupportsUps()) {
            $this->form['has_ups'] = false;
            $this->form['ups_status'] = '';
            $this->form['ups_notes'] = '';
        }

        if (! $this->formSupportsProcessor()) {
            $this->form['cpu_name'] = '';
        }
    }

    protected function columnManifest(): array
    {
        return AssetListColumns::manifest(AssetListColumns::ASSETS);
    }

    /** The list's filters, named as AssetListQuery (and the export links) name them. */
    protected function listFilters(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'type' => $this->assetType,
            'district' => $this->districtId,
            'ups' => $this->ups,
            ...$this->analyticsFilterValues(),
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
            'status_reason' => '',
            'has_ups' => false,
            'ups_status' => '',
            'ups_notes' => '',
            'cpu_name' => '',
            'assigned_to_employee_id' => null,
            'department_id' => null,
            'region_id' => $this->actorRegionId(),
            'district_id' => null,
            'purchased_at' => null,
            'notes' => '',
        ];
    }

    public function render()
    {
        $assets = $this->scopeAssetsForViewing(
            IctAsset::query()->with(['assetModel', 'assignedTo', 'district', 'region'])
        )
            ->tap(fn ($query) => app(AssetListQuery::class)->assets($query, self::CATEGORY, $this->listFilters()))
            ->latest()
            ->paginate($this->perPage);

        // List filter: follows what the actor can see, not the form's region lock.
        $districts = District::query()
            ->with('region')
            ->when(! $this->actorSeesAllRegions(), fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('district_name')
            ->get();

        $models = IctAssetModel::query()
            ->with('manufacturer')
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
            'analyticsChips' => $this->analyticsFilterChips(),
            'history' => $this->editingAssetId ? $this->historyFor($this->editingAssetId) : collect(),
            'assets' => $assets,
            'assetTypes' => IctAsset::ASSET_TYPES[self::CATEGORY],
            'selectedTypes' => $this->selectedAssetTypes(),
            'statusOptions' => IctAsset::STATUSES,
            'upsFilters' => AssetDashboardService::UPS_FILTERS,
            'upsStatuses' => IctAsset::UPS_STATUSES,
            'formSupportsUps' => $this->formSupportsUps(),
            'formSupportsProcessor' => $this->formSupportsProcessor(),
            ...$this->columnViewData(),
            ...$this->exportViewData('assets.export_inventory'),
            'districts' => $districts,
            'formDistricts' => $this->actorRegionDistricts(),
            'actorRegion' => $this->actorRegion(),
            ...$this->regionViewData(),
            'models' => $models,
            'departments' => Department::query()->orderBy('department_name')->get(),
            'employees' => $employees,
        ]);
    }
}
