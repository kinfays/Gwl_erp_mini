<?php

namespace App\Livewire\Assets\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Server side of <x-form.searchable-select search="...">: turns what was typed into at most 20 options (asset name as
 * the label, serial number beside it). The caller passes a query that is ALREADY scoped (actor, asset type, ...); this
 * only narrows it, so a typed term can never widen what a user may reach. Wildcards in the term are escaped.
 */
trait SearchesAssetPicker
{
    protected const ASSET_PICKER_LIMIT = 20;

    /** @return array<int, array{value: int, label: string, description: string}> */
    protected function assetSearchOptions(Builder $scopedQuery, string $term): array
    {
        $term = trim(mb_substr($term, 0, 100));

        return $scopedQuery
            ->when($term !== '', function (Builder $query) use ($term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

                $query->where(function (Builder $inner) use ($like) {
                    $inner->whereRaw("asset_name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("serial_number LIKE ? ESCAPE '!'", [$like]);
                });
            })
            ->orderBy('asset_name')
            ->limit(self::ASSET_PICKER_LIMIT)
            ->get(['id', 'asset_name', 'serial_number'])
            ->map(fn ($asset) => [
                'value' => $asset->id,
                'label' => (string) $asset->asset_name,
                'description' => 'Serial: '.($asset->serial_number ?: 'none'),
            ])
            ->all();
    }
}
