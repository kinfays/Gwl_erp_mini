<?php

namespace App\Services\GWater;

use Illuminate\Support\Facades\Cache;

/**
 * A counter per location (Head Office, or a region without it) that GwaterWorkflowService bumps on every write. The cached
 * profit and loss payload carries it in its key, so a figure is cached for speed but never shown after an entry that changes
 * it: the next read simply misses. Bumping is one cache write; stale entries expire on their own.
 */
class GwaterBooksVersion
{
    /** @param array{region_id: int|null, district_id: int|null} $location */
    public static function unitKey(array $location): string
    {
        return app(GwaterVisibility::class)->isHeadOffice($location) ? 'ho' : 'r'.(int) ($location['region_id'] ?? 0);
    }

    /** @param array{region_id: int|null, district_id: int|null} $location */
    public static function current(array $location): int
    {
        return (int) Cache::get(self::cacheKey($location), 0);
    }

    /** @param array{region_id: int|null, district_id: int|null} $location */
    public static function touch(array $location): void
    {
        Cache::forever(self::cacheKey($location), self::current($location) + 1);
    }

    private static function cacheKey(array $location): string
    {
        return 'gwater:books-version:'.self::unitKey($location);
    }
}
