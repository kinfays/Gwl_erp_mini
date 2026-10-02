<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The compulsory leave of one year. `days` is what comes off the gross annual entitlement of the staff it applies to;
 * start_date and resume_date are informational (and keep people from booking leave across the shutdown).
 */
class CompulsoryLeavePeriod extends Model
{
    protected $fillable = [
        'year',
        'days',
        'start_date',
        'resume_date',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'days' => 'integer',
        'start_date' => 'date',
        'resume_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(fn (self $period) => self::forgetYear((int) $period->year));
        static::deleted(fn (self $period) => self::forgetYear((int) $period->year));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function forYear(int $year): ?self
    {
        return static::query()->where('year', $year)->first();
    }

    /** True when the year was handled by the older deduction screen: those days are already in used_days. */
    public static function hasLegacyDeduction(int $year): bool
    {
        return Schema::hasTable('compulsory_leave_deductions')
            && CompulsoryLeaveDeduction::query()->where('year', $year)->exists();
    }

    /**
     * The days taken off gross entitlement for $year: the year's record, else the configured default (11). Zero for a
     * year that already had the older, category-based deduction, which charged its days to used_days: taking them off the
     * entitlement as well would deduct twice. Read for every employee on a list, so it is cached per year.
     */
    public static function effectiveDaysFor(int $year): int
    {
        return (int) Cache::remember(self::cacheKey($year), 300, function () use ($year) {
            if (self::hasLegacyDeduction($year)) {
                return 0;
            }

            return self::query()->where('year', $year)->value('days') ?? (int) config('gwl.leave_compulsory_default_days', 11);
        });
    }

    public static function forgetYear(int $year): void
    {
        Cache::forget(self::cacheKey($year));
    }

    protected static function cacheKey(int $year): string
    {
        return 'leave:compulsory_days:'.$year.':'.(int) config('gwl.leave_compulsory_default_days', 11);
    }
}
