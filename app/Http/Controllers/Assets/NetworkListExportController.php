<?php

namespace App\Http\Controllers\Assets;

use App\Models\IctAsset;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use Illuminate\Database\Eloquent\Builder;

/** Network devices. The credentials column is never exported (see AssetListColumns::manifest()). */
class NetworkListExportController extends AssetListExportController
{
    protected function listKey(): string
    {
        return AssetListColumns::NETWORK;
    }

    protected function title(): string
    {
        return 'Network devices';
    }

    protected function slug(): string
    {
        return 'network';
    }

    protected function table(): string
    {
        return 'ict_assets';
    }

    protected function listRoute(): string
    {
        return 'assets.network';
    }

    protected function query(array $filters): Builder
    {
        return app(AssetListQuery::class)->assets(
            $this->scopeAssetsForViewing(IctAsset::query()->with(AssetListColumns::relations(AssetListColumns::NETWORK))),
            IctAsset::DEVICE_CATEGORY_NETWORK,
            $filters
        )->latest()->orderByDesc('id');
    }
}
