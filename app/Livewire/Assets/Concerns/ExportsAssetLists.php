<?php

namespace App\Livewire\Assets\Concerns;

/**
 * For the Assets lists that offer Excel / PDF export: builds the query string of the export links from what the list
 * is currently filtering and showing (filters + the column selection), so the file is exactly what is on screen.
 * The component must define listFilters() (keys as AssetListQuery names them) and use HasSelectableColumns and
 * ScopesAssetsByActor.
 */
trait ExportsAssetLists
{
    /**
     * @param  string  $permission  the export permission that gates the links (super_admin always passes)
     * @return array{exportParams: array<string, mixed>, canExport: bool, filtersActive: bool}
     */
    protected function exportViewData(string $permission): array
    {
        $filters = $this->listFilters();
        $user = $this->actor();

        $params = ['q' => $filters['search'] ?? '', ...array_diff_key($filters, ['search' => true]), 'cols' => $this->colsParam];

        return [
            'exportParams' => array_filter($params, fn ($value) => filled($value)),
            // Any filter in force: the footer then says the total is of the filtered rows.
            'filtersActive' => array_filter($filters, fn ($value) => filled($value)) !== [],
            'canExport' => $user->hasRoles('super_admin') || $user->hasPermission($permission),
        ];
    }
}
