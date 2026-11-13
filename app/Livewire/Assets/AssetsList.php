<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\AsksAssignmentChangeReason;
use App\Livewire\Assets\Concerns\CreatesModelInline;
use App\Livewire\Assets\Concerns\ExportsAssetLists;
use App\Livewire\Assets\Concerns\FiltersByAnalytics;
use App\Livewire\Assets\Concerns\FiltersByAssetTypes;
use App\Livewire\Assets\Concerns\ResetsModelOnTypeChange;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Assets\Concerns\SearchesEmployeePicker;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Concerns\HasSelectableColumns;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Rules\UniqueAssetName;
use App\Services\Assets\AssetDashboardService;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use App\Services\Assets\AssetRecordService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AssetsList extends Component
{
    use AsksAssignmentChangeReason;
    use CreatesModelInline;
    use EnforcesModuleAccess;
    use ExportsAssetLists;
    use FiltersByAnalytics;
    use FiltersByAssetTypes;
    use HasSelectableColumns;
    use ResetsModelOnTypeChange;
    use ScopesAssetsByActor;
    use SearchesEmployeePicker;
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

    /** True while the "nobody is assigned" warning is open; the save goes ahead only after it is confirmed. */
    public bool $confirmNoAssignee = false;

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
        'is_general_use' => false,
        'department_id' => null,
        'tag_no' => '',
        'region_id' => null,
        'district_id' => null,
        'actual_location' => '',
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

    /**
     * Options for the "Assigned To" picker, 20 at most, matched on name or staff ID. Renderless: no page re-render per
     * keystroke. The save still validates the posted id as it always did.
     *
     * @return array<int, array{value: int, label: string, description: string}>
     */
    #[Renderless]
    public function searchEmployees(string $term = ''): array
    {
        $this->authorizeAction($this->editingAssetId ? 'assets.edit' : 'assets.create');

        return $this->employeeSearchOptions($term);
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
            ->with('previousAssignment.employee')
            ->findOrFail($assetId);

        $this->editingAssetId = $asset->id;
        $this->showForm = true;
        // Read-only: derived from the assignment history, never typed in or stored on the asset.
        $this->previousAssignedLabel = $asset->previousHolder()?->full_name;
        $this->rememberOriginalAssignee($asset->assigned_to_employee_id);
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
            'is_general_use' => (bool) $asset->is_general_use,
            'department_id' => $asset->department_id,
            'tag_no' => (string) $asset->tag_no,
            'region_id' => $this->actorRegionId(),
            'district_id' => $asset->district_id,
            'actual_location' => (string) $asset->actual_location,
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
        $this->confirmNoAssignee = false;
        $this->closeNewModel();
        $this->resetForm();
    }

    /** "Go back" on the warning: back to the form, nothing saved. */
    public function dismissNoAssigneeWarning(): void
    {
        $this->confirmNoAssignee = false;
    }

    /** "Save anyway" on the warning. Everything is validated again, so nothing is skipped but the warning itself. */
    public function saveWithoutAssignee(): void
    {
        $this->save(true);
    }

    /**
     * "Assigned To" is not required. A device that is issued to a person (IctAsset::PERSONALLY_ASSIGNED_TYPES), is not
     * general use and has nobody assigned only raises a warning first, which the user confirms or goes back from.
     */
    public function save(bool $confirmedNoAssignee = false): void
    {
        $permission = $this->editingAssetId ? 'assets.edit' : 'assets.create';
        $this->authorizeAction($permission);

        $regionId = $this->requireActorRegionId();

        $validated = $this->validate($this->rules())['form'];
        $validated['region_id'] = $regionId;

        if (! $confirmedNoAssignee && $this->lacksNeededAssignee($validated)) {
            $this->confirmNoAssignee = true;

            return;
        }

        $this->confirmNoAssignee = false;

        $existing = $this->editingAssetId
            ? $this->scopeAssetsForActor(IctAsset::query())->where('device_category', self::CATEGORY)->findOrFail($this->editingAssetId)
            : null;

        app(AssetRecordService::class)->save($validated, self::CATEGORY, $existing, $this->assignmentChangeAnswer());

        $this->dispatch('toast', type: 'success', message: $existing ? 'Asset updated successfully.' : 'Asset created successfully.');
        $this->closeForm();
    }

    /** The same test the dashboard's "Unassigned" card uses: a personally issued type, not general use, no holder. */
    protected function lacksNeededAssignee(array $validated): bool
    {
        return IctAsset::isPersonallyAssignedType($validated['asset_type'] ?? null)
            && ! ($validated['is_general_use'] ?? false)
            && blank($validated['assigned_to_employee_id'] ?? null);
    }

    protected function rules(): array
    {
        return [
            'form.asset_name' => ['required', 'string', 'max:255', new UniqueAssetName($this->editingAssetId)],
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
            'form.is_general_use' => ['boolean'],
            // A general-use asset has no holder: any assignee sent is ignored (and cleared by AssetRecordService).
            // Optional: nobody assigned only raises the warning in save().
            'form.assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'form.department_id' => ['required', 'integer', 'exists:departments,id'],
            'form.tag_no' => ['nullable', 'string', 'max:100'],
            'form.district_id' => ['required', 'integer', $this->actorRegionDistrictRule()],
            'form.actual_location' => ['nullable', 'string', 'max:255'],
            'form.purchased_at' => ['nullable', 'date'],
            'form.notes' => ['nullable', 'string'],
            ...$this->assignmentChangeRules(),
        ];
    }

    protected function messages(): array
    {
        return [
            'form.district_id.exists' => 'The selected location is not in your region.',
            'form.ups_status.required' => 'Say whether the UPS is working or spoiled.',
            ...$this->assignmentChangeMessages(),
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
            'form.is_general_use' => 'general use',
            'form.department_id' => 'department',
            'form.tag_no' => 'asset tag',
            'form.district_id' => 'location',
            'form.actual_location' => 'actual location',
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
        // Ticking "General use" drops the holder, like the pool-car checkbox on vehicles.
        if ($key === 'is_general_use' && $value) {
            $this->form['assigned_to_employee_id'] = null;
        }

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
        $this->forgetOriginalAssignee();
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
            'is_general_use' => false,
            'department_id' => null,
            'tag_no' => '',
            'region_id' => $this->actorRegionId(),
            'district_id' => null,
            'actual_location' => '',
            'purchased_at' => null,
            'notes' => '',
        ];
    }

    public function render()
    {
        $assets = $this->scopeAssetsForViewing(
            IctAsset::query()->with(['assetModel.manufacturer', 'assignedTo', 'department', 'district', 'region'])
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
            ...$this->assignmentChangeViewData(),
            ...$this->columnViewData(),
            ...$this->exportViewData('assets.export_inventory'),
            'districts' => $districts,
            'formDistricts' => $this->actorRegionDistricts(),
            'actorRegion' => $this->actorRegion(),
            ...$this->regionViewData(),
            'models' => $models,
            ...$this->addModelViewData(),
            'departments' => Department::query()->orderBy('department_name')->get(),
            'assignedToLabel' => $this->showForm ? $this->employeeLabel($this->form['assigned_to_employee_id'] ?? null) : '',
        ]);
    }
}
