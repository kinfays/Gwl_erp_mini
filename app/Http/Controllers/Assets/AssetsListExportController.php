<?php

namespace App\Http\Controllers\Assets;

use App\Models\IctAsset;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use Illuminate\Database\Eloquent\Builder;

/** Computers, laptops, printers, photocopiers, all-in-ones and servers (the Assets list). */
class AssetsListExportController extends AssetListExportController
{
    protected function listKey(): string
    {
        return AssetListColumns::ASSETS;
    }

    protected function title(): string
    {
        return 'Assets';
    }

    protected function slug(): string
    {
        return 'assets';
    }

    protected function table(): string
    {
        return 'ict_assets';
    }

    protected function listRoute(): string
    {
        return 'assets.assets';
    }

    protected function query(array $filters): Builder
    {
        return app(AssetListQuery::class)->assets(
            $this->scopeAssetsForViewing(IctAsset::query()->with(AssetListColumns::relations(AssetListColumns::ASSETS))),
            IctAsset::DEVICE_CATEGORY_ASSET,
            $filters
        )->latest()->orderByDesc('id');
    }
}
