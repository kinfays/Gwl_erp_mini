<?php

namespace App\Services\Commercial;

use App\Models\CommercialLocationAlias;
use App\Models\District;
use App\Models\Region;

/**
 * Turns the region / district text a report prints into the real record: first an exact match on the normalised name,
 * then the alias table. Anything else is "unmatched" and the officer resolves it once; resolving writes an alias, so
 * every later upload matches by itself.
 */
class LocationMatcher
{
    /** @var array<string, Region>|null */
    protected ?array $regionsByKey = null;

    /** @var array<int, array<string, District>> */
    protected array $districtsByRegion = [];

    /** @var array<string, CommercialLocationAlias>|null */
    protected ?array $aliases = null;

    /** Upper-cased and whitespace-collapsed, with "/" and "-" spacing and a trailing REGION / DISTRICT word ignored. */
    public function normalize(?string $value): string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        $text = preg_replace('/\s*([\/-])\s*/u', '$1', $text) ?? $text;

        return mb_strtoupper(trim($text));
    }

    public function resolveRegion(?string $label): ?Region
    {
        $key = $this->matchKey($label);

        if ($key === '') {
            return null;
        }

        $this->regionsByKey ??= Region::query()->get()
            ->mapWithKeys(fn (Region $region) => [$this->matchKey($region->region_name) => $region])
            ->all();

        if (isset($this->regionsByKey[$key])) {
            return $this->regionsByKey[$key];
        }

        $alias = $this->alias(CommercialLocationAlias::KIND_REGION, $label);

        return $alias?->region_id ? Region::query()->find($alias->region_id) : null;
    }

    /**
     * Districts are looked up inside the batch's region first, so two regions can each have a district of the same name.
     */
    public function resolveDistrict(?string $label, ?int $regionId): ?District
    {
        $key = $this->matchKey($label);

        if ($key === '') {
            return null;
        }

        $districts = $this->districtsInRegion($regionId);

        if (isset($districts[$key])) {
            return $districts[$key];
        }

        $alias = $this->alias(CommercialLocationAlias::KIND_DISTRICT, $label);

        if (! $alias?->district_id) {
            return null;
        }

        $district = District::query()->find($alias->district_id);

        // An alias made for another region must not attach this region's report to that district.
        return $district && ($regionId === null || (int) $district->region_id === $regionId) ? $district : null;
    }

    public function saveRegionAlias(string $rawLabel, Region $region): CommercialLocationAlias
    {
        $alias = CommercialLocationAlias::query()->updateOrCreate(
            ['kind' => CommercialLocationAlias::KIND_REGION, 'alias_normalized' => $this->normalize($rawLabel)],
            ['region_id' => $region->id, 'district_id' => null]
        );

        $this->aliases = null;

        return $alias;
    }

    public function saveDistrictAlias(string $rawLabel, District $district): CommercialLocationAlias
    {
        $alias = CommercialLocationAlias::query()->updateOrCreate(
            ['kind' => CommercialLocationAlias::KIND_DISTRICT, 'alias_normalized' => $this->normalize($rawLabel)],
            ['district_id' => $district->id, 'region_id' => $district->region_id]
        );

        $this->aliases = null;

        return $alias;
    }

    /** The comparison key: normalised, without a trailing "REGION" / "DISTRICT" word. */
    protected function matchKey(?string $value): string
    {
        $normalized = $this->normalize($value);

        return trim(preg_replace('/\s+(REGION|DISTRICT)$/u', '', $normalized) ?? $normalized);
    }

    /** @return array<string, District> */
    protected function districtsInRegion(?int $regionId): array
    {
        $cacheKey = (int) $regionId;

        return $this->districtsByRegion[$cacheKey] ??= District::query()
            ->when($regionId, fn ($query) => $query->where('region_id', $regionId))
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (District $district) => [$this->matchKey($district->district_name) => $district])
            ->all();
    }

    protected function alias(string $kind, ?string $label): ?CommercialLocationAlias
    {
        $this->aliases ??= CommercialLocationAlias::query()->get()
            ->keyBy(fn (CommercialLocationAlias $alias) => $alias->kind.'|'.$alias->alias_normalized)
            ->all();

        return $this->aliases[$kind.'|'.$this->normalize($label)] ?? null;
    }
}
