<?php

namespace App\Services\Commercial\BillingDetails;

use App\Services\Commercial\Customers\CustomerValues;
use App\Services\Commercial\Customers\XlsxStreamReader;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;

/**
 * Reads the structure of the "Detailed Billing Report" (rptBillingDetails_ExP) from a stream of sheet rows. ONE sheet, ONE ROW PER
 * CUSTOMER, grouped by route. Everything is found by LABEL, never by coordinate:
 *
 *   filter block   "BILL PERIOD: September-2026 - September-2026" and "REGION: ACCRA WEST" (separate cells or one multi-line cell)
 *   route heading  "DISTRICT code" in a single cell ("AMASAMAN 4601"; "0000" holds the unassigned customers)
 *   header row     repeated for every route; the columns are mapped from it each time
 *   customer row   Account # is a 12-digit number
 *   totals row     a label, "Customer Count: N" in the count column, and the route's sums
 *
 * What is NEVER read: Account Name, Meter #, the readings, any address or phone. Those columns are not mapped, so no value from them is
 * ever copied into an event (the mapped fields are the only cells looked at), which is what keeps names and meter numbers out of memory,
 * logs, errors and the database. Account numbers are read (and kept only on the capped exception lists and the staging table).
 *
 * It yields events and keeps no rows in memory:
 *   ['kind' => 'filters', 'period' => ?string, 'region' => ?string]
 *   ['kind' => 'heading', 'label' => string]
 *   ['kind' => 'row', 'row_no' => int, 'v' => array<field, mixed>]            v = the mapped cells (account is the normalised 12 digits)
 *   ['kind' => 'totals', 'row_no' => int, 'count' => ?int, 'sums' => array<field, float>]
 *   ['kind' => 'malformed', 'row_no' => int, 'reason' => string]              never carries a cell value
 *   ['kind' => 'missing_columns', 'columns' => list<string>]
 */
class BillingDetailsParser
{
    /** Rows scanned at the top for the filter block and the title. */
    public const SCAN_ROWS = 60;

    /** normalised header (letters and digits only, parenthesised percentages removed) => field. Only these columns are ever read. */
    public const HEADERS = [
        'ACCOUNT' => 'account', 'ACCOUNTNO' => 'account', 'ACCOUNTNUMBER' => 'account',
        'SERVICECATEGORY' => 'category', 'CATEGORY' => 'category',
        'STATUS' => 'status', 'ACCOUNTSTATUS' => 'status', 'CUSTOMERSTATUS' => 'status',
        'METERSTATUS' => 'meter_status',
        'CONSUME' => 'consume', 'CONSUMPTION' => 'consume',
        'RATE' => 'rate',
        'WATERCHARGE' => 'water', 'RURALWATER' => 'rural', 'FIREFIGHTING' => 'fire', 'SEWAGE' => 'sewage', 'SERVICECHARGE' => 'service',
        'MONTHTOTAL' => 'month_total',
        'PREVIOUSBALANCE' => 'previous_balance', 'PREVBALANCE' => 'previous_balance',
        'PAIDTHISMONTH' => 'paid',
        'PREVMONTHPAYMENT' => 'prev_payment', 'PREVIOUSMONTHPAYMENT' => 'prev_payment',
        'OFFSETPAYMENT' => 'offset', 'OFFSETPAYMENTS' => 'offset',
        'ADJUSTED' => 'adjusted',
        'BALANCEDUE' => 'balance_due',
        'BILLMONTH' => 'bill_month',
        'LASTREADDATE' => 'last_read',
        'BILLSTATUS' => 'bill_status',
    ];

    /** A header row without these cannot be imported. */
    public const REQUIRED = ['account', 'status', 'bill_status', 'consume', 'month_total', 'previous_balance', 'paid', 'balance_due'];

    /** The money columns of a totals row (what the route's totals row is compared with, and the other sums it prints). */
    public const SUM_FIELDS = ['consume', 'water', 'rural', 'fire', 'sewage', 'service', 'month_total', 'previous_balance', 'paid', 'prev_payment', 'offset', 'adjusted', 'balance_due'];

    /**
     * @param  iterable<int, list<mixed>>  $rows  row number => dense cells (XlsxStreamReader::rows())
     * @return Generator<int, array<string, mixed>>
     */
    public function parse(iterable $rows): Generator
    {
        $map = null;
        $filtersSeen = false;
        $scanned = 0;
        $period = null;
        $region = null;

        foreach ($rows as $rowNumber => $cells) {
            // Fast path, taken by nearly every row: once a header has been seen, a valid account number in the account column IS a customer.
            if ($map !== null) {
                $account = CustomerValues::accountNo($cells[$map['account']] ?? null);

                if ($account !== null) {
                    yield ['kind' => 'row', 'row_no' => $rowNumber, 'v' => $this->values($cells, $map, $account)];

                    continue;
                }
            }

            $filled = array_filter($cells, fn ($cell) => $cell !== null && $cell !== '');

            if ($filled === []) {
                continue;
            }

            // the filter block sits in the first rows, before any header
            if ($scanned++ < self::SCAN_ROWS && ! $this->isTotals($filled)) {
                $found = $this->filters($filled);

                if ($found['period'] !== null || $found['region'] !== null) {
                    $period ??= $found['period'];
                    $region ??= $found['region'];

                    if (! $filtersSeen && $period !== null && $region !== null) {
                        $filtersSeen = true;
                    }

                    yield ['kind' => 'filters', 'period' => $found['period'], 'region' => $found['region']];

                    continue;
                }
            }

            $header = $this->headerMap($cells);

            if ($header !== null) {
                $map = $header;
                $missing = array_values(array_diff(self::REQUIRED, array_keys($header)));

                if ($missing !== []) {
                    yield ['kind' => 'missing_columns', 'columns' => $missing];
                }

                continue;
            }

            $totals = $this->totals($filled, $map);

            if ($totals !== null) {
                yield ['kind' => 'totals', 'row_no' => $rowNumber, ...$totals];

                continue;
            }

            if (count($filled) === 1) {
                $label = $this->heading((string) reset($filled));

                if ($label !== null) {
                    yield ['kind' => 'heading', 'label' => $label];
                }

                continue;
            }

            if ($map === null || ! isset($map['account'])) {
                continue;
            }

            // several filled cells but no usable account number: a damaged row, counted and reported (without values)
            yield ['kind' => 'malformed', 'row_no' => $rowNumber, 'reason' => 'Account # is missing or not a 12-digit number'];
        }
    }

    // ---------------------------------------------------------------- detection

    /**
     * Whether a workbook is the Detailed Billing Report, from the title and the header text of its first sheet (cheap: a streamed read of
     * the first rows only, flat memory whatever the size). The first sheet that is not a "Document map". Returns what the top of the sheet
     * says, or null when it is not this report.
     *
     * @return array{sheet: string, period: ?string, region: ?string, fields: list<string>, missing: list<string>, titled: bool}|null
     */
    public function detect(string $path): ?array
    {
        try {
            $reader = new XlsxStreamReader($path);
        } catch (\Throwable) {
            return null;
        }

        try {
            $sheet = null;

            foreach ($reader->sheetNames() as $name) {
                if (! preg_match('/^\s*document\s*map\s*$/i', $name)) {
                    $sheet = $name;

                    break;
                }
            }

            if ($sheet === null) {
                return null;
            }

            $period = null;
            $region = null;
            $titled = (bool) preg_match('/rptBillingDetails/i', $sheet);
            $map = null;
            $seen = 0;

            foreach ($reader->rows($sheet) as $cells) {
                $filled = array_filter($cells, fn ($cell) => $cell !== null && $cell !== '');

                if ($filled === []) {
                    continue;
                }

                $found = $this->filters($filled);
                $period ??= $found['period'];
                $region ??= $found['region'];

                foreach ($filled as $cell) {
                    if (is_string($cell) && preg_match('/detailed\s+billing\s+report/i', $cell)) {
                        $titled = true;
                    }
                }

                $map ??= $this->headerMap($cells);

                if ($map !== null || ++$seen >= self::SCAN_ROWS) {
                    break;
                }
            }
        } catch (\Throwable) {
            return null;
        } finally {
            $reader->close();
        }

        // the header text decides: the account and bill status columns beside the money columns of this report
        if ($map === null || ! isset($map['bill_status'], $map['month_total'], $map['balance_due'])) {
            return null;
        }

        return ['sheet' => $sheet, 'period' => $period, 'region' => $region, 'fields' => array_keys($map), 'missing' => array_values(array_diff(self::REQUIRED, array_keys($map))), 'titled' => $titled];
    }

    // ---------------------------------------------------------------- values

    /** The mapped cells of one customer row; the account is the normalised 12 digits. @return array<string, mixed> */
    protected function values(array $cells, array $map, string $account): array
    {
        $values = ['account' => $account];

        foreach ($map as $field => $index) {
            if ($field !== 'account') {
                $values[$field] = $cells[$index] ?? null;
            }
        }

        return $values;
    }

    /** @param  array<int, mixed>  $filled @return array{period: ?string, region: ?string} */
    protected function filters(array $filled): array
    {
        $period = null;
        $region = null;

        foreach ($filled as $cell) {
            if (! is_string($cell)) {
                continue;
            }

            if ($period === null && preg_match('/^\s*BILL\s*PERIOD\s*:\s*(.+?)\s*$/im', $cell, $m)) {
                $period = $m[1];
            }

            if ($region === null && preg_match('/^\s*REGION\s*:\s*(.+?)\s*$/im', $cell, $m)) {
                $region = $m[1];
            }
        }

        return ['period' => $period, 'region' => $region];
    }

    /**
     * Field => column index when the row is a header row (it holds the Account # label and several of this report's columns), else null.
     *
     * @param  list<mixed>  $cells
     * @return array<string, int>|null
     */
    protected function headerMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            if (! is_string($cell)) {
                continue;
            }

            $field = self::HEADERS[self::normalizeHeader($cell)] ?? null;

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return isset($map['account']) && count($map) >= 6 ? $map : null;
    }

    /** "Rural Water (2%)" => RURALWATER, "Account #" => ACCOUNT. */
    public static function normalizeHeader(string $text): string
    {
        $text = preg_replace('/\([^)]*\)/u', '', $text) ?? $text;

        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]+/u', '', $text) ?? $text);
    }

    /** @param  array<int, mixed>  $filled */
    protected function isTotals(array $filled): bool
    {
        foreach ($filled as $cell) {
            if (is_string($cell) && preg_match('/customer\s*count\s*:/i', $cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $filled
     * @param  array<string, int>|null  $map
     * @return array{count: ?int, sums: array<string, float>}|null
     */
    protected function totals(array $filled, ?array $map): ?array
    {
        $count = null;

        foreach ($filled as $cell) {
            if (is_string($cell) && preg_match('/customer\s*count\s*:?\s*([\d.,\s]+)/iu', $cell, $m)) {
                $count = (int) preg_replace('/\D/', '', $m[1]);

                break;
            }
        }

        if ($count === null) {
            return null;
        }

        // a customer whose NAME contained that text is still a customer: a real account number settles it (the fast path took it already)
        $sums = [];

        foreach ($map ?? [] as $field => $index) {
            if (in_array($field, self::SUM_FIELDS, true) && isset($filled[$index])) {
                $number = self::number($filled[$index]);

                if ($number !== null) {
                    $sums[$field] = $number;
                }
            }
        }

        return ['count' => $count, 'sums' => $sums];
    }

    /** "AMASAMAN 4601", or a bare code; null for a title, a page line or a filter. */
    protected function heading(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '' || str_contains($text, ':') || preg_match('/^page\b/i', $text) || ! preg_match('/\d{1,12}$/', $text)) {
            return null;
        }

        return mb_substr($text, 0, 120);
    }

    // ---------------------------------------------------------------- cell helpers (also used by the scan)

    /** A number from a cell (a numeric cell, or text such as "1,234.50"); null when the cell is empty or not a number. */
    public static function number(mixed $cell): ?float
    {
        if (is_int($cell) || is_float($cell)) {
            return (float) $cell;
        }

        if (! is_string($cell)) {
            return null;
        }

        $text = str_replace([',', ' '], '', trim($cell));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** A date from a cell as Y-m-d; null when it is empty, unreadable or the "no date" 2000-01-01 (Excel serial 36526) or earlier. */
    public static function date(mixed $cell): ?string
    {
        $date = null;

        if ($cell instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($cell);
        } elseif (is_int($cell) || is_float($cell)) {
            $date = XlsxStreamReader::serialToDate((float) $cell);
        } elseif (is_string($cell) && trim($cell) !== '') {
            try {
                $date = new DateTimeImmutable(trim($cell));
            } catch (\Exception) {
                $date = null;
            }
        }

        if ($date === null || $date->format('Y-m-d') <= '2000-01-01') {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * The calendar month a Bill Month cell stands for, as [first day, last day]; null when it cannot be read ("September-2026", "Sep 2026",
     * "2026-09", "09/2026", a date, or a date serial).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function billMonth(mixed $cell): ?array
    {
        $date = null;

        if ($cell instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($cell);
        } elseif (is_int($cell) || is_float($cell)) {
            $date = XlsxStreamReader::serialToDate((float) $cell);
        } elseif (is_string($cell) && trim($cell) !== '') {
            $text = trim($cell);

            if (preg_match('/^(\d{1,2})\s*[\/\-]\s*(\d{4})$/', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
                $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $m[2], $m[1]));
            } else {
                try {
                    $date = new DateTimeImmutable(preg_match('/^[A-Za-z]+[\s\-\/]+\d{4}$/', $text) ? '1 '.str_replace(['-', '/'], ' ', $text) : $text);
                } catch (\Exception) {
                    $date = null;
                }
            }
        }

        if ($date === null || (int) $date->format('Y') < 2001) {
            return null;
        }

        return [$date->format('Y-m-01'), $date->format('Y-m-t')];
    }
}
