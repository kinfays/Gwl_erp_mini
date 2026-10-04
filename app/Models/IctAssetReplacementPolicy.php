<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** How many years after purchase ICT replaces a device. asset_type NULL is the default; other rows override it. */
class IctAssetReplacementPolicy extends Model
{
    /** Last-resort figure if the table is empty or missing (seeded to 4 by the migration, which is the real policy). */
    public const FALLBACK_YEARS = 4;

    protected $table = 'ict_asset_replacement_policies';

    protected $fillable = ['asset_type', 'years'];

    protected $casts = ['years' => 'integer'];

    /** Exact asset_type override first, then the default row, then FALLBACK_YEARS. */
    public static function yearsFor(string $assetType): int
    {
        $policy = self::resolve();

        return $policy['overrides'][$assetType] ?? $policy['default'];
    }

    /**
     * One read for callers that need every type at once.
     *
     * @return array{default: int, overrides: array<string, int>}
     */
    public static function resolve(): array
    {
        $default = self::FALLBACK_YEARS;
        $overrides = [];

        if (Schema::hasTable((new self)->getTable())) {
            foreach (self::query()->get(['asset_type', 'years']) as $row) {
                if ($row->asset_type === null) {
                    $default = max(1, (int) $row->years);
                } else {
                    $overrides[$row->asset_type] = max(1, (int) $row->years);
                }
            }
        }

        return ['default' => $default, 'overrides' => $overrides];
    }
}
