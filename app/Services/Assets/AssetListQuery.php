<?php

namespace App\Services\Assets;

use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of the Assets, Phones, Network and Issue Reports lists, written once and used by the Livewire lists and
 * by the export controllers, so an exported file holds exactly the rows the list shows. Callers pass an already
 * region-scoped query; nothing here widens it.
 *
 * Filter keys: search, status, type, district (assets only), ups (assets only), age_min, age_max, age, warranty,
 * replacement_bucket, assigned. Blank or unknown values mean "no filter".
 */
class AssetListQuery
{
    public function __construct(
        protected AssetDashboardService $dashboard,
        protected AssetReplacementService $replacement,
    ) {}

    /** @param array<string, mixed> $f */
    public function assets(Builder $query, string $category, array $f): Builder
    {
        $search = (string) ($f['search'] ?? '');
        $district = (string) ($f['district'] ?? '');
        $ups = (string) ($f['ups'] ?? '');

        $query->where('device_category', $category)
            ->when($search !== '', function (Builder $q) use ($search, $category) {
                $term = '%'.$search.'%';
                $q->where(function (Builder $inner) use ($term, $category) {
                    $inner->where('asset_name', 'like', $term)->orWhere('serial_number', 'like', $term);

                    match ($category) {
                        IctAsset::DEVICE_CATEGORY_PHONE => $inner
                            ->orWhere('imei', 'like', $term)
                            ->orWhere('device_phone_number', 'like', $term)
                            ->orWhereHas('assignedTo', fn ($e) => $e->where('full_name', 'like', $term)),
                        IctAsset::DEVICE_CATEGORY_NETWORK => $inner
                            ->orWhere('device_ip', 'like', $term)
                            ->orWhere('ssid', 'like', $term)
                            ->orWhere('actual_location', 'like', $term),
                        default => $inner->orWhereHas('assignedTo', fn ($e) => $e->where('full_name', 'like', $term)),
                    };
                });
            })
            ->when(filled($f['status'] ?? null), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(self::types($f['type'] ?? null) !== [], fn (Builder $q) => $q->whereIn('asset_type', self::types($f['type'])));

        $this->analytics($query, $f);

        if ($category === IctAsset::DEVICE_CATEGORY_ASSET) {
            $query->when($district !== '', fn (Builder $q) => $q->where('district_id', (int) $district))
                ->when(array_key_exists($ups, AssetDashboardService::UPS_FILTERS), fn (Builder $q) => $this->dashboard->applyUps($q, $ups));
        }

        return $query;
    }

    /** Age, warranty, replacement and unassigned filters the dashboard and Summary pages drill into. */
    public function analytics(Builder $query, array $f): Builder
    {
        if (($f['age'] ?? '') === 'unknown') {
            $this->dashboard->applyAgeRange($query, null, null, true);
        } elseif (self::ageBound($f['age_min'] ?? '') !== null || self::ageBound($f['age_max'] ?? '') !== null) {
            $this->dashboard->applyAgeRange($query, self::ageBound($f['age_min'] ?? ''), self::ageBound($f['age_max'] ?? ''));
        }

        if (array_key_exists((string) ($f['warranty'] ?? ''), AssetDashboardService::WARRANTY_BUCKETS)) {
            $this->dashboard->applyWarranty($query, $f['warranty']);
        }

        if (array_key_exists((string) ($f['replacement_bucket'] ?? ''), AssetReplacementService::BUCKETS)) {
            $this->replacement->apply($query, $f['replacement_bucket']);
        }

        if (($f['assigned'] ?? '') === 'none') {
            $this->dashboard->applyUnassigned($query);
        }

        return $query;
    }

    /** @param array<string, mixed> $f */
    public function reports(Builder $query, array $f): Builder
    {
        return $query
            ->when(filled($f['search'] ?? null), function (Builder $q) use ($f) {
                $term = '%'.$f['search'].'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('issue_type', 'like', $term)
                    ->orWhere('issue_subtype', 'like', $term)
                    ->orWhere('reason', 'like', $term)
                    ->orWhereHas('asset', fn ($a) => $a->where('asset_name', 'like', $term)));
            })
            ->when(filled($f['status'] ?? null), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(filled($f['type'] ?? null), fn (Builder $q) => $q->where('issue_type', $f['type']));
    }

    /** The asset types in a ?type= value: one, or several joined by commas ("PC,Laptop"). */
    public static function types(mixed $value): array
    {
        return is_string($value)
            ? array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn (string $type) => $type !== '')))
            : [];
    }

    /** A hand-edited URL with junk in it is ignored rather than erroring. */
    public static function ageBound(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) && $value >= 0 ? $value : null);
    }
}
