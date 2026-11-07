<?php

namespace App\Livewire\Transport\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Services\Transport\VehicleCatalogService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Transport > Settings > Vehicle Models. Thin: every change goes through VehicleCatalogService. The catalogue is shared
 * (not region-scoped) and this screen shows vehicle COUNTS only.
 */
class VehicleModelsManager extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';

    public int|string|null $filterBrandId = null;

    public int|string $brandId = '';

    public string $name = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public int|string $editingBrandId = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
        $this->authorizeCatalog();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterBrandId(): void
    {
        $this->resetPage();
    }

    public function save(VehicleCatalogService $catalog): void
    {
        $this->authorizeCatalog();

        $this->validate(
            ['brandId' => ['required', 'integer', $this->activeBrandRule()]],
            attributes: ['brandId' => 'brand'],
        );

        $brand = VehicleBrand::query()->findOrFail((int) $this->brandId);

        $this->validate(['name' => $catalog->modelNameRules($brand->id)], attributes: ['name' => 'model name']);

        $catalog->createModel($brand, $this->name);

        // Keep the brand, so a run of models for one brand can be typed in a row.
        $this->reset('name');
        $this->dispatch('toast', type: 'success', message: 'Model created successfully.');
    }

    public function edit(int $modelId): void
    {
        $this->authorizeCatalog();

        $model = VehicleModel::query()->findOrFail($modelId);

        $this->resetValidation();
        $this->editingId = $model->id;
        $this->editingName = $model->name;
        $this->editingBrandId = $model->vehicle_brand_id;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'editingName', 'editingBrandId']);
    }

    public function update(VehicleCatalogService $catalog): void
    {
        $this->authorizeCatalog();

        $model = VehicleModel::query()->findOrFail($this->editingId);

        $this->validate(
            ['editingBrandId' => ['required', 'integer', $this->activeBrandRule($model->vehicle_brand_id)]],
            attributes: ['editingBrandId' => 'brand'],
        );
        $this->validate(['editingName' => $catalog->modelNameRules((int) $this->editingBrandId, $model->id)], attributes: ['editingName' => 'model name']);

        try {
            $vehicles = $catalog->updateModel($model, $this->editingName, (int) $this->editingBrandId);
        } catch (ValidationException $exception) {
            $this->addError('editingName', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: $vehicles > 0
            ? "Model updated. {$vehicles} ".str('vehicle')->plural($vehicles).' now show the new details.'
            : 'Model updated successfully.');
    }

    public function toggleActive(int $modelId, VehicleCatalogService $catalog): void
    {
        $this->authorizeCatalog();

        $model = VehicleModel::query()->findOrFail($modelId);
        $catalog->setModelActive($model, ! $model->is_active);

        $this->dispatch('toast', type: 'success', message: 'Model status updated.');
    }

    /** Only active brands can be picked, except the one the model already sits under. */
    protected function activeBrandRule(?int $currentBrandId = null): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('vehicle_brands', 'id')->where(
            fn ($query) => $query->where('is_active', true)->when($currentBrandId, fn ($inner, $id) => $inner->orWhere('id', $id))
        );
    }

    protected function authorizeCatalog(): void
    {
        abort_unless(VehicleCatalogService::canManage(auth()->user()), 403);
    }

    public function render(VehicleCatalogService $catalog)
    {
        $term = trim($this->search);
        $counts = $catalog->modelVehicleCounts();

        $models = VehicleModel::query()
            ->with('brand')
            ->when($this->filterBrandId, fn ($query, $id) => $query->where('vehicle_brand_id', (int) $id))
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy(VehicleBrand::query()->select('name')->whereColumn('vehicle_brands.id', 'vehicle_models.vehicle_brand_id'))
            ->orderBy('name')
            ->paginate(25);

        $editing = $this->editingId ? VehicleModel::query()->with('brand')->find($this->editingId) : null;
        $editingKey = $editing?->brand ? VehicleCatalogService::modelKey($editing->brand->name, $editing->name) : null;

        return view('livewire.transport.settings.vehicle-models-manager', [
            'models' => $models,
            'vehicleCounts' => $models->getCollection()->mapWithKeys(fn (VehicleModel $model) => [
                $model->id => $model->brand ? ($counts[VehicleCatalogService::modelKey($model->brand->name, $model->name)] ?? 0) : 0,
            ])->all(),
            'editing' => $editing,
            'editingVehicleCount' => $editingKey ? ($counts[$editingKey] ?? 0) : 0,
            'allBrandOptions' => VehicleBrand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($brand) => ['value' => $brand->id, 'label' => $brand->name])->all(),
            // Brands a model can be added to or moved to: active ones, plus the brand the edited model already has.
            'brandChoices' => VehicleBrand::query()
                ->where(fn ($query) => $query->where('is_active', true)->when($editing?->vehicle_brand_id, fn ($inner, $id) => $inner->orWhere('id', $id)))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ]);
    }
}
