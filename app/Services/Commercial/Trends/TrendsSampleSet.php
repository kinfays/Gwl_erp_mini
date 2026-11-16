<?php

namespace App\Services\Commercial\Trends;

use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use App\Services\Commercial\Cross\CrossSampleSet;
use App\Services\Commercial\ReadingRouteSampleFile;

/**
 * A SERIES of invented uploads for the trends (Phase 8f, design 8.45): one invented region, two invented districts, six routes, and for each period a
 * reading-by-route file and a Detailed Billing file (plus daily customer-list snapshots), so the period-on-period, rolling, rank, year-on-year,
 * projection and timeliness pages have something to show. Nothing here is real: the CCAs are "AMA TEST" and friends, the customers "Sample Customer n".
 *
 * The schedule (all in 2026 unless said) has, on purpose:
 *   - a MONTHLY upload (September) that must never be compared with a weekly one,
 *   - three WEEKLY uploads (1 to 7, 8 to 14 and 22 to 28 October) with a GAP of 15 to 21 October,
 *   - a LATE upload (22 to 28 October, imported on 3 November: 6 days after its period ended; the others are 0 to 2 days),
 *   - the SAME WEEK A YEAR EARLIER (1 to 7 October 2025), for year on year,
 *   - three DAILY customer-list snapshots (31 October 2025, 24 and 31 October 2026).
 *
 * Per period: the skipped visits of each of the six routes (visited is always 10 of a strength of 10) and how many customers of each billing route
 * did not pay and how many were estimated, so every figure can be worked out by hand from this table.
 */
final class TrendsSampleSet
{
    public const REGION = 'ACCRA WEST';

    /** The six routes: district index (0 or 1) and code, in the order of the figures below. */
    public const ROUTES = [[0, '4101'], [0, '4102'], [0, '4103'], [1, '4201'], [1, '4202'], [1, '4203']];

    /**
     * @var list<array{key: string, from: string, to: string, imported: string, skips: list<int>, unpaid: list<int>, estimated: list<int>}>
     */
    public const SCHEDULE = [
        ['key' => 'last-year', 'from' => '2025-10-01', 'to' => '2025-10-07', 'imported' => '2025-10-08', 'skips' => [2, 2, 3, 4, 4, 5], 'unpaid' => [3, 3, 3, 4, 4, 4], 'estimated' => [2, 2, 2, 3, 3, 3]],
        ['key' => 'month', 'from' => '2026-09-01', 'to' => '2026-09-30', 'imported' => '2026-10-02', 'skips' => [1, 1, 1, 3, 3, 3], 'unpaid' => [2, 2, 2, 3, 3, 3], 'estimated' => [1, 1, 1, 2, 2, 2]],
        ['key' => 'week-1', 'from' => '2026-10-01', 'to' => '2026-10-07', 'imported' => '2026-10-09', 'skips' => [2, 1, 3, 4, 3, 5], 'unpaid' => [3, 2, 3, 4, 3, 4], 'estimated' => [2, 1, 2, 3, 2, 3]],
        ['key' => 'week-2', 'from' => '2026-10-08', 'to' => '2026-10-14', 'imported' => '2026-10-14', 'skips' => [1, 1, 2, 3, 3, 4], 'unpaid' => [2, 2, 2, 3, 3, 3], 'estimated' => [1, 1, 1, 2, 2, 2]],
        ['key' => 'week-4-late', 'from' => '2026-10-22', 'to' => '2026-10-28', 'imported' => '2026-11-03', 'skips' => [3, 0, 2, 1, 1, 3], 'unpaid' => [1, 1, 2, 2, 2, 3], 'estimated' => [0, 1, 1, 1, 2, 2]],
    ];

    /** The daily customer-list snapshots: as-of date => how many customers of each route are left out (so the billing accounts differ). */
    public const SNAPSHOTS = ['2025-10-31' => 2, '2026-10-24' => 1, '2026-10-31' => 0];

    /** The printed bill period of a schedule row, e.g. 01-October-2026 - 07-October-2026. */
    public static function periodText(string $from, string $to): string
    {
        return date('d-F-Y', strtotime($from)).' - '.date('d-F-Y', strtotime($to));
    }

    /**
     * @param  array{from: string, to: string, skips: list<int>}  $p
     * @param  list<string>  $d  the two district names as printed
     * @return array<string, mixed> a spec for ReadingRouteSampleFile::write()
     */
    public static function readingSpec(array $p, array $d, string $region = self::REGION): array
    {
        $row = function (int $i) use ($p, $d): array {
            [$district, $code] = self::ROUTES[$i];
            $skipped = $p['skips'][$i];

            return ['label' => $d[$district].' '.$code, 'strength' => 10, 'read' => 10 - $skipped, 'skipped' => $skipped, 'visited' => 10];
        };

        return ['region' => $region, 'period' => self::periodText($p['from'], $p['to']), 'ccas' => [
            ['id' => '80001', 'name' => 'AMA TEST', 'routes' => [$row(0), $row(1)]],
            ['id' => '80002', 'name' => 'KWAME TEST', 'routes' => [$row(2)]],
            ['id' => '80003', 'name' => 'EFUA TEST', 'routes' => [$row(3), $row(4)]],
            ['id' => '80004', 'name' => 'YAW TEST', 'routes' => [$row(5)]],
        ]];
    }

    /**
     * @param  array{from: string, to: string, unpaid: list<int>, estimated: list<int>}  $p
     * @param  list<string>  $d
     * @return array<string, mixed> a spec for BillingDetailsSampleFile::write()
     */
    public static function billingSpec(array $p, array $d, string $region = self::REGION): array
    {
        $month = date('F-Y', strtotime($p['to']));
        $routes = [];

        foreach (self::ROUTES as $i => [$district, $code]) {
            $customers = [];

            for ($k = 0; $k < 10; $k++) {
                $over = ['bill_month' => $month, 'last_read' => date('Y-m-d', strtotime($p['to'].' -3 days'))];

                if ($k < $p['unpaid'][$i]) {
                    $over['paid'] = 0;
                }

                if ($k >= 10 - $p['estimated'][$i]) {
                    $over['bill_status'] = 'ESTIMATED';
                }

                $customers[] = BillingDetailsSampleFile::customer(($i + 1) * 100 + $k, $over);
            }

            $routes[] = ['label' => $d[$district].' '.$code, 'customers' => $customers];
        }

        return ['region' => $region, 'period' => self::periodText($p['from'], $p['to']), 'routes' => $routes];
    }

    /**
     * The customer list of a snapshot: every route of the cross set's list with the last $leftOut customers of each left out.
     *
     * @param  list<string>  $d
     * @return list<array{code: string, district: string, customers: list<array<string, mixed>>}>
     */
    public static function listRoutes(array $d, int $leftOut): array
    {
        return array_map(function (array $route) use ($leftOut): array {
            $route['customers'] = array_slice($route['customers'], 0, max(1, count($route['customers']) - $leftOut));

            return $route;
        }, CrossSampleSet::listRoutes($d));
    }

    /**
     * Writes every file of the series into a folder: reading-<key>.xlsx, billing-<key>.xlsx and customers-<date>.xlsx.
     *
     * @param  list<string>  $districts
     * @return array{reading: array<string, string>, billing: array<string, string>, customers: array<string, string>}
     */
    public static function write(string $directory, array $districts, string $region = self::REGION): array
    {
        is_dir($directory) || mkdir($directory, 0775, true);
        $out = ['reading' => [], 'billing' => [], 'customers' => []];

        foreach (self::SCHEDULE as $p) {
            $out['reading'][$p['key']] = ReadingRouteSampleFile::write(self::readingSpec($p, $districts, $region), $directory.'/rptReadingSumm-trends-'.$p['key'].'.xlsx');
            $out['billing'][$p['key']] = BillingDetailsSampleFile::write(self::billingSpec($p, $districts, $region), $directory.'/rptBillingDetails-trends-'.$p['key'].'.xlsx');
        }

        foreach (self::SNAPSHOTS as $asOf => $leftOut) {
            $out['customers'][$asOf] = CrossSampleSet::writeCustomerList($directory.'/rptCustomerDetails-trends-'.$asOf.'.xlsx', self::listRoutes($districts, $leftOut), $region);
        }

        return $out;
    }
}
