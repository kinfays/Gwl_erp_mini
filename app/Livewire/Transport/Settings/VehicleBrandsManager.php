<?php

namespace App\Livewire\Transport\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\VehicleBrand;
use App\Services\Transport\VehicleCatalogService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Transport > Settings > Vehicle Brands. Thin: every change goes through VehicleCatalogService. The catalogue is shared
 * (not region-scoped) and this screen shows vehicle COUNTS only.
 */
class VehicleBrandsManager extends Component
{
    use EnforcesModuleAccess;

    public string $search = '';

    public string $name = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
        $this->authorizeCatalog();
    }

    public function save(VehicleCatalogService $catalog): void
    {
        $this->authorizeCatalog();

        $this->validate(['name' => $catalog->brandNameRules()], attributes: ['name' => 'brand name']);

        $catalog->createBrand($this->name);

        $this->reset('name');
        $this->dispatch('toast', type: 'success', message: 'Brand created successfully.');
    }

    public function edit(int $brandId): void
    {
        $this->authorizeCatalog();

        $brand = VehicleBrand::query()->findOrFail($brandId);

        $this->resetValidation();
        $this->editingId = $brand->id;
        $this->editingName = $brand->name;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'editingName']);
    }

    public function update(VehicleCatalogService $catalog): void
    {
        $this->authorizeCatalog();

        $brand = VehicleBrand::query()->findOrFail($this->editingId);

        $this->validate(['editingName' => $catalog->brandNameRules($brand->id)], attributes: ['editingName' => 'brand name']);

        try {
            $vehicles = $catalog->updateBrand($brand, $this->editingName);
        } catch (ValidationException $exception) {
            $this->addError('editingName', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: $vehicles > 0
            ? "Brand updated. {$vehicles} ".str('vehicle')->plural($vehicles).' now show the new name.'
            : 'Brand updated successfully.');
    }

    public function toggleActive(int $brandId, VehicleCatalogService $catalog): void
    {
        $this->authorizeCatalog();

        $brand = VehicleBrand::query()->findOrFail($brandId);
        $catalog->setBrandActive($brand, ! $brand->is_active);

        $this->dispatch('toast', type: 'success', message: 'Brand status updated.');
    }

    protected function authorizeCatalog(): void
    {
        abort_unless(VehicleCatalogService::canManage(auth()->user()), 403);
    }

    public function render(VehicleCatalogService $catalog)
    {
        $term = trim($this->search);
        $counts = $catalog->brandVehicleCounts();

        $brands = VehicleBrand::query()
            ->withCount('models')
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('name')
            ->get();

        $editing = $this->editingId ? $brands->firstWhere('id', $this->editingId) ?? VehicleBrand::query()->find($this->editingId) : null;

        return view('livewire.transport.settings.vehicle-brands-manager', [
            'brands' => $brands,
            'vehicleCounts' => $brands->mapWithKeys(fn (VehicleBrand $brand) => [$brand->id => $counts[VehicleCatalogService::brandKey($brand->name)] ?? 0])->all(),
            'editing' => $editing,
            'editingVehicleCount' => $editing ? ($counts[VehicleCatalogService::brandKey($editing->name)] ?? 0) : 0,
        ]);
    }
}
