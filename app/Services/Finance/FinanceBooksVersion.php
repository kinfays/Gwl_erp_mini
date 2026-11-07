<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Cache;

/**
 * A counter per location (Head Office, or a region without it) that PaymentVoucherWorkflowService bumps after every write.
 * The cached analytics payload carries it in its key, so a figure is cached for speed but never shown after a change: the
 * next read simply misses. Bumping is one cache write; stale entries expire on their own.
 */
class FinanceBooksVersion
{
    /** @param array{region_id: int|null, district_id: int|null} $location */
    public static function unitKey(array $location): string
    {
        return app(FinanceScope::class)->isHeadOffice($location) ? 'ho' : 'r'.(int) ($location['region_id'] ?? 0);
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
        return 'finance:books-version:'.self::unitKey($location);
    }
}
