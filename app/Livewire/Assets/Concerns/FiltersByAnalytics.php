<?php

namespace App\Livewire\Assets\Concerns;

use App\Services\Assets\AssetDashboardService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Query-string filters the Assets dashboard drills into (age, warranty, unassigned). Shared by the Assets, Phones and
 * Network lists so a dashboard bucket and the list behind it use the same rules (AssetDashboardService::apply*).
 * Plain bookmarkable URLs: ?age_min=2&age_max=3, ?age=unknown, ?warranty=expired, ?assigned=none.
 */
trait FiltersByAnalytics
{
    /** Whole years, inclusive lower bound. */
    #[Url(as: 'age_min')]
    public string $ageMin = '';

    /** Whole years, exclusive upper bound. */
    #[Url(as: 'age_max')]
    public string $ageMax = '';

    /** 'unknown' = no purchase date. */
    #[Url(as: 'age')]
    public string $ageMode = '';

    /** One of AssetDashboardService::WARRANTY_BUCKETS. */
    #[Url]
    public string $warranty = '';

    /** 'none' = no current holder. */
    #[Url]
    public string $assigned = '';

    /** Property names that should send the paginator back to page 1. */
    protected function analyticsFilterProperties(): array
    {
        return ['ageMin', 'ageMax', 'ageMode', 'warranty', 'assigned'];
    }

    protected function applyAnalyticsFilters(Builder $query): Builder
    {
        $service = app(AssetDashboardService::class);

        if ($this->ageMode === 'unknown') {
            $service->applyAgeRange($query, null, null, true);
        } elseif ($this->ageBound($this->ageMin) !== null || $this->ageBound($this->ageMax) !== null) {
            $service->applyAgeRange($query, $this->ageBound($this->ageMin), $this->ageBound($this->ageMax));
        }

        if (array_key_exists($this->warranty, AssetDashboardService::WARRANTY_BUCKETS)) {
            $service->applyWarranty($query, $this->warranty);
        }

        if ($this->assigned === 'none') {
            $service->applyUnassigned($query);
        }

        return $query;
    }

    /** A hand-edited URL with junk in it is ignored rather than erroring. */
    protected function ageBound(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    /** @return array<string, string> property => chip label, for the filters currently in force */
    protected function analyticsFilterChips(): array
    {
        $chips = [];

        if ($this->ageMode === 'unknown') {
            $chips['ageMode'] = 'Age: unknown purchase date';
        } else {
            $min = $this->ageBound($this->ageMin);
            $max = $this->ageBound($this->ageMax);

            if ($min !== null || $max !== null) {
                $chips['age'] = 'Age: '.match (true) {
                    $min !== null && $max !== null => "{$min}-{$max} years",
                    $min !== null => "{$min}+ years",
                    default => "under {$max} years",
                };
            }
        }

        if (array_key_exists($this->warranty, AssetDashboardService::WARRANTY_BUCKETS)) {
            $chips['warranty'] = 'Warranty: '.AssetDashboardService::WARRANTY_BUCKETS[$this->warranty];
        }

        if ($this->assigned === 'none') {
            $chips['assigned'] = 'Unassigned only';
        }

        return $chips;
    }

    public function clearAnalyticsFilter(string $key): void
    {
        match ($key) {
            'age', 'ageMode' => [$this->ageMin, $this->ageMax, $this->ageMode] = ['', '', ''],
            'warranty' => $this->warranty = '',
            'assigned' => $this->assigned = '',
            default => null,
        };

        $this->resetPage();
    }
}
