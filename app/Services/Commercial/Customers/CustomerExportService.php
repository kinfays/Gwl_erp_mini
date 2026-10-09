<?php

namespace App\Services\Commercial\Customers;

use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Customer-list exports.
 *
 *   summary()    the rollup-based analysis as titled tables (same array shape as CommercialExportService, so the existing
 *                workbook / PDF builders render it); aggregates only, no personal data.
 *   writeList()  a customer list streamed to a file in keyset pages with a streaming writer: memory does not depend on the
 *                number of rows. Name and address columns exist only for holders of commercial.view_customer_details, and
 *                phones / e-mails are masked even then (the full contact details never leave in bulk).
 *
 * Callers check permission and scope, apply the row cap and write the audit row (counts and filters only).
 */
class CustomerExportService
{
    public const LIST_HEADINGS = [
        'Account', 'District', 'Route', 'Category', 'Status', 'Meter status', 'Meter number', 'Balance (GH¢)', 'Bills owed',
        'Last bill date', 'Last bill (GH¢)', 'Last payment date', 'Last payment (GH¢)', 'Last read date', 'Connect date', 'Not in latest file',
    ];

    public const PII_HEADINGS = ['Name', 'Address', 'Mobile (masked)', 'E-mail (masked)'];

    // ---------------------------------------------------------------- summary

    /**
     * @param  array<string, mixed>  $sections  overview, receivables, meters, collection, dormancy, quality (analytics payloads)
     * @param  array<string, string>  $meta  header lines (region, period ...)
     * @return array<string, mixed>
     */
    public function summary(array $sections, array $meta, ?string $period): array
    {
        $o = $sections['overview'];
        $r = $sections['receivables'];
        $m = $sections['meters'];
        $d = $sections['dormancy'];
        $q = $sections['quality'];
        $share = fn (array $rows) => array_map(fn ($row) => [$row['label'], $row['customers'], $row['share'] ?? ''], $rows);

        $tables = [
            ['title' => 'Customer base', 'headings' => ['Measure', 'Customers', 'Share (%)'], 'rows' => [
                ['All customers', $o['total'], ''],
                ['Billing accounts (ACTB)', $o['billing'], $o['billing_share'] ?? ''],
                ['Active, not billing (ACTN)', $o['active_non_billing'], ''],
                ['Disconnected', $o['disconnected'], ''],
                ['Suspended', $o['suspended'], ''],
                ['Other status codes', $o['other_status'], ''],
                ['Not in the latest file', $o['not_in_file'], ''],
            ]],
            ['title' => 'By category group', 'headings' => ['Group', 'Customers', 'Share (%)'], 'rows' => $share($o['by_group'])],
            ['title' => 'By account status', 'headings' => ['Status', 'Customers', 'Share (%)'], 'rows' => $share($o['by_status'])],
            ['title' => 'By district', 'headings' => ['District', 'Customers', 'Routes', 'Customers per route'], 'rows' => array_map(fn ($row) => [$row['label'], $row['customers'], $row['routes'], $row['per_route'] ?? ''], $o['by_district'])],
            ['title' => 'Meter health', 'headings' => [ucfirst($m['by']), 'Customers', 'Working', 'Faulty', 'Faulty (%)', 'No meter', 'No meter (%)', 'Faulty and billing', 'No meter and billing'], 'rows' => array_map(fn ($row) => [$row['label'], $row['customers'], $row['working'], $row['faulty'], $row['faulty_pct'] ?? '', $row['no_meter'], $row['no_meter_pct'] ?? '', $row['faulty_billing'], $row['no_meter_billing']], $m['rows'])],
            ['title' => 'Receivables', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Owed (GH¢)', $r['debit']], ['Customers owing', $r['debit_count']], ['Credit balances (GH¢)', $r['credit']], ['Customers in credit', $r['credit_count']],
                ['Net balance (GH¢)', $r['net']], ['Owed by disconnected / suspended (GH¢)', $r['owing_after_leaving']['debit']],
                ['Top 1% of debtors hold (%)', $r['concentration']['top1_share'] ?? ''], ['Top 10% of debtors hold (%)', $r['concentration']['top10_share'] ?? ''],
            ]],
            ['title' => 'Bills owed', 'headings' => ['Bucket', 'Customers', 'Share (%)'], 'rows' => array_map(fn ($b) => [$b['label'], $b['customers'], $b['share'] ?? ''], $r['buckets'])],
            ['title' => 'No activity', 'headings' => ['Window (days)', 'No read', 'No bill', 'No payment'], 'rows' => array_map(fn ($w) => [$w['days'], $w['no_read'], $w['no_bill'], $w['no_pay']], $d['windows'])],
            ['title' => 'Data quality', 'headings' => ['Issue', 'Accounts', 'Share (%)'], 'rows' => array_map(fn ($i) => [$i['label'], $i['count'], $i['share'] ?? ''], $q['issues'])],
        ];

        return [
            'key' => 'customer-summary',
            'file' => ['region' => (string) ($meta['Region'] ?? 'all'), 'period' => (string) ($period ?? 'latest')],
            'title' => 'Customer list summary',
            'meta' => $meta,
            'notes' => [
                'Aggregates only: no customer names, phone numbers, e-mails or addresses are in this file.',
                'A negative balance is treated as a customer credit and a positive one as owed; the sign convention is to be confirmed.',
                'Status codes whose meaning is not confirmed are shown as their raw code. The category grouping is a proposal.',
                'Bills owed = the balance divided by the customer\'s last bill: the file has no age of debt.',
            ],
            'tables' => $tables,
            'row_count' => array_sum(array_map(fn (array $table) => count($table['rows']), $tables)),
        ];
    }

    // ---------------------------------------------------------------- list

    /**
     * A row whose text is always TEXT. OpenSpout's own Row::fromValues() turns any string that starts with "=" into a formula,
     * which would let a customer name like =HYPERLINK(...) run when the file is opened: every string is forced to a string cell.
     *
     * @param  list<mixed>  $values
     */
    protected function row(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }

    /**
     * How many rows a list would hold, when the rollups can say exactly (district / route / group / status / meter filters
     * only); null when it cannot (a balance bucket, a data-quality issue, "not in file"), so the caller treats it as large.
     *
     * @param  array<string, mixed>  $q  the same filter array CustomerListService::page() takes
     */
    public function estimate(array $q): ?int
    {
        if (! empty($q['issue']) || ! empty($q['missing']) || (isset($q['bucket']) && $q['bucket'] !== null && $q['bucket'] !== '')) {
            return null;
        }

        $rollups = DB::table('commercial_customer_rollups AS r')
            ->join('commercial_customer_batches AS b', function ($join): void {
                $join->on('b.id', '=', 'r.batch_id')->where('b.status', 'imported');
            })
            ->join('commercial_customer_categories AS k', 'k.id', '=', 'r.category_id')
            ->where('r.district_id', (int) ($q['district_id'] ?? 0))
            ->when(isset($q['restriction']) && $q['restriction'] !== null, fn ($query) => $q['restriction'] === 0 ? $query->whereRaw('1 = 0') : $query->where('r.region_id', (int) $q['restriction']))
            ->when($q['route_id'] ?? null, fn ($query, $id) => $query->where('r.route_id', (int) $id))
            ->when($q['category_id'] ?? null, fn ($query, $id) => $query->where('r.category_id', (int) $id))
            ->when($q['group'] ?? null, fn ($query, $group) => $query->where('k.category_group', (string) $group))
            ->when($q['status_id'] ?? null, fn ($query, $id) => $query->where('r.status_id', (int) $id))
            ->when($q['meter_status_id'] ?? null, fn ($query, $id) => $query->where('r.meter_status_id', (int) $id));

        // Only the district's newest imported batch counts.
        $newest = DB::table('commercial_customer_batches')->where('district_id', (int) ($q['district_id'] ?? 0))->where('status', 'imported')->orderByDesc('as_of_date')->orderByDesc('id')->value('id');

        return $newest ? (int) $rollups->where('r.batch_id', $newest)->sum('r.customer_count') : 0;
    }

    /**
     * Streams a list to an .xlsx file and returns how many rows it wrote and whether the cap cut it short.
     *
     * @param  array<string, mixed>  $q  CustomerListService::page() filters (restriction included)
     * @return array{rows: int, truncated: bool}
     */
    public function writeList(string $path, array $q, bool $details, int $cap, CustomerListService $lists): array
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Customers');
        $writer->addRow($this->row($details ? [...self::LIST_HEADINGS, ...self::PII_HEADINGS] : self::LIST_HEADINGS));

        $written = 0;
        $after = null;
        $truncated = false;

        do {
            $page = $lists->page($q + ['after' => $after, 'size' => 2000, 'max_size' => 2000, 'details' => $details]);

            foreach ($page['rows'] as $row) {
                if ($written >= $cap) {
                    $truncated = true;

                    break 2;
                }

                $cells = [
                    $row['account_no'], $row['district'], $row['route'], $row['category'], $row['status'], $row['meter_status'], $row['meter_no'], $row['balance'], $row['bucket'],
                    $row['last_bill_date'], $row['last_bill_amount'], $row['last_paid_date'], $row['last_paid_amount'], $row['last_read_date'], $row['connect_date'], $row['missing'] ? 'Yes' : '',
                ];

                if ($details) {
                    array_push($cells, $row['name'], $row['address'], $row['mobile'], $row['email']);
                }

                $writer->addRow($this->row($cells));
                $written++;
            }

            $after = $page['next'];
        } while ($after !== null);

        if ($truncated) {
            $writer->addRow($this->row(['Limited to the first '.number_format($cap).' rows (the export row cap). Narrow the filters to export the rest.']));
        }

        $writer->close();

        return ['rows' => $written, 'truncated' => $truncated];
    }
}
