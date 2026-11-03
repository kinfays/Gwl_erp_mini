<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\IctAsset;
use App\Services\Assets\AssetListQuery;
use Livewire\Attributes\Url;

/**
 * The device-type filter of the Assets, Phones and Network lists, which takes several types at once. The choice is
 * kept as a comma-joined string (?type=PC,Laptop), so it is bookmarkable and a single old-style ?type=Laptop still
 * works; AssetListQuery turns it into a whereIn. The component needs a CATEGORY constant and WithPagination.
 */
trait FiltersByAssetTypes
{
    /** Selected asset types, comma-joined; empty means all. */
    #[Url(as: 'type', except: '')]
    public string $assetType = '';

    /** @return array<int, string> the selected types that really belong to this list, in the list's own order */
    protected function selectedAssetTypes(): array
    {
        $chosen = AssetListQuery::types($this->assetType);

        return array_values(array_filter(array_keys(IctAsset::ASSET_TYPES[self::CATEGORY]), fn (string $type) => in_array($type, $chosen, true)));
    }

    public function toggleAssetType(string $type): void
    {
        if (! array_key_exists($type, IctAsset::ASSET_TYPES[self::CATEGORY])) {
            return;
        }

        $selected = $this->selectedAssetTypes();

        $selected = in_array($type, $selected, true) ? array_values(array_diff($selected, [$type])) : [...$selected, $type];

        $this->assetType = implode(',', $selected);
        $this->resetPage();
    }

    public function clearAssetTypes(): void
    {
        $this->assetType = '';
        $this->resetPage();
    }
}
