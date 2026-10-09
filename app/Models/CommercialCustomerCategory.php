<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCustomerCategory extends Model
{
    public const GROUPS = [
        'domestic' => 'Domestic',
        'commercial' => 'Commercial',
        'industrial' => 'Industrial',
        'institutions' => 'Institutions / Government',
        'bulk_tanker' => 'Bulk & Tanker',
        'standpipe' => 'Standpipe',
        'non_water' => 'Non-water / fee revenue',
        'unknown' => 'Unknown / not grouped',
    ];

    protected $fillable = ['code', 'name', 'category_group', 'group_is_proposed', 'is_unknown', 'is_pending'];

    protected $casts = ['group_is_proposed' => 'boolean', 'is_unknown' => 'boolean', 'is_pending' => 'boolean'];

    public static function groupLabel(?string $group): string
    {
        return self::GROUPS[$group ?? 'unknown'] ?? ucfirst(str_replace('_', ' ', (string) $group));
    }

    public function label(): string
    {
        return $this->is_unknown ? 'UNKNOWN' : trim(($this->code ? $this->code.' ' : '').$this->name);
    }
}
