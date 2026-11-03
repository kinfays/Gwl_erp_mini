<?php

namespace App\Http\Controllers\Assets;

use App\Models\IctAsset;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use Illuminate\Database\Eloquent\Builder;

class PhonesListExportController extends AssetListExportController
{
    protected function listKey(): string
    {
        return AssetListColumns::PHONES;
    }

    protected function title(): string
    {
        return 'Phone devices';
    }

    protected function slug(): string
    {
        return 'phones';
    }

    protected function table(): string
    {
        return 'ict_assets';
    }

    protected function listRoute(): string
    {
        return 'assets.phones';
    }

    protected function query(array $filters): Builder
    {
        return app(AssetListQuery::class)->assets(
            $this->scopeAssetsForViewing(IctAsset::query()->with(AssetListColumns::relations(AssetListColumns::PHONES))),
            IctAsset::DEVICE_CATEGORY_PHONE,
            $filters
        )->latest()->orderByDesc('id');
    }
}
