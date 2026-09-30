<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use Livewire\Component;
use Livewire\WithPagination;

class MaintenanceLog extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
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

    public array $form = [
        'ict_asset_id' => null,
        'maintenance_type' => '',
        'status' => 'Open',
        'completion_date' => null,
        'technician' => '',
        'location' => '',
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
            ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForActor($assetQuery))
            ->findOrFail($id);

        $this->editingMaintenanceId = $maintenance->id;
        $this->showForm = true;
        $this->form = [
            'ict_asset_id' => $maintenance->ict_asset_id,
            'maintenance_type' => (string) $maintenance->maintenance_type,
            'status' => (string) $maintenance->status,
            'completion_date' => $maintenance->completion_date?->toDateString(),
            'technician' => (string) $maintenance->technician,
            'location' => (string) $maintenance->location,
            'notes' => (string) $maintenance->notes,
        ];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingMaintenanceId = null;
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorizeAction();
        $validated = $this->validate($this->rules());
        $payload = $validated['form'];

        $asset = $this->scopeAssetsForActor(IctAsset::query())->findOrFail((int) $payload['ict_asset_id']);

        if ($this->editingMaintenanceId) {
            $maintenance = IctAssetMaintenance::query()
                ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForActor($assetQuery))
                ->findOrFail($this->editingMaintenanceId);
            $maintenance->update([
                ...$payload,
                'ict_asset_id' => $asset->id,
                'performed_by_user_id' => auth()->id(),
            ]);
            $message = 'Maintenance record updated.';
        } else {
            IctAssetMaintenance::query()->create([
                ...$payload,
                'ict_asset_id' => $asset->id,
                'performed_by_user_id' => auth()->id(),
            ]);
            $message = 'Maintenance record added.';
        }

        $this->dispatch('toast', type: 'success', message: $message);
        $this->closeForm();
    }

    protected function rules(): array
    {
        return [
            'form.ict_asset_id' => ['required', 'integer', 'exists:ict_assets,id'],
            'form.maintenance_type' => ['required', 'string', 'max:120'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.completion_date' => ['nullable', 'date'],
            'form.technician' => ['nullable', 'string', 'max:255'],
            'form.location' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string'],
        ];
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
        $this->form = [
            'ict_asset_id' => null,
            'maintenance_type' => '',
            'status' => 'Open',
            'completion_date' => null,
            'technician' => '',
            'location' => '',
            'notes' => '',
        ];
    }

    public function render()
    {
        $maintenance = IctAssetMaintenance::query()
            ->with(['asset.region', 'asset.district'])
            ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForViewing($assetQuery))
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('maintenance_type', 'like', $term)
                        ->orWhere('technician', 'like', $term)
                        ->orWhere('location', 'like', $term)
                        ->orWhereHas('asset', fn ($assetQuery) => $assetQuery->where('asset_name', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->type, fn ($query) => $query->where('maintenance_type', $this->type))
            ->when($this->dueOnly, fn ($query) => $query->where('status', 'Open'))
            ->latest()
            ->paginate($this->perPage);

        $assets = $this->scopeAssetsForActor(IctAsset::query())
            ->orderBy('asset_name')
            ->get(['id', 'asset_name', 'serial_number']);

        $statusOptions = IctAssetMaintenance::query()->select('status')->distinct()->orderBy('status')->pluck('status');
        $typeOptions = IctAssetMaintenance::query()->select('maintenance_type')->distinct()->orderBy('maintenance_type')->pluck('maintenance_type');

        $dueCount = IctAssetMaintenance::query()
            ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForViewing($assetQuery))
            ->where('status', 'Open')
            ->count();

        return view('livewire.assets.maintenance-log', [
            'maintenance' => $maintenance,
            'assets' => $assets,
            'statusOptions' => $statusOptions,
            'typeOptions' => $typeOptions,
            'dueCount' => $dueCount,
            ...$this->regionViewData(),
        ]);
    }
}

