<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Assets\Concerns\SearchesAssetPicker;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use App\Services\Assets\AssetModelService;
use App\Services\Assets\MaintenanceListService;
use App\Services\Assets\MaintenanceService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithPagination;

class MaintenanceLog extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use SearchesAssetPicker;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $type = '';

    /**
     * "Due for maintenance" = an open ticket (status='Open'), the closest
     * existing signal since there's no due-date field on this table today.
     * This surfaces what the dashboard's old "Maintenance Check" panel used
     * to show.
     */
    public bool $dueOnly = false;

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingMaintenanceId = null;

    /** The completion date a record already had when it was opened for editing, so toggling the status never loses it. */
    public ?string $storedCompletionDate = null;

    /**
     * form.asset_type only narrows the asset picker; it is not stored. The asset is re-resolved on save from the
     * actor's own scope AND this type, so a posted id for anything else is refused.
     */
    public array $form = [
        'asset_type' => '',
        'ict_asset_id' => null,
        'maintenance_type' => '',
        'status' => IctAssetMaintenance::STATUS_OPEN,
        'reported_date' => null,
        'completion_date' => null,
        'technician' => '',
        'notes' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'type', 'dueOnly', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorizeAction();
        $this->editingMaintenanceId = null;
        $this->showForm = true;
        $this->resetForm();
    }

    public function openEdit(int $id): void
    {
        $this->authorizeAction();

        $maintenance = IctAssetMaintenance::query()
            ->with('asset')
            ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForActor($assetQuery))
            ->findOrFail($id);

        $this->resetValidation();
        $this->editingMaintenanceId = $maintenance->id;
        $this->showForm = true;
        $this->storedCompletionDate = $maintenance->completion_date?->toDateString();
        $this->form = [
            'asset_type' => (string) $maintenance->asset?->asset_type,
            'ict_asset_id' => $maintenance->ict_asset_id,
            'maintenance_type' => (string) $maintenance->maintenance_type,
            'status' => (string) $maintenance->status,
            // Records from before the reported date existed were backfilled by the migration; this is only a safety net.
            'reported_date' => ($maintenance->reported_date ?? $maintenance->created_at)?->toDateString(),
            'completion_date' => $this->storedCompletionDate,
            'technician' => (string) $maintenance->technician,
            'notes' => (string) $maintenance->notes,
        ];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingMaintenanceId = null;
        $this->resetValidation();
        $this->resetForm();
    }

    /** A different type means a different list of assets: the chosen one no longer applies. */
    public function updatedFormAssetType(): void
    {
        $this->form['ict_asset_id'] = null;
        $this->resetErrorBag('form.ict_asset_id');
    }

    /**
     * The completion date exists only while the status is Completed: it appears prefilled with today (or the date the
     * record already had), and is dropped again if the status moves away from Completed before saving.
     */
    public function updatedFormStatus(string $status): void
    {
        if ($status === IctAssetMaintenance::STATUS_COMPLETED) {
            if (blank($this->form['completion_date'] ?? null)) {
                $this->form['completion_date'] = $this->storedCompletionDate ?: today()->toDateString();
            }

            return;
        }

        $this->form['completion_date'] = null;
        $this->resetErrorBag('form.completion_date');
    }

    /**
     * Options for the asset picker: only assets of the chosen type, only within the actor's own scope (the one that
     * governs writes), matched on name or serial number, 20 at most. Renderless: no re-render of the page per keystroke.
     *
     * @return array<int, array{value: int, label: string, description: string}>
     */
    #[Renderless]
    public function searchAssets(string $term = ''): array
    {
        $this->authorizeAction();

        $type = (string) ($this->form['asset_type'] ?? '');

        if (! in_array($type, $this->assetTypeCodes(), true)) {
            return [];
        }

        return $this->assetSearchOptions(
            $this->scopeAssetsForActor(IctAsset::query())->where('asset_type', $type),
            $term
        );
    }

    public function save(): void
    {
        $this->authorizeAction();
        $validated = $this->validate($this->rules());
        $payload = $validated['form'];

        // Never trust the posted id: resolve it again through the actor's scope AND the chosen type.
        $asset = $this->scopeAssetsForActor(IctAsset::query())
            ->where('asset_type', $payload['asset_type'])
            ->find((int) $payload['ict_asset_id']);

        if (! $asset) {
            throw ValidationException::withMessages([
                'form.ict_asset_id' => 'Pick an asset of the selected type from the list.',
            ]);
        }

        $existing = $this->editingMaintenanceId
            ? IctAssetMaintenance::query()
                ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForActor($assetQuery))
                ->findOrFail($this->editingMaintenanceId)
            : null;

        app(MaintenanceService::class)->save($asset, $payload, $existing, auth()->id());

        $this->dispatch('toast', type: 'success', message: $existing ? 'Maintenance record updated.' : 'Maintenance record added.');
        $this->closeForm();
    }

    protected function rules(): array
    {
        $completed = ($this->form['status'] ?? null) === IctAssetMaintenance::STATUS_COMPLETED;

        return [
            'form.asset_type' => ['required', Rule::in($this->assetTypeCodes())],
            'form.ict_asset_id' => ['required', 'integer', 'exists:ict_assets,id'],
            'form.maintenance_type' => ['required', 'string', 'max:120'],
            'form.status' => ['required', Rule::in(IctAssetMaintenance::STATUSES)],
            'form.reported_date' => ['required', 'date', 'before_or_equal:today'],
            // Required (and bounded) only for Completed; for anything else it is not read at all and is dropped on save.
            'form.completion_date' => $completed
                ? ['required', 'date', 'after_or_equal:form.reported_date', 'before_or_equal:today']
                : ['nullable'],
            'form.technician' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.completion_date.required' => 'Enter the date the work was completed.',
            'form.completion_date.after_or_equal' => 'The completion date cannot be before the reported date.',
            'form.completion_date.before_or_equal' => 'The completion date cannot be in the future.',
            'form.reported_date.before_or_equal' => 'The reported date cannot be in the future.',
            'form.asset_type.required' => 'Choose the asset type first.',
            'form.ict_asset_id.required' => 'Select the asset.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.asset_type' => 'asset type',
            'form.ict_asset_id' => 'asset',
            'form.maintenance_type' => 'maintenance type',
            'form.reported_date' => 'reported date',
            'form.completion_date' => 'completion date',
        ];
    }

    /** Every asset type code, across Assets, Phones and Network (maintenance covers any device). @return array<int, string> */
    protected function assetTypeCodes(): array
    {
        return AssetModelService::categoryOptions();
    }

    protected function authorizeAction(): void
    {
        $user = $this->actor();

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission('assets.manage_maintenance')) {
            abort(403, 'You do not have permission to manage maintenance.');
        }
    }

    protected function resetForm(): void
    {
        $this->storedCompletionDate = null;
        $this->form = [
            'asset_type' => '',
            'ict_asset_id' => null,
            'maintenance_type' => '',
            'status' => IctAssetMaintenance::STATUS_OPEN,
            'reported_date' => today()->toDateString(),
            'completion_date' => null,
            'technician' => '',
            'notes' => '',
        ];
    }

    /** The list's filters, named as MaintenanceListService (and the export links) name them. */
    protected function listFilters(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'type' => $this->type,
            'due' => $this->dueOnly,
        ];
    }

    public function render()
    {
        $maintenance = app(MaintenanceListService::class)
            ->apply(
                IctAssetMaintenance::query()
                    ->with(['asset.region', 'asset.district'])
                    ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForViewing($assetQuery)),
                $this->listFilters()
            )
            ->latest()
            ->paginate($this->perPage);

        // The picked asset's label, looked up through the actor's scope (never a bare find on the posted id).
        $selectedAsset = $this->showForm && ($this->form['ict_asset_id'] ?? null)
            ? $this->scopeAssetsForActor(IctAsset::query())->find((int) $this->form['ict_asset_id'], ['id', 'asset_name'])
            : null;

        $statusOptions = IctAssetMaintenance::query()->select('status')->distinct()->orderBy('status')->pluck('status');
        $typeOptions = IctAssetMaintenance::query()->select('maintenance_type')->distinct()->orderBy('maintenance_type')->pluck('maintenance_type');

        $dueCount = IctAssetMaintenance::query()
            ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForViewing($assetQuery))
            ->where('status', IctAssetMaintenance::STATUS_OPEN)
            ->count();

        $filters = $this->listFilters();
        $user = $this->actor();

        return view('livewire.assets.maintenance-log', [
            'maintenance' => $maintenance,
            'assetTypeGroups' => IctAsset::ASSET_TYPES,
            'selectedAssetLabel' => $selectedAsset?->asset_name ?? '',
            'formStatuses' => IctAssetMaintenance::STATUSES,
            'completedStatus' => IctAssetMaintenance::STATUS_COMPLETED,
            'statusOptions' => $statusOptions,
            'typeOptions' => $typeOptions,
            'dueCount' => $dueCount,
            'canExport' => $user->hasRoles('super_admin') || $user->hasPermission('assets.export_maintenance'),
            'exportParams' => array_filter([
                'q' => $filters['search'],
                'status' => $filters['status'],
                'type' => $filters['type'],
                'due' => $filters['due'] ? 1 : null,
            ], fn ($value) => filled($value)),
            'filtersActive' => $this->search !== '' || $this->status !== '' || $this->type !== '' || $this->dueOnly,
            ...$this->regionViewData(),
        ]);
    }
}
