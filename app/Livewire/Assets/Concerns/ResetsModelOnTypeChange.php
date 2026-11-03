<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\IctAssetModel;

/**
 * The Assets, Phones and Network forms list only the models of the chosen
 * asset type (their render() filters on form.asset_type). When the type
 * changes, a model picked for the previous type would be left selected but
 * no longer offered, so it is cleared here instead of being saved silently.
 */
trait ResetsModelOnTypeChange
{
    public function updatedFormAssetType(string $type): void
    {
        $modelId = $this->form['ict_asset_model_id'] ?? null;

        if (! $modelId) {
            return;
        }

        $modelCategory = IctAssetModel::query()->whereKey($modelId)->value('category');

        if ($modelCategory !== $type) {
            $this->form['ict_asset_model_id'] = null;
        }
    }
}
