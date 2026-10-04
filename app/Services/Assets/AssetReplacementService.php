<?php

namespace App\Services\Assets;

use App\Models\IctAssetReplacementPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Replacement forecast: an asset is due purchased_at + policy years (policy per asset_type, else the default).
 * Like AssetDashboardService::applyAgeRange, the same apply() backs both the dashboard counts and the list filter so
 * a bucket and its drill-down cannot disagree. The date test is pushed into SQL per policy group rather than
 * computed per row.
 */
class AssetReplacementService
{
    public const BUCKETS = [
        'overdue' => 'Overdue',
        'due_90' => 'Due within 90 days',
        'due_1y' => 'Due within 1 year',
        'not_due' => 'Not due yet',
        'unknown' => 'Unknown',
    ];

    public function __construct(protected AssetDashboardService $dashboard) {}

    /** @param string $bucket one of BUCKETS; anything else leaves the query untouched */
    public function apply(Builder $query, string $bucket): Builder
    {
        if (! array_key_exists($bucket, self::BUCKETS)) {
            return $query;
        }

        $model = $query->getModel();
        $purchased = $model->qualifyColumn('purchased_at');

        if ($bucket === 'unknown') {
            return $query->whereNull($purchased);
        }

        $typeColumn = $model->qualifyColumn('asset_type');
        $policy = IctAssetReplacementPolicy::resolve();
        $today = now()->startOfDay();

        // due = purchased + Y years, so "due on or before D" is "purchased on or before D - Y years".
        $dueBefore = fn (\Carbon\CarbonInterface $day, int $years) => $day->copy()->subYears($years)->toDateString();

        // Group asset types by their years figure; the default group is "every type without an override".
        $groups = [];
        foreach ($policy['overrides'] as $type => $years) {
            $groups[$years]['types'][] = $type;
        }

        $clauses = [];
        foreach ($groups as $years => $group) {
            $clauses[] = [$years, fn (Builder $q) => $q->whereIn($typeColumn, $group['types'])];
        }
        $overridden = array_keys($policy['overrides']);
        $clauses[] = [$policy['default'], fn (Builder $q) => $overridden === []
            ? $q
            : $q->where(fn (Builder $inner) => $inner->whereNotIn($typeColumn, $overridden)->orWhereNull($typeColumn))];

        return $query->whereNotNull($purchased)->where(function (Builder $outer) use ($clauses, $bucket, $purchased, $today, $dueBefore) {
            foreach ($clauses as [$years, $typeScope]) {
                $outer->orWhere(function (Builder $q) use ($years, $typeScope, $bucket, $purchased, $today, $dueBefore) {
                    $typeScope($q);

                    match ($bucket) {
                        'overdue' => $q->whereDate($purchased, '<', $dueBefore($today, $years)),
                        'due_90' => $q->whereDate($purchased, '>=', $dueBefore($today, $years))
                            ->whereDate($purchased, '<=', $dueBefore($today->copy()->addDays(90), $years)),
                        'due_1y' => $q->whereDate($purchased, '>', $dueBefore($today->copy()->addDays(90), $years))
                            ->whereDate($purchased, '<=', $dueBefore($today->copy()->addYear(), $years)),
                        'not_due' => $q->whereDate($purchased, '>', $dueBefore($today->copy()->addYear(), $years)),
                    };
                });
            }
        });
    }

    /** @return array<int, array{key: string, label: string, total: int, by_category: array<string, int>, params: array<string, mixed>}> */
    public function buckets(Builder $scopedAssets): array
    {
        $rows = [];

        foreach (self::BUCKETS as $key => $label) {
            $rows[] = $this->dashboard->bucketRow($key, $label, $this->apply(clone $scopedAssets, $key), ['replacement_bucket' => $key]);
        }

        return $rows;
    }
}
