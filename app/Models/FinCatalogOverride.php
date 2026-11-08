<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A location's own display name and / or on-off flag for a SYSTEM DEFAULT category or type. Exists only while something differs
 * from the default. Written only by PvCatalogService.
 */
class FinCatalogOverride extends Model
{
    public const CATEGORY = 'category';

    public const TYPE = 'type';

    protected $fillable = ['unit', 'kind', 'item_id', 'display_name', 'is_active', 'updated_by'];

    protected $casts = ['item_id' => 'integer', 'is_active' => 'boolean'];
}
