<?php

namespace App\Livewire\Assets\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\Permission;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ModelsManager extends Component
{
    use EnforcesModuleAccess;

    public string $name = '';

    public string $category = '';

    public string $manufacturer = '';

    public string $notes = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public string $editingCategory = '';

    public string $editingManufacturer = '';

    public string $editingNotes = '';

    public bool $editingIsActive = true;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        if (! $this->canManageModels()) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:ict_asset_models,name'],
            'category' => ['required', Rule::in($this->categoryOptions())],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $model = IctAssetModel::create([
            ...$validated,
            'is_active' => true,
        ]);

        AuditLog::record('create_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, null, $model->toArray());

        $this->reset('name', 'category', 'manufacturer', 'notes');
        $this->dispatch('toast', type: 'success', message: 'Model created successfully.');
    }

    public function edit(int $modelId): void
    {
        $model = IctAssetModel::findOrFail($modelId);

        $this->editingId = $model->id;
        $this->editingName = $model->name;
        $this->editingCategory = $model->category;
        $this->editingManufacturer = (string) $model->manufacturer;
        $this->editingNotes = (string) $model->notes;
        $this->editingIsActive = (bool) $model->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingName', 'editingCategory', 'editingManufacturer', 'editingNotes', 'editingIsActive']);
    }

    public function update(): void
    {
        $model = IctAssetModel::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', Rule::unique('ict_asset_models', 'name')->ignore($model->id)],
            'editingCategory' => ['required', Rule::in($this->categoryOptions())],
            'editingManufacturer' => ['nullable', 'string', 'max:255'],
            'editingNotes' => ['nullable', 'string'],
        ]);

        $old = $model->toArray();
        $model->update([
            'name' => $validated['editingName'],
            'category' => $validated['editingCategory'],
            'manufacturer' => $validated['editingManufacturer'],
            'notes' => $validated['editingNotes'],
            'is_active' => $this->editingIsActive,
        ]);

        AuditLog::record('update_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, $old, $model->fresh()->toArray());

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: 'Model updated successfully.');
    }

    public function toggleActive(int $modelId): void
    {
        $model = IctAssetModel::findOrFail($modelId);
        $old = $model->toArray();
        $model->update(['is_active' => ! $model->is_active]);

        AuditLog::record('toggle_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, $old, $model->fresh()->toArray());

        $this->dispatch('toast', type: 'success', message: 'Model status updated.');
    }

    public function delete(int $modelId): void
    {
        $model = IctAssetModel::withCount('assets')->findOrFail($modelId);

        if ($model->assets_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'You cannot delete a model that is still assigned to assets.');

            return;
        }

        $old = $model->toArray();
        $model->delete();

        AuditLog::record('delete_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $modelId, $old, null);
        $this->dispatch('toast', type: 'success', message: 'Model deleted successfully.');
    }

    protected function categoryOptions(): array
    {
        return collect(IctAsset::ASSET_TYPES)->flatMap(fn ($types) => array_keys($types))->all();
    }

    protected function canManageModels(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_models'));
    }

    public function render()
    {
        return view('livewire.assets.settings.models-manager', [
            'models' => IctAssetModel::query()
                ->withCount('assets')
                ->orderBy('category')
                ->orderBy('name')
                ->get(),
            'assetTypeGroups' => IctAsset::ASSET_TYPES,
        ]);
    }
}
