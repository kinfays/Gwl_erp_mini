<?php

namespace App\Livewire\Assets\Concerns;

use Livewire\Attributes\Url;

/**
 * The District and Model filters of the Assets, Phones and Network lists. Both take several values at once and live in
 * the URL as arrays (?districts[]=3&districts[]=7, ?models[]=12), so links and the back button work.
 *
 * What the browser sends is never trusted: every id is intersected with what the actor may SEE (ScopesAssetsByActor::
 * permittedDistrictIds() / permittedModelIds()) each time the filters are read, so a hand-edited URL or a tampered
 * request is ignored, not obeyed. An old single-district link (?district=5 or ?district_id=5) is still accepted and
 * folded into the list. The component needs a CATEGORY constant, WithPagination and ScopesAssetsByActor.
 */
trait FiltersByLocationAndModel
{
    /** Selected district ids; empty means every district the actor may see. */
    #[Url(as: 'districts')]
    public array $districtIds = [];

    /** Selected model ids (ict_asset_models.id); empty means every model. */
    #[Url(as: 'models')]
    public array $modelIds = [];

    /** Old single-district links. Read once, converted into $districtIds and cleared. */
    #[Url(as: 'district', except: '')]
    public string $legacyDistrict = '';

    #[Url(as: 'district_id', except: '')]
    public string $legacyDistrictId = '';

    /** Property names that should send the paginator back to page 1. */
    protected function locationModelFilterProperties(): array
    {
        return ['districtIds', 'modelIds', 'legacyDistrict', 'legacyDistrictId'];
    }

    /** Folds an old ?district= / ?district_id= value into the array filter (once), then forgets it. */
    protected function absorbLegacyDistrict(): void
    {
        foreach (['legacyDistrict', 'legacyDistrictId'] as $property) {
            if (ctype_digit($this->{$property})) {
                $this->districtIds = [...$this->districtIds, (int) $this->{$property}];
            }

            $this->{$property} = '';
        }
    }

    /** @return array<int, int> the chosen districts the actor may actually see */
    protected function selectedDistrictIds(): array
    {
        $this->absorbLegacyDistrict();

        return $this->permittedDistrictIds($this->districtIds);
    }

    /** @return array<int, int> the chosen models that occur on assets the actor may see in this list */
    protected function selectedModelIds(): array
    {
        return $this->permittedModelIds($this->modelIds, self::CATEGORY);
    }

    /** The filters as AssetListQuery (and the export links) name them. */
    protected function locationModelFilterValues(): array
    {
        return [
            'districts' => $this->selectedDistrictIds(),
            'models' => $this->selectedModelIds(),
        ];
    }

    public function toggleDistrict(string $id): void
    {
        $allowed = $this->permittedDistrictIds([$id]);

        if ($allowed === []) {
            return;
        }

        $this->districtIds = $this->toggled($this->selectedDistrictIds(), $allowed[0]);
        $this->resetPage();
    }

    public function selectAllDistricts(): void
    {
        $this->districtIds = $this->viewableDistricts()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->resetPage();
    }

    public function clearDistricts(): void
    {
        $this->districtIds = [];
        $this->legacyDistrict = $this->legacyDistrictId = '';
        $this->resetPage();
    }

    public function toggleModel(string $id): void
    {
        $allowed = $this->permittedModelIds([$id], self::CATEGORY);

        if ($allowed === []) {
            return;
        }

        $this->modelIds = $this->toggled($this->selectedModelIds(), $allowed[0]);
        $this->resetPage();
    }

    public function selectAllModels(): void
    {
        $this->modelIds = $this->viewableModels(self::CATEGORY)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->resetPage();
    }

    public function clearModels(): void
    {
        $this->modelIds = [];
        $this->resetPage();
    }

    /** @param array<int, int> $selected */
    private function toggled(array $selected, int $id): array
    {
        return in_array($id, $selected, true)
            ? array_values(array_diff($selected, [$id]))
            : [...$selected, $id];
    }

    /**
     * Options and current choice for the two <x-ui.multi-select> controls.
     *
     * @return array<string, mixed>
     */
    protected function locationModelViewData(): array
    {
        $seesAll = $this->actorSeesAllRegions();

        return [
            'districtOptions' => $this->viewableDistricts()->mapWithKeys(fn ($district) => [
                (string) $district->id => $district->district_name.($seesAll && $district->region ? ' ('.$district->region->region_name.')' : ''),
            ])->all(),
            'selectedDistricts' => array_map('strval', $this->selectedDistrictIds()),
            'modelOptions' => $this->viewableModels(self::CATEGORY)->mapWithKeys(fn ($model) => [
                (string) $model->id => trim(($model->manufacturer?->name ?? '').' '.$model->name),
            ])->all(),
            'selectedModels' => array_map('strval', $this->selectedModelIds()),
        ];
    }
}
