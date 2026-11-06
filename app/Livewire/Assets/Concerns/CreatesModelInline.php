<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Services\Assets\AssetModelService;
use Illuminate\Validation\Rule;

/**
 * The "Add new model" panel on the Assets, Phones and Network forms: creates a model without leaving the form (same
 * rules, duplicate check and audit row as the Asset Models screen, through AssetModelService), then selects it.
 *
 * The component must define CATEGORY (its device category), authorizeAction(string $permission), an `editingAssetId`
 * and a `form` array with `asset_type` and `ict_asset_model_id`, and use ScopesAssetsByActor. Its view draws the panel
 * with <x-assets.add-model-button> and <x-assets.add-model-panel>.
 */
trait CreatesModelInline
{
    /** The inline panel is separate from $form so adding a model never touches what was typed there. */
    public bool $showNewModel = false;

    public array $newModel = [
        'name' => '',
        'category' => '',
        'manufacturer_id' => '',
    ];

    public function openNewModel(): void
    {
        $this->authorizeNewModel();

        $this->resetValidation(['newModel.name', 'newModel.category', 'newModel.manufacturer_id']);
        $this->newModel = ['name' => '', 'category' => (string) ($this->form['asset_type'] ?? ''), 'manufacturer_id' => ''];
        $this->showNewModel = true;
    }

    public function closeNewModel(): void
    {
        $this->showNewModel = false;
        $this->newModel = ['name' => '', 'category' => '', 'manufacturer_id' => ''];
        $this->resetValidation(['newModel.name', 'newModel.category', 'newModel.manufacturer_id']);
    }

    /**
     * Nothing typed in $form is touched, apart from the model (and the type, when none was chosen yet, so the new model
     * appears in the list).
     */
    public function saveNewModel(): void
    {
        $this->authorizeNewModel();

        $service = app(AssetModelService::class);

        // With a type already chosen the model belongs to it: the form lists only that type's models.
        if (filled($this->form['asset_type'] ?? null)) {
            $this->newModel['category'] = (string) $this->form['asset_type'];
        }

        $validated = $this->validate([
            ...$service->createRules('newModel.'),
            'newModel.category' => ['required', Rule::in(array_keys(IctAsset::ASSET_TYPES[static::CATEGORY]))],
        ], attributes: [
            'newModel.name' => 'model name',
            'newModel.category' => 'type',
            'newModel.manufacturer_id' => 'manufacturer',
        ])['newModel'];

        $model = $service->create($validated);

        if (($this->form['asset_type'] ?? '') !== $model->category) {
            $this->form['asset_type'] = $model->category;

            // The Assets form drops UPS / processor values a new type does not carry.
            if (method_exists($this, 'updatedForm')) {
                $this->updatedForm($model->category, 'asset_type');
            }
        }

        $this->form['ict_asset_model_id'] = $model->id;
        $this->closeNewModel();
        $this->dispatch('toast', type: 'success', message: 'Model created and selected.');
    }

    protected function authorizeNewModel(): void
    {
        // You must be allowed to use this form at all, and to create models.
        $this->authorizeAction($this->editingAssetId ? 'assets.edit' : 'assets.create');

        abort_unless(AssetModelService::canManage($this->actor()), 403, 'You do not have permission to add models.');
    }

    /** @return array{canAddModel: bool, newModelManufacturers: \Illuminate\Support\Collection} */
    protected function addModelViewData(): array
    {
        return [
            'canAddModel' => AssetModelService::canManage($this->actor()),
            'newModelManufacturers' => $this->showNewModel
                ? IctAssetManufacturer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ];
    }
}
