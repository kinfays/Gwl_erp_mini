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
 * Filter keys: search, status, type, districts (a list of district ids, every category), models (a list of model ids),
 * ups (assets only), age_min, age_max, age, warranty, replacement_bucket, assigned. Blank or unknown values mean "no
 * filter". The old single `district` key is still read (as one entry of `districts`). The callers cut `districts` and
 * `models` down to what the user may see before they get here; this class only filters.
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
        $search = trim((string) ($f['search'] ?? ''));
        $ups = (string) ($f['ups'] ?? '');
        $districts = self::ids($f['districts'] ?? null);
        $models = self::ids($f['models'] ?? null);

        // The pre-multi-select single-district value.
        if ($districts === [] && ctype_digit((string) ($f['district'] ?? ''))) {
            $districts = [(int) $f['district']];
        }

        $query->where('device_category', $category)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $this->search($inner, $category, $search)))
            ->when(filled($f['status'] ?? null), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(self::types($f['type'] ?? null) !== [], fn (Builder $q) => $q->whereIn('asset_type', self::types($f['type'])))
            ->when($districts !== [], fn (Builder $q) => $q->whereIn('district_id', $districts))
            ->when($models !== [], fn (Builder $q) => $q->whereIn('ict_asset_model_id', $models));

        $this->analytics($query, $f);

        if ($category === IctAsset::DEVICE_CATEGORY_ASSET) {
            $query->when(array_key_exists($ups, AssetDashboardService::UPS_FILTERS), fn (Builder $q) => $this->dashboard->applyUps($q, $ups));
        }

        return $query;
    }

    /**
     * The search box: name, serial, tag and the category's own columns as before, plus the MODEL. A model matches when
     * every word of the search is found in the model name or its manufacturer's name, so "Samsung A15" finds a Galaxy
     * A15 made by Samsung, and "a15" or "samsung" alone also work. `%` and `_` typed by the user are plain text.
     */
    protected function search(Builder $query, string $category, string $search): void
    {
        $like = self::like($search);

        $query->whereRaw("asset_name LIKE ? ESCAPE '!'", [$like])
            ->orWhereRaw("serial_number LIKE ? ESCAPE '!'", [$like])
            ->orWhereRaw("tag_no LIKE ? ESCAPE '!'", [$like]);

        match ($category) {
            IctAsset::DEVICE_CATEGORY_PHONE => $query
                ->orWhereRaw("imei LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("device_phone_number LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('assignedTo', fn ($e) => $e->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])),
            IctAsset::DEVICE_CATEGORY_NETWORK => $query
                ->orWhereRaw("device_ip LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("ssid LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("actual_location LIKE ? ESCAPE '!'", [$like]),
            default => $query->orWhereHas('assignedTo', fn ($e) => $e->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])),
        };

        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', $search) ?: [], fn ($word) => $word !== '')), 0, 6);

        if ($words !== []) {
            $query->orWhereHas('assetModel', function ($model) use ($words) {
                foreach ($words as $word) {
                    $wordLike = self::like($word);

                    $model->where(fn ($either) => $either
                        ->whereRaw("ict_asset_models.name LIKE ? ESCAPE '!'", [$wordLike])
                        ->orWhereHas('manufacturer', fn ($maker) => $maker->whereRaw("ict_asset_manufacturers.name LIKE ? ESCAPE '!'", [$wordLike])));
                }
            });
        }
    }

    /** "%term%" with the user's own % and _ made literal, for `LIKE ? ESCAPE '!'`. */
    public static function like(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($term, 0, 100)).'%';
    }

    /** @return array<int, int> the whole positive numbers in a filter value (an array, or one number); junk is dropped */
    public static function ids(mixed $value): array
    {
        return collect(is_array($value) ? $value : (filled($value) ? [$value] : []))
            ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
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

    /**
     * The Racks list: search (name, code, room / building, notes; `%` and `_` typed by the user are plain text), status and
     * districts. The caller passes an already region-scoped query, and cuts `districts` down to what the user may see.
     *
     * @param  array<string, mixed>  $f
     */
    public function racks(Builder $query, array $f): Builder
    {
        $search = trim((string) ($f['search'] ?? ''));
        $districts = self::ids($f['districts'] ?? null);

        return $query
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = self::like($search);

                $q->where(fn (Builder $inner) => $inner
                    ->whereRaw("ict_racks.name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("ict_racks.code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("ict_racks.location LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("ict_racks.notes LIKE ? ESCAPE '!'", [$like]));
            })
            ->when(filled($f['status'] ?? null), fn (Builder $q) => $q->where('ict_racks.status', $f['status']))
            ->when($districts !== [], fn (Builder $q) => $q->whereIn('ict_racks.district_id', $districts));
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
