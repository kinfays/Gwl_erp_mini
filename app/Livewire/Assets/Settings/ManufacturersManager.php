<?php

namespace App\Livewire\Assets\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\IctAssetManufacturer;
use App\Models\Permission;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ManufacturersManager extends Component
{
    use EnforcesModuleAccess;

    public string $name = '';

    public string $notes = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public string $editingNotes = '';

    public bool $editingIsActive = true;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        if (! $this->canManageManufacturers()) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:ict_asset_manufacturers,name'],
            'notes' => ['nullable', 'string'],
        ]);

        $manufacturer = IctAssetManufacturer::create([
            ...$validated,
            'is_active' => true,
        ]);

        AuditLog::record('create_asset_manufacturer', Permission::MODULE_ASSETS, 'ict_asset_manufacturers', $manufacturer->id, null, $manufacturer->toArray());

        $this->reset('name', 'notes');
        $this->dispatch('toast', type: 'success', message: 'Manufacturer created successfully.');
    }

    public function edit(int $manufacturerId): void
    {
        $manufacturer = IctAssetManufacturer::findOrFail($manufacturerId);

        $this->editingId = $manufacturer->id;
        $this->editingName = $manufacturer->name;
        $this->editingNotes = (string) $manufacturer->notes;
        $this->editingIsActive = (bool) $manufacturer->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingName', 'editingNotes', 'editingIsActive']);
    }

    public function update(): void
    {
        $manufacturer = IctAssetManufacturer::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', Rule::unique('ict_asset_manufacturers', 'name')->ignore($manufacturer->id)],
            'editingNotes' => ['nullable', 'string'],
        ]);

        $old = $manufacturer->toArray();
        $manufacturer->update([
            'name' => $validated['editingName'],
            'notes' => $validated['editingNotes'],
            'is_active' => $this->editingIsActive,
        ]);

        AuditLog::record('update_asset_manufacturer', Permission::MODULE_ASSETS, 'ict_asset_manufacturers', $manufacturer->id, $old, $manufacturer->fresh()->toArray());

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: 'Manufacturer updated successfully.');
    }

    public function toggleActive(int $manufacturerId): void
    {
        $manufacturer = IctAssetManufacturer::findOrFail($manufacturerId);
        $old = $manufacturer->toArray();
        $manufacturer->update(['is_active' => ! $manufacturer->is_active]);

        AuditLog::record('toggle_asset_manufacturer', Permission::MODULE_ASSETS, 'ict_asset_manufacturers', $manufacturer->id, $old, $manufacturer->fresh()->toArray());

        $this->dispatch('toast', type: 'success', message: 'Manufacturer status updated.');
    }

    public function delete(int $manufacturerId): void
    {
        $manufacturer = IctAssetManufacturer::withCount('models')->findOrFail($manufacturerId);

        if ($manufacturer->models_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'You cannot delete a manufacturer that still has models.');

            return;
        }

        $old = $manufacturer->toArray();
        $manufacturer->delete();

        if ($this->editingId === $manufacturerId) {
            $this->cancelEdit();
        }

        AuditLog::record('delete_asset_manufacturer', Permission::MODULE_ASSETS, 'ict_asset_manufacturers', $manufacturerId, $old, null);
        $this->dispatch('toast', type: 'success', message: 'Manufacturer deleted successfully.');
    }

    protected function canManageManufacturers(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_manufacturers'));
    }

    public function render()
    {
        return view('livewire.assets.settings.manufacturers-manager', [
            'manufacturers' => IctAssetManufacturer::query()
                ->withCount('models')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
