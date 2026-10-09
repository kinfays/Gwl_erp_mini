<?php

namespace App\Services\Commercial\Customers;

use Generator;

/**
 * Reads the structure of the "Customer List Report" (rptCustomerDetails) from a stream of sheet rows. Everything is found
 * by LABEL, never by coordinate, so a column that shifts by one cannot load the wrong numbers:
 *
 *   FILTERS block     one multi-line cell, "REGION: <name>\nDISTRICT: <name>"
 *   group heading     "<route>, <DISTRICT>, <REGION>" in a single cell
 *   header row        repeated for every group; columns are mapped from it each time (tolerant: case, spacing)
 *   data rows         Account # is a 12-digit string
 *   totals row        "<route> TOTALS :" with "Customer Count: N" and the route's balance
 *
 * It yields events and keeps no rows in memory:
 *   ['kind' => 'filters', 'region' => ?string, 'district' => ?string]
 *   ['kind' => 'heading', 'route' => string, 'district' => ?string, 'region' => ?string]
 *   ['kind' => 'row', 'row_no' => int, 'route' => ?string, 'values' => array<field, mixed>]
 *   ['kind' => 'totals', 'row_no' => int, 'route' => string, 'count' => ?int, 'balance' => ?float]
 *   ['kind' => 'malformed', 'row_no' => int, 'reason' => string]     (never carries cell values: they are personal data)
 *   ['kind' => 'missing_columns', 'columns' => list<string>]          (a header row without a required column)
 */
class CustomerListParser
{
    /** Rows scanned at the top for the FILTERS cell. */
    protected const FILTER_SCAN_ROWS = 40;

    /** Header text (normalised) => field. */
    public const HEADERS = [
        'ACCOUNT #' => 'account', 'ACCOUNT NO' => 'account', 'ACCOUNT NUMBER' => 'account', 'ACCOUNT NO.' => 'account',
        'METER #' => 'meter_no', 'METER NO' => 'meter_no', 'METER NUMBER' => 'meter_no',
        'ACCOUNT NAME' => 'name', 'CUSTOMER NAME' => 'name', 'NAME' => 'name',
        'CATEGORY' => 'category', 'STATUS' => 'status', 'ACCOUNT STATUS' => 'status', 'METER STATUS' => 'meter_status',
        'RESIDENTIAL ADDRESS' => 'address', 'ADDRESS' => 'address',
        'MOBILE' => 'mobile', 'PHONE' => 'mobile', 'MOBILE NO' => 'mobile',
        'EMAIL' => 'email', 'E-MAIL' => 'email',
        'BALANCE' => 'balance',
        'LAST READ DATE' => 'last_read_date', 'LAST READING' => 'last_reading',
        'LAST BILL DATE' => 'last_bill_date', 'LAST BILL AMOUNT' => 'last_bill_amount',
        'LAST PAID DATE' => 'last_paid_date', 'LAST PAID AMOUNT' => 'last_paid_amount',
        'CONNECT DATE' => 'connect_date', 'CONNECTION DATE' => 'connect_date',
        'ESTIMATED CONSUME' => 'estimated_consume', 'AVERAGE CONSUME' => 'average_consume', 'METER FACTOR' => 'meter_factor',
    ];

    /** A header row without these cannot be imported. */
    public const REQUIRED = ['account', 'category', 'status', 'meter_status', 'balance'];

    /**
     * @param  iterable<int, list<mixed>>  $rows  row number => dense cells (XlsxStreamReader::rows())
     * @return Generator<int, array<string, mixed>>
     */
    public function parse(iterable $rows): Generator
    {
        $map = null;
        $route = null;
        $filtersSeen = false;
        $scanned = 0;

        foreach ($rows as $rowNumber => $cells) {
            // Fast path, taken by nearly every row: once a header has been seen, a row with a valid account number in the
            // account column IS a customer. Headings, headers, totals and the like are only looked for in the other rows.
            if ($map !== null) {
                $account = CustomerValues::accountNo($cells[$map['account']] ?? null);

                if ($account !== null) {
                    $values = [];

                    foreach ($map as $field => $index) {
                        $values[$field] = $cells[$index] ?? null;
                    }

                    $values['account'] = $account;

                    yield ['kind' => 'row', 'row_no' => $rowNumber, 'route' => $route, 'values' => $values];

                    continue;
                }
            }

            $filled = array_filter($cells, fn ($cell) => $cell !== null && $cell !== '');

            if ($filled === []) {
                continue;
            }

            if (! $filtersSeen && $scanned++ < self::FILTER_SCAN_ROWS) {
                $filters = $this->filters($filled);

                if ($filters !== null) {
                    $filtersSeen = true;

                    yield ['kind' => 'filters', ...$filters];

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
                $heading = $this->heading((string) reset($filled));

                if ($heading !== null) {
                    $route = $heading['route'];

                    yield ['kind' => 'heading', ...$heading];
                }

                continue;
            }

            if ($map === null || ! isset($map['account'])) {
                continue;
            }

            $values = [];

            foreach ($map as $field => $index) {
                $values[$field] = $cells[$index] ?? null;
            }

            $account = CustomerValues::accountNo($values['account']);

            if ($account === null) {
                // Several filled cells but no usable account number: a damaged row, counted and reported (without values).
                yield ['kind' => 'malformed', 'row_no' => $rowNumber, 'reason' => 'Account # is missing or not a 12-digit number'];

                continue;
            }

            $values['account'] = $account;

            yield ['kind' => 'row', 'row_no' => $rowNumber, 'route' => $route, 'values' => $values];
        }
    }

    /** @param  array<int, mixed>  $filled */
    protected function filters(array $filled): ?array
    {
        foreach ($filled as $cell) {
            if (! is_string($cell) || ! preg_match('/^\s*REGION\s*:/im', $cell)) {
                continue;
            }

            preg_match('/^\s*REGION\s*:\s*(.*?)\s*$/im', $cell, $region);
            preg_match('/^\s*DISTRICT\s*:\s*(.*?)\s*$/im', $cell, $district);

            return [
                'region' => ($region[1] ?? '') !== '' ? $region[1] : null,
                'district' => ($district[1] ?? '') !== '' ? $district[1] : null,
            ];
        }

        return null;
    }

    /**
     * Field => column index when the row is a header row (it holds the Account # label), else null.
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

        return isset($map['account']) && count($map) >= 3 ? $map : null;
    }

    public static function normalizeHeader(string $text): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * @param  array<int, mixed>  $filled
     * @param  array<string, int>|null  $map
     * @return array{route: string, count: ?int, balance: ?float}|null
     */
    protected function totals(array $filled, ?array $map): ?array
    {
        $first = null;
        $count = null;
        $countIndex = null;

        foreach ($filled as $index => $cell) {
            if (! is_string($cell)) {
                continue;
            }

            if ($first === null && preg_match('/^\s*(.+?)\s+TOTALS?\s*:?\s*$/iu', $cell, $matches)) {
                $first = trim($matches[1]);
            }

            if (preg_match('/customer\s*count\s*:\s*([\d.,\s]+)/iu', $cell, $matches)) {
                $count = (int) preg_replace('/\D/', '', $matches[1]);
                $countIndex = $index;
            }
        }

        // A customer whose NAME ends in "totals" is still a customer: a real account number settles it.
        if ($first === null || ($map !== null && isset($map['account']) && CustomerValues::accountNo($filled[$map['account']] ?? null) !== null)) {
            return null;
        }

        $balance = null;
        $balanceIndex = $map['balance'] ?? null;

        if ($balanceIndex !== null && isset($filled[$balanceIndex]) && (is_int($filled[$balanceIndex]) || is_float($filled[$balanceIndex]))) {
            $balance = (float) $filled[$balanceIndex];
        } else {
            foreach ($filled as $index => $cell) {
                if ($countIndex !== null && $index > $countIndex && (is_int($cell) || is_float($cell))) {
                    $balance = (float) $cell;

                    break;
                }
            }
        }

        return ['route' => $first, 'count' => $count, 'balance' => $balance];
    }

    /** "<route>, <DISTRICT>, <REGION>" (the route itself may contain commas). */
    protected function heading(string $text): ?array
    {
        $parts = array_map('trim', explode(',', $text));

        if (count($parts) < 3 || $parts[0] === '') {
            return null;
        }

        $region = array_pop($parts);
        $district = array_pop($parts);

        return ['route' => implode(', ', $parts), 'district' => $district !== '' ? $district : null, 'region' => $region !== '' ? $region : null];
    }
}
