<?php

namespace Tests\Support\Commercial;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds SMALL synthetic .xlsx files that mimic the layout of the two billing-system reports (rptReadingSummDate and
 * rptBillingSumm_ExP). Nothing here is, or is derived from, a real export: names, staff IDs and figures are invented.
 */
class ReportWorkbooks
{
    public const BILLING_COLUMNS = [
        'code', 'vol_actual', 'vol_average', 'vol_total',
        'opening', 'billing', 'receivable', 'adjustment', 'pay_month', 'pay_prev', 'pay_offset', 'payments', 'closing',
        'ratio', 'customers',
        'avg_metered', 'avg_unmetered', 'actual', 'billed_total',
        'susp_metered', 'susp_unmetered', 'disc_metered', 'disc_unmetered', 'other', 'unbilled_total',
    ];

    /**
     * Reading report. $spec keys (all optional):
     *   region, months (list of first-of-month dates), strength (month => int),
     *   readers (list of [id, name, counts: month => [read, skipped]]),
     *   visited (id|month => int overrides), grand_sheet (false to leave the grand-total sheet out),
     *   grand_layout: 'overall' (default, as the real export: one row of overall Read / Skipped / Visited) or 'monthly',
     *   grand_overall ([read, skipped, visited] override) / grand (month => [read, skipped, visited] overrides),
     *   merged_headers (default true, as the real export: "Verified" over "Strength" and "Read" over "#" / "%" on two
     *   rows; false gives the flat "Read #" headers), filters ('lines' = one multi-line cell as in the real export, 'cells').
     */
    public static function reading(array $spec = []): string
    {
        $region = $spec['region'] ?? 'ACCRA WEST';
        $months = $spec['months'] ?? ['2026-06-01', '2026-07-01'];
        $strength = $spec['strength'] ?? array_combine($months, array_map(fn ($i) => 59000 + $i * 100, array_keys($months)));
        $readers = $spec['readers'] ?? self::defaultReaders($months);
        $merged = (bool) ($spec['merged_headers'] ?? true);
        $filters = $spec['filters'] ?? 'lines';

        $book = new Spreadsheet;
        $map = $book->getActiveSheet();
        $map->setTitle('Document map');
        $map->setCellValue('A1', 'Customer Meter Reading Report - CCA Summary - By Month');
        // The real "Document map" declares a used range out to column XFC; reading it cell by cell exhausts memory.
        $map->setCellValue('XFC72', 'x');
        $row = 3;

        foreach ($readers as $reader) {
            $map->setCellValue("A{$row}", $reader['id'].' - '.$reader['name']);
            $row++;
        }

        $totals = [];

        foreach (array_values($readers) as $position => $reader) {
            $sheet = $book->createSheet();
            $sheet->setTitle('Sheet'.($position + 2));
            self::readingHeader($sheet, $region, $reader['id'].' - '.$reader['name'], $merged, $filters);

            $line = $merged ? 13 : 12;

            foreach ($months as $month) {
                [$read, $skipped] = $reader['counts'][$month] ?? [0, 0];
                $visited = $spec['visited'][$reader['id'].'|'.$month] ?? $read + $skipped;

                $sheet->fromArray([
                    self::monthLabel($month), $strength[$month], $read, '0.89%', $skipped, '0.10%', $visited, '0.99%', 59000 - $visited, '99.01%',
                ], null, "A{$line}");

                $totals[$month]['read'] = ($totals[$month]['read'] ?? 0) + $read;
                $totals[$month]['skipped'] = ($totals[$month]['skipped'] ?? 0) + $skipped;
                $totals[$month]['visited'] = ($totals[$month]['visited'] ?? 0) + $read + $skipped;
                $line++;
            }

            $sheet->fromArray(['Total', null, array_sum(array_map(fn ($m) => $reader['counts'][$m][0] ?? 0, $months))], null, "A{$line}", true);
        }

        if (($spec['grand_sheet'] ?? true) !== false) {
            $grand = $book->createSheet();
            $grand->setTitle('Sheet'.(count($readers) + 2));
            $line = $merged ? 13 : 12;

            if (($spec['grand_layout'] ?? 'overall') === 'overall') {
                self::readingTitleAndFilters($grand, $region, $filters);
                $read = $spec['grand_overall'][0] ?? array_sum(array_column($totals, 'read'));
                $skipped = $spec['grand_overall'][1] ?? array_sum(array_column($totals, 'skipped'));
                $visited = $spec['grand_overall'][2] ?? array_sum(array_column($totals, 'visited'));

                $grand->setCellValue('C9', 'Total');
                $grand->setCellValue('J9', 'REPORT METER READINGS TOTALS');
                $grand->setCellValue('J10', 'Read');
                $grand->setCellValue('L10', 'Skipped');
                $grand->setCellValue('O10', 'Visited');
                $grand->setCellValue('Q10', 'Unvisited');
                $grand->setCellValue('C11', 'Total');
                $grand->setCellValue('J11', $read);
                $grand->setCellValue('L11', $skipped);
                $grand->setCellValue('O11', $visited);
                $grand->setCellValue('Q11', 16231976);

                return self::save($book);
            }

            self::readingHeader($grand, $region, 'GRAND TOTAL', $merged, $filters);

            foreach ($months as $month) {
                $read = $spec['grand'][$month][0] ?? $totals[$month]['read'] ?? 0;
                $skipped = $spec['grand'][$month][1] ?? $totals[$month]['skipped'] ?? 0;
                $visited = $spec['grand'][$month][2] ?? $totals[$month]['visited'] ?? 0;

                $grand->fromArray([self::monthLabel($month), $strength[$month] * count($readers), $read, '0.5%', $skipped, '0.1%', $visited, '0.6%', 0, '99%'], null, "A{$line}", true);
                $line++;
            }
        }

        return self::save($book);
    }

    /** @return list<array{id: string, name: string, counts: array<string, array{0: int, 1: int}>}> */
    public static function defaultReaders(array $months): array
    {
        $readers = [
            ['id' => '15071', 'name' => 'KOFI MENSAH', 'counts' => []],
            ['id' => '15072', 'name' => 'AMA SERWAA', 'counts' => []],
            ['id' => '00000', 'name' => 'System Administrator', 'counts' => []],
        ];

        foreach ($months as $index => $month) {
            $readers[0]['counts'][$month] = [400 + $index * 10, 100];
            $readers[1]['counts'][$month] = [300, 50 + $index];
            $readers[2]['counts'][$month] = [5, 1];
        }

        return $readers;
    }

    /**
     * Billing report. $spec keys (all optional):
     *   region, period, status (null = no BILLING STATUS line),
     *   districts (name => list of route specs, see route()), district_header ('name' puts the name in the header row,
     *   'District' prints the word District and leaves the name to the totals row),
     *   bands (list of [band, customers, volume, amount]) or false for none,
     *   blank_header (a header text to blank out, e.g. 'Prev Month Payment'),
     *   district_totals (name => field => delta added to that totals cell), report_totals (field => delta).
     */
    public static function billing(array $spec = []): string
    {
        $districts = $spec['districts'] ?? self::defaultDistricts();
        $book = new Spreadsheet;
        $map = $book->getActiveSheet();
        $map->setTitle('Document map');
        $map->setCellValue('A1', 'rptBillingSumm_ExP');
        $map->setCellValue('XFC6', 'x');
        $sheet = $book->createSheet();
        $sheet->setTitle('rptBillingSumm_ExP');

        $sheet->setCellValue('A1', 'Billing Summary Report By Routes - Routes By District');

        $period = $spec['period'] ?? 'June-2026 - August-2026';
        $region = $spec['region'] ?? 'ACCRA WEST';
        $status = array_key_exists('status', $spec) ? $spec['status'] : 'New Service Customers Only';

        if (($spec['filters'] ?? 'lines') === 'lines') {
            // As in the real export: the whole filter block is ONE cell holding several lines.
            $sheet->setCellValue('A3', "BILL PERIOD: {$period}\nREGION: {$region}".($status !== null ? "\nBILLING STATUS: {$status}" : '')."\n");
        } else {
            $sheet->setCellValue('A3', 'BILL PERIOD:');
            $sheet->setCellValue('B3', $period);
            $sheet->setCellValue('A4', 'REGION:');
            $sheet->setCellValue('B4', $region);

            if ($status !== null) {
                $sheet->setCellValue('A5', 'BILLING STATUS:');
                $sheet->setCellValue('B5', $status);
            }
        }

        $line = 7;
        $grand = array_fill_keys(self::BILLING_COLUMNS, 0);

        foreach ($districts as $name => $routes) {
            $label = ($spec['district_header'] ?? 'name') === 'name' ? $name : 'District';

            $sheet->fromArray([$label, "VOLUMES ('000 Litres)", null, null, 'AMOUNTS (GH¢)', null, null, null, null, null, null, null, null, 'Collection', 'Number Of', 'NUMBER BILLED', null, null, null, 'NUMBER UNBILLED'], null, "A{$line}", true);
            $line++;

            $header = ['Route', 'Actual', 'Average', 'Total', 'Opening Balance', 'Billing For Period', 'Total Receivable', 'Revenue Adjustment', 'Payment For Month', 'Prev Month Payment', 'Offset Payments', 'Total Payments ', 'Closing Balance', 'Collection Ratio', 'Customers', 'Average Metered', 'Average Unmetered', 'Actual Reading', 'Total Billed', 'Suspense Metered', 'Suspense Unmetered', 'Disconn Metered', 'Disconn Unmetered', 'Other Status', 'Total Unbilled'];

            if (isset($spec['blank_header'])) {
                $header = array_map(fn ($text) => $text === $spec['blank_header'] ? null : $text, $header);
            }

            $sheet->fromArray($header, null, "A{$line}", true);
            $line++;

            $sum = array_fill_keys(self::BILLING_COLUMNS, 0);

            foreach ($routes as $route) {
                $values = self::route($route);

                foreach (self::BILLING_COLUMNS as $column) {
                    if ($column !== 'code' && is_numeric($values[$column])) {
                        $sum[$column] += $values[$column];
                    }
                }

                $sheet->fromArray(array_values($values), null, "A{$line}", true);
                $line++;
            }

            foreach (self::BILLING_COLUMNS as $column) {
                $grand[$column] += $sum[$column];
            }

            $totalsRow = self::totalsRow($sum, $spec['district_totals'][$name] ?? []);
            $sheet->fromArray([strtoupper($name).' TOTALS:', ...array_slice($totalsRow, 1)], null, "A{$line}", true);
            $line += 2;
        }

        $sheet->fromArray(['REPORT TOTALS :', ...array_slice(self::totalsRow($grand, $spec['report_totals'] ?? []), 1)], null, "A{$line}", true);
        $line += 2;

        if (($spec['bands'] ?? null) !== false) {
            $sheet->setCellValue("A{$line}", 'DOMESTIC (611) CATEGORY BREAKDOWN');
            $line++;
            $sheet->fromArray(['Band', 'Customers', "Volume\n('000 L)", "Amount\n(GH¢)"], null, "A{$line}", true);
            $line++;

            foreach ($spec['bands'] ?? [['<=5', 80, 410.5, 3450.25], ['>5', 20, 300.75, 3460.0]] as $band) {
                $sheet->fromArray($band, null, "A{$line}", true);
                $line++;
            }
        }

        return self::save($book);
    }

    /**
     * A route's row, with every derived column worked out so the identities hold. Override 'closing' (or any other derived
     * key) to break one on purpose.
     *
     * @return array<string, int|float|string>
     */
    public static function route(array $r): array
    {
        $base = [
            'vol_actual' => 8.0, 'vol_average' => 4.0, 'opening' => 0.0, 'billing' => 100.0, 'adjustment' => 0.0,
            'pay_month' => 40.0, 'pay_prev' => 20.0, 'pay_offset' => 0.0, 'customers' => 10,
            'avg_metered' => 2, 'avg_unmetered' => 1, 'actual' => 6,
            'susp_metered' => 1, 'susp_unmetered' => 0, 'disc_metered' => 0, 'disc_unmetered' => 1, 'other' => 0,
        ];
        $r = array_merge($base, $r);

        $receivable = $r['opening'] + $r['billing'] + $r['adjustment'];
        $payments = $r['pay_month'] + $r['pay_prev'] + $r['pay_offset'];

        return [
            'code' => $r['code'],
            'vol_actual' => $r['vol_actual'],
            'vol_average' => $r['vol_average'],
            'vol_total' => $r['vol_total'] ?? $r['vol_actual'] + $r['vol_average'],
            'opening' => $r['opening'],
            'billing' => $r['billing'],
            'receivable' => $r['receivable'] ?? $receivable,
            'adjustment' => $r['adjustment'],
            'pay_month' => $r['pay_month'],
            'pay_prev' => $r['pay_prev'],
            'pay_offset' => $r['pay_offset'],
            'payments' => $r['payments'] ?? $payments,
            'closing' => $r['closing'] ?? $receivable - $payments,
            'ratio' => $r['billing'] == 0 ? '#VALUE!' : round($payments / $r['billing'], 4),
            'customers' => $r['customers'],
            'avg_metered' => $r['avg_metered'],
            'avg_unmetered' => $r['avg_unmetered'],
            'actual' => $r['actual'],
            'billed_total' => $r['billed_total'] ?? $r['avg_metered'] + $r['avg_unmetered'] + $r['actual'],
            'susp_metered' => $r['susp_metered'],
            'susp_unmetered' => $r['susp_unmetered'],
            'disc_metered' => $r['disc_metered'],
            'disc_unmetered' => $r['disc_unmetered'],
            'other' => $r['other'],
            'unbilled_total' => $r['unbilled_total'] ?? $r['susp_metered'] + $r['susp_unmetered'] + $r['disc_metered'] + $r['disc_unmetered'] + $r['other'],
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function defaultDistricts(): array
    {
        return [
            'SOWUTUOM' => [
                ['code' => 'SOWUTUOM 4601'],
                ['code' => 'SOWUTUOM 4602', 'billing' => 250.5, 'pay_month' => 100.25, 'pay_prev' => 60.0, 'opening' => -100.0],
            ],
            'ODORKOR' => [
                ['code' => 'ODORKOR 4701', 'billing' => 0.0, 'pay_month' => 0.0, 'pay_prev' => 0.0],
                ['code' => 'ODORKOR 4702', 'billing' => 80.0, 'adjustment' => 5.0],
            ],
        ];
    }

    /** Customer-list columns of the real export: field => sheet column. */
    public const CUSTOMER_COLUMNS = [
        'account' => 'B', 'meter_no' => 'C', 'name' => 'D', 'category' => 'F', 'status' => 'G', 'meter_status' => 'H',
        'address' => 'I', 'mobile' => 'N', 'email' => 'O', 'balance' => 'Q', 'last_read_date' => 'R', 'last_reading' => 'T',
        'last_bill_date' => 'U', 'last_bill_amount' => 'V', 'last_paid_date' => 'W', 'last_paid_amount' => 'X',
        'connect_date' => 'Y', 'estimated_consume' => 'Z', 'average_consume' => 'AA', 'meter_factor' => 'AB',
    ];

    private const CUSTOMER_HEADERS = [
        'account' => 'Account #', 'meter_no' => 'Meter  #', 'name' => 'Account Name', 'category' => 'Category', 'status' => 'Status',
        'meter_status' => 'Meter Status', 'address' => 'Residential Address', 'mobile' => 'Mobile', 'email' => 'Email',
        'balance' => 'Balance', 'last_read_date' => 'Last Read Date', 'last_reading' => 'Last Reading', 'last_bill_date' => 'Last Bill Date',
        'last_bill_amount' => 'Last Bill Amount', 'last_paid_date' => 'Last Paid Date', 'last_paid_amount' => 'Last Paid Amount',
        'connect_date' => 'Connect Date', 'estimated_consume' => 'Estimated Consume', 'average_consume' => 'Average Consume', 'meter_factor' => 'Meter Factor',
    ];

    /**
     * One invented customer. $n makes it unique; $over replaces any field. Names, addresses, phones and e-mails are obviously
     * fake (Customer 000123, 0240000123, c123@example.test): nothing here comes from a real export.
     *
     * @return array<string, mixed>
     */
    public static function customer(int $n, array $over = []): array
    {
        return $over + [
            'account' => str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT),
            'meter_no' => 'M'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'name' => 'Customer '.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'category' => '611', 'status' => 'ACTB', 'meter_status' => 'W',
            'address' => 'House '.$n.', Test Street',
            'mobile' => '024'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'email' => 'c'.$n.'@example.test',
            'balance' => 100.0 + $n, 'last_read_date' => '2026-09-20', 'last_reading' => 120 + $n,
            'last_bill_date' => '2026-09-25', 'last_bill_amount' => 80.0, 'last_paid_date' => '2026-09-28', 'last_paid_amount' => 60.0,
            'connect_date' => '2020-03-15', 'estimated_consume' => 0, 'average_consume' => 8, 'meter_factor' => 1,
        ];
    }

    /**
     * The customer-list report (rptCustomerDetails), laid out like the real export: a "Document map" sheet that must never be
     * loaded, then the report sheet with a title, a FILTERS cell (one multi-line cell), and per route a group heading, a header
     * row, the customers and a "<route> TOTALS :" row with "Customer Count: N" and the balance.
     *
     * $spec: region, district, routes (list of ['name' => string, 'customers' => list of customer arrays, 'count' => override
     * for the totals row, 'balance' => override, 'totals' => false to leave the totals row out]), sheet (sheet name).
     */
    public static function customerList(array $spec = []): string
    {
        $region = $spec['region'] ?? 'ACCRA WEST';
        $district = $spec['district'] ?? 'SOWUTUOM';
        $routes = $spec['routes'] ?? [['name' => '1001', 'customers' => [self::customer(1), self::customer(2)]]];

        $book = new Spreadsheet;
        $map = $book->getActiveSheet();
        $map->setTitle('Document map');
        $map->setCellValue('A1', 'Customer List Report');

        $sheet = $book->createSheet();
        $sheet->setTitle($spec['sheet'] ?? 'rptCustomerDetails');
        $sheet->setCellValue('J4', 'Customer List Report');
        $sheet->setCellValue('A5', 'FILTERS');
        $sheet->setCellValue('A7', "REGION: {$region}\nDISTRICT: {$district}\n");

        $row = 9;

        foreach ($routes as $route) {
            $sheet->setCellValue("B{$row}", $route['name'].', '.$district.', '.$region);
            $row++;

            foreach (self::CUSTOMER_HEADERS as $field => $label) {
                $sheet->setCellValue(self::CUSTOMER_COLUMNS[$field].$row, $label);
            }

            $row++;
            $balance = 0.0;
            $count = 0;

            foreach ($route['customers'] as $customer) {
                foreach (self::CUSTOMER_COLUMNS as $field => $column) {
                    $value = $customer[$field] ?? null;

                    if ($value === null) {
                        continue;
                    }

                    $cell = $column.$row;

                    if (str_ends_with($field, '_date') && is_string($value)) {
                        $sheet->setCellValue($cell, Date::PHPToExcel(Carbon::parse($value)));
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                    } elseif (in_array($field, ['account', 'meter_no', 'category', 'mobile'], true)) {
                        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue($cell, $value);
                    }
                }

                $balance += (float) ($customer['balance'] ?? 0);
                $count++;
                $row++;
            }

            if (($route['totals'] ?? true) !== false) {
                $sheet->setCellValue("B{$row}", $route['name'].' TOTALS :');
                $sheet->setCellValue("O{$row}", 'Customer Count: '.number_format($route['count'] ?? $count));
                $sheet->setCellValue("Q{$row}", $route['balance'] ?? round($balance, 2));
                $row++;
            }

            $row += 2;
        }

        return self::save($book);
    }

    /**
     * The same report, but written with a STREAMING writer so a file of tens of thousands of rows can be made without
     * PhpSpreadsheet's memory (used by the memory-budget test). Customers are invented from their number alone.
     */
    public static function customerListStreamed(int $rows, string $district = 'SOWUTUOM', string $region = 'ACCRA WEST', int $perRoute = 400, int $firstNumber = 1): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cust').'.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Document map');
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Customer List Report']));
        $writer->addNewSheetAndMakeItCurrent()->setName('rptCustomerDetails');
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Customer List Report']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['FILTERS']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(["REGION: {$region}\nDISTRICT: {$district}\n"]));

        $headers = [null, 'Account #', 'Meter  #', 'Account Name', null, 'Category', 'Status', 'Meter Status', 'Residential Address', null, null, null, null, 'Mobile', 'Email', null, 'Balance', 'Last Read Date', null, 'Last Reading', 'Last Bill Date', 'Last Bill Amount', 'Last Paid Date', 'Last Paid Amount', 'Connect Date', 'Estimated Consume', 'Average Consume', 'Meter Factor'];
        $day = new \DateTimeImmutable('2026-09-20');

        for ($route = 1; $route <= (int) ceil($rows / $perRoute); $route++) {
            $name = 'R'.str_pad((string) $route, 3, '0', STR_PAD_LEFT);
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([null, "{$name}, {$district}, {$region}"]));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
            $balance = 0.0;
            $count = 0;

            for ($i = ($route - 1) * $perRoute; $i < min($rows, $route * $perRoute); $i++) {
                $c = self::customer($firstNumber + $i);
                $balance += $c['balance'];
                $count++;
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([
                    null, $c['account'], $c['meter_no'], $c['name'], null, $c['category'], $c['status'], $c['meter_status'], $c['address'], null, null, null, null,
                    $c['mobile'], $c['email'], null, $c['balance'], $day, null, $c['last_reading'], $day, $c['last_bill_amount'], $day, $c['last_paid_amount'], $day,
                    $c['estimated_consume'], $c['average_consume'], $c['meter_factor'],
                ]));
            }

            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([null, "{$name} TOTALS :", null, null, null, null, null, null, null, null, null, null, null, null, 'Customer Count: '.number_format($count), null, round($balance, 2)]));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([]));
        }

        $writer->close();

        return $path;
    }

    public static function upload(string $path, string $name = 'report.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    protected static function totalsRow(array $sum, array $deltas): array
    {
        $row = ['code' => 'TOTALS'];

        foreach (self::BILLING_COLUMNS as $column) {
            if ($column === 'code') {
                continue;
            }

            $row[$column] = $column === 'ratio'
                ? ($sum['billing'] == 0 ? '#VALUE!' : round($sum['payments'] / $sum['billing'], 4))
                : $sum[$column] + ($deltas[$column] ?? 0);
        }

        return array_values($row);
    }

    protected static function readingTitleAndFilters(Worksheet $sheet, string $region, string $filters): void
    {
        $sheet->setCellValue('A1', 'Customer Meter Reading Report - CCA Summary - By Month');

        if ($filters === 'lines') {
            // As in the real export: the whole filter block is ONE cell holding several lines.
            $sheet->setCellValue('B7', "REPORT PERIOD: 01-June-2026 - 01-October-2026\nREGION: {$region}\nDISTRICT: SOWUTUOM,ODORKOR,KANESHIE\n");

            return;
        }

        $sheet->setCellValue('A3', 'REPORT PERIOD');
        $sheet->setCellValue('B3', '01-June-2026 to 01-October-2026');
        $sheet->setCellValue('A4', 'REGION');
        $sheet->setCellValue('B4', $region);
        $sheet->setCellValue('A5', 'DISTRICT');
        $sheet->setCellValue('B5', 'SOWUTUOM, ODORKOR, KANESHIE');
    }

    protected static function readingHeader(Worksheet $sheet, string $region, string $label, bool $merged, string $filters = 'lines'): void
    {
        self::readingTitleAndFilters($sheet, $region, $filters);

        $sheet->setCellValue('D9', $label);

        if ($merged) {
            $sheet->fromArray(['Month', 'Verified', 'Read', null, 'Skipped', null, 'Visited', null, 'Unvisited', null], null, 'A11', true);
            $sheet->fromArray([null, 'Strength', '#', '%', '#', '%', '#', '%', '#', '%'], null, 'A12', true);
            $sheet->mergeCells('C11:D11');
            $sheet->mergeCells('E11:F11');
            $sheet->mergeCells('G11:H11');
            $sheet->mergeCells('I11:J11');
        } else {
            $sheet->fromArray(['Month', 'Verified Strength', 'Read #', 'Read %', 'Skipped #', 'Skipped %', 'Visited #', 'Visited %', 'Unvisited #', 'Unvisited %'], null, 'A11', true);
        }
    }

    protected static function monthLabel(string $month): string
    {
        return Carbon::parse($month)->format('D d-M-Y');
    }

    protected static function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'comm').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
