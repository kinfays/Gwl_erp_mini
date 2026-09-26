<?php

namespace App\Livewire\Assets\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\Permission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ModelsManager extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;

    public string $name = '';

    public string $category = '';

    public int|string $manufacturer_id = '';

    public string $notes = '';

    public ?TemporaryUploadedFile $image = null;

    public ?int $editingId = null;

    public string $editingName = '';

    public string $editingCategory = '';

    public int|string $editingManufacturerId = '';

    public string $editingNotes = '';

    public bool $editingIsActive = true;

    public ?TemporaryUploadedFile $editingImage = null;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        if (! $this->canManageModels()) {
            abort(403);
        }
    }

    public function updatedImage(): void
    {
        $this->validateOnly('image', ['image' => $this->imageRules()]);
    }

    public function updatedEditingImage(): void
    {
        $this->validateOnly('editingImage', ['editingImage' => $this->imageRules()]);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:ict_asset_models,name'],
            'category' => ['required', Rule::in($this->categoryOptions())],
            'manufacturer_id' => ['nullable', 'integer', $this->selectableManufacturerRule()],
            'notes' => ['nullable', 'string'],
            'image' => $this->imageRules(),
        ]);

        $model = IctAssetModel::create([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'ict_asset_manufacturer_id' => $validated['manufacturer_id'] ?: null,
            'notes' => $validated['notes'],
            'image_path' => $this->image?->store(IctAssetModel::IMAGE_DIRECTORY, 'public') ?: null,
            'is_active' => true,
        ]);

        AuditLog::record('create_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, null, $model->toArray());

        $this->reset('name', 'category', 'manufacturer_id', 'notes', 'image');
        $this->dispatch('toast', type: 'success', message: 'Model created successfully.');
    }

    public function edit(int $modelId): void
    {
        $model = IctAssetModel::findOrFail($modelId);

        $this->resetValidation();
        $this->editingId = $model->id;
        $this->editingName = $model->name;
        $this->editingCategory = $model->category;
        $this->editingManufacturerId = $model->ict_asset_manufacturer_id ?? '';
        $this->editingNotes = (string) $model->notes;
        $this->editingIsActive = (bool) $model->is_active;
        $this->editingImage = null;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingName', 'editingCategory', 'editingManufacturerId', 'editingNotes', 'editingIsActive', 'editingImage']);
    }

    public function update(): void
    {
        $model = IctAssetModel::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', Rule::unique('ict_asset_models', 'name')->ignore($model->id)],
            'editingCategory' => ['required', Rule::in($this->categoryOptions())],
            'editingManufacturerId' => ['nullable', 'integer', $this->selectableManufacturerRule($model->ict_asset_manufacturer_id)],
            'editingNotes' => ['nullable', 'string'],
            'editingImage' => $this->imageRules(),
        ]);

        $old = $model->toArray();
        $oldImagePath = $model->image_path;

        $model->update([
            'name' => $validated['editingName'],
            'category' => $validated['editingCategory'],
            'ict_asset_manufacturer_id' => $validated['editingManufacturerId'] ?: null,
            'notes' => $validated['editingNotes'],
            'is_active' => $this->editingIsActive,
            'image_path' => $this->editingImage?->store(IctAssetModel::IMAGE_DIRECTORY, 'public') ?: $oldImagePath,
        ]);

        // The old file is only dropped once the replacement path is saved.
        if ($model->image_path !== $oldImagePath) {
            $this->deleteImageFile($oldImagePath);
        }

        AuditLog::record('update_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, $old, $model->fresh()->toArray());

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: 'Model updated successfully.');
    }

    public function removeImage(int $modelId): void
    {
        $model = IctAssetModel::findOrFail($modelId);

        if (! $model->image_path) {
            return;
        }

        $old = $model->toArray();
        $model->update(['image_path' => null]);
        $this->deleteImageFile($old['image_path']);

        AuditLog::record('remove_asset_model_image', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, $old, $model->fresh()->toArray());

        $this->reset('editingImage');
        $this->dispatch('toast', type: 'success', message: 'Model image removed.');
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
        $this->deleteImageFile($model->image_path);

        if ($this->editingId === $modelId) {
            $this->cancelEdit();
        }

        AuditLog::record('delete_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $modelId, $old, null);
        $this->dispatch('toast', type: 'success', message: 'Model deleted successfully.');
    }

    protected function categoryOptions(): array
    {
        return collect(IctAsset::ASSET_TYPES)->flatMap(fn ($types) => array_keys($types))->all();
    }

    protected function imageRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
    }

    /**
     * Only active manufacturers can be picked, except the one a model is
     * already linked to — deactivating a manufacturer shouldn't force its
     * existing models off it the next time they're edited.
     */
    protected function selectableManufacturerRule(?int $currentManufacturerId = null): Exists
    {
        return Rule::exists('ict_asset_manufacturers', 'id')->where(
            fn ($query) => $query
                ->where('is_active', true)
                ->when($currentManufacturerId, fn ($inner, $id) => $inner->orWhere('id', $id))
        );
    }

    protected function deleteImageFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function canManageModels(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_models'));
    }

    public function render()
    {
        $editingModel = $this->editingId ? IctAssetModel::query()->find($this->editingId) : null;
        $user = auth()->user();

        return view('livewire.assets.settings.models-manager', [
            'models' => IctAssetModel::query()
                ->with('manufacturer')
                ->withCount('assets')
                ->orderBy('category')
                ->orderBy('name')
                ->get(),
            'assetTypeGroups' => IctAsset::ASSET_TYPES,
            'manufacturers' => IctAssetManufacturer::query()
                ->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($editingModel?->ict_asset_manufacturer_id, fn ($inner, $id) => $inner->orWhere('id', $id)))
                ->orderBy('name')
                ->get(),
            'editingModel' => $editingModel,
            'canManageManufacturers' => $user->hasRoles('super_admin') || $user->hasPermission('assets.manage_manufacturers'),
        ]);
    }
}
