<?php

namespace App\Services\Commercial\Cross;

use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use App\Services\Commercial\ReadingRouteSampleFile;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * ONE consistent set of invented files for the cross-file analyses (Phase 8e, design 8.42): a reading report by route and CCA, a Detailed Billing
 * Report and a region-wide customer list for one region, whose accounts and routes line up, with the awkward cases the analyses look for. Used by
 * the sample command (commercial:cross:sample), by the small fixture set in tests/Fixtures and by the tests, so there is ONE writer of the set.
 * Nothing here is real: customers are "Sample Customer n", accounts 100000000001 and up, routes 41xx and 42xx.
 *
 * THE SET (two districts, A and B, the names as printed; the numbers are what the tests work out by hand):
 *
 *   reading, 1 to 31 October 2026     CCA 80001 owns A 4101 and A 4102; 80002 owns A 4103 (and still lists A 4101: strength 9, no visits, the STALE second
 *       CCA); 80003 owns B 4201 and B 4202; 80004 owns B 4203 and A 4109 and has ZERO visits; 80005 has no route. Every route of the first five has
 *       strength 10 and 10 visits, with skipped 0 / 1 / 2 / 3 / 4 on A 4101, A 4102, A 4103, B 4201, B 4202 (skip rate 0, 10, 20, 30, 40 %).
 *   billing, September 2026           A 4101 (accounts 1-10 billed, 11 ACTB unbilled), A 4102 (21-30, 31 HOLD unbilled), A 4103 (41-50), B 4201 (61-70),
 *       B 4202 (81-90), B 4203 (101-105), B 4204 (111-113, no reading row), B 0000 (121-122). Every billed customer owes 57.20 for the month.
 *       Estimated on a WORKING meter: 0 / 1 / 3 / 2 / 4 on those five routes (accounts 21; 41-43; 61-62; 81-84), one ESTIMATE on a faulty meter (63).
 *       Not paid: 1, 2, 22, 85, 86. Account 49 is DISC but billed. Accounts 66-68 read long ago (66, 67 on 1 Jun 2026) or never (68).
 *   customer list, as at 15 Nov 2026  A 4101 (1-12: 3 now DISC, 5 has no meter, 12 is new), A 4102 (21-31), A 4103 (41-50: 49 now ACTB), B 4201 (61-70: 65 now
 *       category 612, 66 now DISC), B 4202 printed under district A (81-90: the DISTRICT DISAGREEMENT), B 4203 (101-105), B 4204 (111-113), B 0000 (121; 122 is
 *       not in the list) and A 4999 (201-205: a list-only route).
 */
final class CrossSampleSet
{
    public const REGION = 'ACCRA WEST';

    public const READING_PERIOD = '01-October-2026 - 31-October-2026';

    public const BILL_PERIOD = 'September-2026 - September-2026';

    public const LIST_AS_OF = '2026-11-15';

    /** The two district names printed when none are given. */
    public const INVENTED_DISTRICTS = ['SAMPLE ONE', 'SAMPLE TWO'];

    /**
     * @param  list<string>  $d  the two district names as printed
     * @return array<string, mixed> a spec for ReadingRouteSampleFile::write()
     */
    public static function readingSpec(array $d = self::INVENTED_DISTRICTS, string $region = self::REGION, string $period = self::READING_PERIOD): array
    {
        $d = array_values($d) + self::INVENTED_DISTRICTS;
        $row = fn (string $district, string $code, int $strength, int $read, int $skipped, int $visited) => ['label' => $district.' '.$code, 'strength' => $strength, 'read' => $read, 'skipped' => $skipped, 'visited' => $visited];

        return ['region' => $region, 'period' => $period, 'ccas' => [
            ['id' => '80001', 'name' => 'AMA TEST', 'routes' => [$row($d[0], '4101', 10, 10, 0, 10), $row($d[0], '4102', 10, 9, 1, 10)]],
            ['id' => '80002', 'name' => 'KWAME TEST', 'routes' => [$row($d[0], '4103', 10, 8, 2, 10), $row($d[0], '4101', 9, 0, 0, 0)]],
            ['id' => '80003', 'name' => 'EFUA TEST', 'routes' => [$row($d[1], '4201', 10, 7, 3, 10), $row($d[1], '4202', 10, 6, 4, 10)]],
            ['id' => '80004', 'name' => 'YAW TEST', 'routes' => [$row($d[1], '4203', 10, 0, 0, 0), $row($d[0], '4109', 8, 0, 0, 0)]],
            ['id' => '80005', 'name' => 'KOFI TEST', 'routes' => []],
        ]];
    }

    /**
     * @param  list<string>  $d
     * @return array<string, mixed> a spec for BillingDetailsSampleFile::write()
     */
    public static function billingSpec(array $d = self::INVENTED_DISTRICTS, string $region = self::REGION, string $period = self::BILL_PERIOD): array
    {
        $d = array_values($d) + self::INVENTED_DISTRICTS;
        $c = fn (int $n, array $over = []) => BillingDetailsSampleFile::customer($n, $over);
        $zero = ['consume' => 0, 'water' => 0, 'rural' => 0, 'fire' => 0, 'sewage' => 0, 'service' => 0, 'month_total' => 0, 'paid' => 0, 'bill_status' => 'UNBILLED'];
        $range = fn (int $from, int $to, array $special = []) => array_map(fn (int $n) => $c($n, $special[$n] ?? []), range($from, $to));
        $est = ['bill_status' => 'ESTIMATED'];
        $unpaid = ['paid' => 0];

        return ['region' => $region, 'period' => $period, 'routes' => [
            ['label' => $d[0].' 4101', 'customers' => [...$range(1, 10, [1 => $unpaid, 2 => $unpaid]), $c(11, $zero + ['previous_balance' => 100])]],
            ['label' => $d[0].' 4102', 'customers' => [...$range(21, 30, [21 => $est, 22 => $unpaid]), $c(31, $zero + ['status' => 'HOLD'])]],
            ['label' => $d[0].' 4103', 'customers' => $range(41, 50, [41 => $est, 42 => $est, 43 => $est, 49 => ['status' => 'DISC']])],
            ['label' => $d[1].' 4201', 'customers' => $range(61, 70, [61 => $est, 62 => $est, 63 => ['bill_status' => 'ESTIMATE', 'meter_status' => 'F'], 66 => ['last_read' => '2026-06-01'], 67 => ['last_read' => '2026-06-01'], 68 => ['last_read' => null]])],
            ['label' => $d[1].' 4202', 'customers' => $range(81, 90, [81 => $est, 82 => $est, 83 => $est, 84 => $est, 85 => $unpaid, 86 => $unpaid])],
            ['label' => $d[1].' 4203', 'customers' => $range(101, 105)],
            ['label' => $d[1].' 4204', 'customers' => $range(111, 113)],
            ['label' => $d[1].' 0000', 'customers' => $range(121, 122)],
        ]];
    }

    /**
     * The customer list as routes: [['code', 'district' (as printed), 'customers' => [...]]].
     *
     * @param  list<string>  $d
     * @return list<array{code: string, district: string, customers: list<array<string, mixed>>}>
     */
    public static function listRoutes(array $d = self::INVENTED_DISTRICTS): array
    {
        $d = array_values($d) + self::INVENTED_DISTRICTS;
        $range = fn (int $from, int $to, array $special = []) => array_map(fn (int $n) => self::listCustomer($n, $special[$n] ?? []), range($from, $to));

        return [
            ['code' => '4101', 'district' => $d[0], 'customers' => $range(1, 12, [3 => ['status' => 'DISC'], 4 => ['meter_status' => 'F'], 5 => ['meter_status' => 'N'], 2 => ['mobile' => '233241234567 / 233207654321'], 1 => ['mobile' => null]])],
            ['code' => '4102', 'district' => $d[0], 'customers' => $range(21, 31, [31 => ['status' => 'HOLD']])],
            ['code' => '4103', 'district' => $d[0], 'customers' => $range(41, 50)],
            ['code' => '4201', 'district' => $d[1], 'customers' => $range(61, 70, [65 => ['category' => '612'], 66 => ['status' => 'DISC']])],
            ['code' => '4202', 'district' => $d[0], 'customers' => $range(81, 90)],     // printed under the OTHER district: the disagreement
            ['code' => '4203', 'district' => $d[1], 'customers' => $range(101, 105)],
            ['code' => '4204', 'district' => $d[1], 'customers' => $range(111, 113)],
            ['code' => '0000', 'district' => $d[1], 'customers' => $range(121, 121)],
            ['code' => '4999', 'district' => $d[0], 'customers' => $range(201, 205, [205 => ['status' => 'DISC']])],
        ];
    }

    /** @param  array<string, mixed>  $over @return array<string, mixed> */
    public static function listCustomer(int $n, array $over = []): array
    {
        return $over + [
            'account' => str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT), 'meter_no' => 'SMPL-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'name' => 'Sample Customer '.$n,
            'category' => '611', 'status' => 'ACTB', 'meter_status' => 'W', 'address' => 'Sample House '.$n.', Test Estate', 'mobile' => '233240'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'email' => 'sample'.$n.'@example.test', 'balance' => 0.0, 'last_reading' => 100 + $n, 'last_read_date' => '2026-11-01', 'last_bill_date' => '2026-10-28', 'last_bill_amount' => 57.2,
            'last_paid_date' => '2026-11-05', 'last_paid_amount' => 57.2, 'connect_date' => '2018-03-12', 'estimated_consume' => 0, 'average_consume' => 10.0, 'meter_factor' => 1,
        ];
    }

    /**
     * Writes the region-wide customer list in the layout of the real export (the filter block has only REGION; every route has its own heading
     * "code, DISTRICT, REGION", its customers and a "<code> TOTALS :" row). Streams, like the real file.
     *
     * @param  list<array{code: string, district: string, customers: list<array<string, mixed>>}>  $routes
     */
    public static function writeCustomerList(string $path, array $routes, string $region = self::REGION): string
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Document map');
        $writer->addRow(Row::fromValues(['Customer List Report (SAMPLE - invented data)']));
        $writer->addNewSheetAndMakeItCurrent()->setName('rptCustomerDetails');
        $writer->addRow(Row::fromValues(['Customer List Report']));
        $writer->addRow(Row::fromValues(['FILTERS']));
        $writer->addRow(Row::fromValues(['REGION: '.$region."\n"]));

        $headers = [null, 'Account #', 'Meter  #', 'Account Name', null, 'Category', 'Status', 'Meter Status', 'Residential Address', null, null, null, null, 'Mobile', 'Email', null, 'Balance', 'Last Read Date', null, 'Last Reading', 'Last Bill Date', 'Last Bill Amount', 'Last Paid Date', 'Last Paid Amount', 'Connect Date', 'Estimated Consume', 'Average Consume', 'Meter Factor'];
        $date = fn (?string $value) => $value === null ? null : new DateTimeImmutable($value);

        foreach ($routes as $route) {
            $writer->addRow(Row::fromValues([null, $route['code'].', '.$route['district'].', '.$region]));
            $writer->addRow(Row::fromValues($headers));
            $balance = 0.0;

            foreach ($route['customers'] as $x) {
                $balance += (float) $x['balance'];
                $writer->addRow(Row::fromValues([
                    null, $x['account'], $x['meter_no'], $x['name'], null, $x['category'], $x['status'], $x['meter_status'], $x['address'], null, null, null, null,
                    $x['mobile'], $x['email'], null, $x['balance'], $date($x['last_read_date']), null, $x['last_reading'], $date($x['last_bill_date']), $x['last_bill_amount'],
                    $date($x['last_paid_date']), $x['last_paid_amount'], $date($x['connect_date']), $x['estimated_consume'], $x['average_consume'], $x['meter_factor'],
                ]));
            }

            $writer->addRow(Row::fromValues([null, $route['code'].' TOTALS :', null, null, null, null, null, null, null, null, null, null, null, null, 'Customer Count: '.number_format(count($route['customers'])), null, round($balance, 2)]));
            $writer->addRow(Row::fromValues([]));
        }

        $writer->close();

        return $path;
    }

    /**
     * Writes the three files of the set into a folder and returns their paths.
     *
     * @param  list<string>  $districts  the two district names as printed
     * @return array{reading: string, billing: string, customers: string}
     */
    public static function write(string $directory, array $districts = self::INVENTED_DISTRICTS, string $region = self::REGION): array
    {
        is_dir($directory) || mkdir($directory, 0775, true);

        return [
            'reading' => ReadingRouteSampleFile::write(self::readingSpec($districts, $region), $directory.'/rptReadingSumm-cross-sample.xlsx'),
            'billing' => BillingDetailsSampleFile::write(self::billingSpec($districts, $region), $directory.'/rptBillingDetails-cross-sample.xlsx'),
            'customers' => self::writeCustomerList($directory.'/rptCustomerDetails-cross-sample.xlsx', self::listRoutes($districts), $region),
        ];
    }
}
