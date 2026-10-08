<?php

namespace App\Services\HealthSafety;

use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\Region;

/**
 * FE-<REGION PREFIX>-<NNNN> for extinguishers and FAK-<REGION PREFIX>-<NNNN> for first aid kits, for items added without a
 * tag of their own. The same approach as IncidentReferenceGenerator: one more than the highest already issued for that
 * prefix (found by length then value, so 9999 sorts below 10000), the unique index as the final guard, and the caller
 * asking again when two items collide. Tags typed by an officer are kept as they are; this only fills the blank ones.
 */
class EquipmentAssetCodeGenerator
{
    public const EXTINGUISHER_PREFIX = 'FE';
    public const KIT_PREFIX = 'FAK';

    public function nextExtinguisher(Region $region): string
    {
        return $this->next(HsFireExtinguisher::class, self::EXTINGUISHER_PREFIX, $region);
    }

    public function nextKit(Region $region): string
    {
        return $this->next(HsFirstAidKit::class, self::KIT_PREFIX, $region);
    }

    /** @param  class-string<\Illuminate\Database\Eloquent\Model>  $model */
    protected function next(string $model, string $kind, Region $region): string
    {
        $prefix = $kind.'-'.$region->assignLetterPrefix().'-';

        $highest = $model::query()
            ->where('asset_code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(asset_code) desc')
            ->orderByDesc('asset_code')
            ->value('asset_code');

        $last = $highest ? (int) substr($highest, strlen($prefix)) : 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
