<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the immutable per-batch facts with set-based SQL: INSERT ... SELECT ... GROUP BY over the district's current
 * customers (the ones present in the file; accounts flagged "not in latest file" are counted separately on the batch).
 * Every dashboard, trend and comparison reads these tables, never commercial_customers.
 *
 * "No read / bill / payment in N days" is measured against the batch's as_of_date, so a rollup stays true however old it
 * gets. The date cut-offs are written into the SQL as literals produced by Carbon (never user input).
 */
class CustomerRollupService
{
    /** Average-consumption histogram edges (upper bounds of bins 1..4; bin 0 is exactly 0, bin 5 is above the last). The unit is unconfirmed. */
    public const BIN_EDGES = [5, 10, 20, 50];

    /** Seconds each step of the last build() took (the benchmark reports them). @var array<string, float> */
    public array $timings = [];

    /** The most accounts stored per issue for the drill-down lists (the COUNT of an issue is always exact). */
    public const ISSUE_LIST_CAP = 10000;

    public const TABLES = [
        'commercial_customer_rollups', 'commercial_customer_rollups_district', 'commercial_customer_consumption', 'commercial_customer_quality',
        'commercial_customer_connections', 'commercial_customer_debt_stats', 'commercial_customer_issue_accounts',
    ];

    /** @param  list<int>  $billingStatusIds */
    public function build(CommercialCustomerBatch $batch, array $billingStatusIds): void
    {
        $this->timings = [];

        DB::transaction(function () use ($batch, $billingStatusIds): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('batch_id', $batch->id)->delete();
            }

            $this->timed('route_rollups', fn () => $this->rollups($batch, $billingStatusIds));
            $this->timed('district_rollups', fn () => $this->districtRollups($batch));
            $this->timed('consumption', fn () => $this->consumption($batch, $billingStatusIds));
            $this->timed('connections', fn () => $this->connections($batch, $billingStatusIds));
            $this->timed('debt', fn () => $this->debt($batch));
            $this->timed('issue_accounts', fn () => $this->issueAccounts($batch));
            $this->timed('quality', fn () => $this->quality($batch));
        });
    }

    protected function timed(string $step, callable $work): void
    {
        $start = microtime(true);
        $work();
        $this->timings[$step] = round(microtime(true) - $start, 3);
    }

    /** The route-level rows summed one level up, plus the number of routes (kept on the batch for the district tables). */
    protected function districtRollups(CommercialCustomerBatch $batch): void
    {
        $measures = $this->measureColumns();
        $columns = implode(', ', ['batch_id', 'region_id', 'district_id', 'category_id', 'status_id', 'meter_status_id', ...$measures]);
        $select = implode(', ', ['batch_id', 'region_id', 'district_id', 'category_id', 'status_id', 'meter_status_id', ...array_map(fn (string $column) => "SUM({$column})", $measures)]);

        DB::affectingStatement("INSERT INTO commercial_customer_rollups_district ({$columns}) SELECT {$select} FROM commercial_customer_rollups WHERE batch_id = ".(int) $batch->id.' GROUP BY batch_id, region_id, district_id, category_id, status_id, meter_status_id');

        $routes = (int) DB::table('commercial_customer_rollups')->where('batch_id', $batch->id)->distinct()->count('route_id');
        DB::table('commercial_customer_batches')->where('id', $batch->id)->update(['routes_count' => $routes]);
        $batch->routes_count = $routes;
    }

    /** @return list<string> every summed column of the rollup tables */
    protected function measureColumns(): array
    {
        $columns = ['customer_count', 'debit_count', 'credit_count', 'billed_count', 'never_read', 'never_billed', 'never_paid', 'zero_bill_count', 'ghost_count', 'est_count', 'avg_count',
            'balance_sum', 'debit_sum', 'credit_sum', 'billed_sum', 'paid_sum', 'est_sum', 'avg_sum'];

        foreach (range(0, 7) as $bucket) {
            $columns[] = 'bucket_'.$bucket;
        }

        foreach ([30, 60, 90, 180] as $days) {
            array_push($columns, "no_read_{$days}", "no_bill_{$days}", "no_pay_{$days}", "billed_unpaid_{$days}");
        }

        return $columns;
    }

    /**
     * The accounts behind the duplicate-style quality counts, stored so a drill-down is an index read. A meter / mobile /
     * e-mail shared inside the district is a candidate duplicate (or a landlord with several accounts): a list to review.
     */
    protected function issueAccounts(CommercialCustomerBatch $batch): void
    {
        $b = (int) $batch->id;
        $d = (int) $batch->district_id;
        $live = "c.district_id = {$d} AND c.missing_since_batch_id IS NULL";

        DB::affectingStatement(
            "INSERT INTO commercial_customer_issue_accounts (batch_id, issue, customer_id) SELECT {$b}, 'shared_meter', c.id FROM commercial_customers c "
            ."INNER JOIN (SELECT meter_no FROM commercial_customers WHERE district_id = {$d} AND missing_since_batch_id IS NULL AND meter_no IS NOT NULL GROUP BY meter_no HAVING COUNT(*) > 1) s ON s.meter_no = c.meter_no WHERE {$live}"
        );

        // The flag-based issues (no mobile, no e-mail, no address, no name, invalid phone, future / implausible dates) come from
        // the quality bits staged with the file, so their lists and their counts cannot disagree.
        $cap = self::ISSUE_LIST_CAP;
        $flagCounts = $this->flagCounts($b);

        foreach ([
            'missing_mobile' => CustomerRecordBuilder::MISSING_MOBILE, 'missing_email' => CustomerRecordBuilder::MISSING_EMAIL,
            'missing_address' => CustomerRecordBuilder::MISSING_ADDRESS, 'missing_name' => CustomerRecordBuilder::MISSING_NAME,
            'invalid_phone' => CustomerRecordBuilder::INVALID_PHONE, 'future_date' => CustomerRecordBuilder::FUTURE_DATE,
            'implausible_date' => CustomerRecordBuilder::IMPLAUSIBLE_DATE, 'multiple_phones' => CustomerRecordBuilder::MULTIPLE_PHONES,
        ] as $issue => $bit) {
            // An issue nobody has costs nothing: only issues that occur get a list.
            if (($flagCounts[$issue] ?? 0) === 0) {
                continue;
            }

            DB::affectingStatement(
                "INSERT INTO commercial_customer_issue_accounts (batch_id, issue, customer_id) SELECT {$b}, '{$issue}', c.id FROM commercial_customer_staging s "
                ."INNER JOIN commercial_customers c ON c.account_no = s.account_no WHERE s.batch_id = {$b} AND (s.quality_flags & {$bit}) = {$bit} LIMIT {$cap}"
            );
        }

        // A mobile is shared when it is on more than one ACCOUNT of the district, as first or second number. The district's contacts
        // are read ONCE (base) and unfolded into one row per number; the numbers of one account are unique (the parser drops
        // repeats), so COUNT(*) is the number of accounts, and an account in two shared numbers is listed once (DISTINCT).
        // Placeholders are never stored, so they cannot be shared.
        DB::affectingStatement(
            "INSERT INTO commercial_customer_issue_accounts (batch_id, issue, customer_id) "
            ."WITH base AS (SELECT t.customer_id AS cid, t.phone_primary AS p1, t.phone_secondary AS p2 FROM commercial_customer_contacts t INNER JOIN commercial_customers c2 ON c2.id = t.customer_id WHERE c2.district_id = {$d} AND c2.missing_since_batch_id IS NULL AND t.phone_primary IS NOT NULL), "
            .'numbers AS (SELECT cid, p1 AS v FROM base UNION ALL SELECT cid, p2 FROM base WHERE p2 IS NOT NULL), '
            .'shared AS (SELECT v FROM numbers GROUP BY v HAVING COUNT(*) > 1) '
            ."SELECT {$b}, 'shared_mobile', x.cid FROM (SELECT DISTINCT n.cid FROM numbers n INNER JOIN shared s ON s.v = n.v) x"
        );

        DB::affectingStatement(
            "INSERT INTO commercial_customer_issue_accounts (batch_id, issue, customer_id) SELECT {$b}, 'shared_email', c.id FROM commercial_customers c "
            ."INNER JOIN commercial_customer_contacts t ON t.customer_id = c.id "
            ."INNER JOIN (SELECT t2.email_lower AS v FROM commercial_customer_contacts t2 INNER JOIN commercial_customers c2 ON c2.id = t2.customer_id WHERE c2.district_id = {$d} AND c2.missing_since_batch_id IS NULL AND t2.email_lower IS NOT NULL GROUP BY t2.email_lower HAVING COUNT(*) > 1) s ON s.v = t.email_lower WHERE {$live}"
        );
    }

    /** @param  list<int>  $billing */
    protected function rollups(CommercialCustomerBatch $batch, array $billing): void
    {
        $asOf = Carbon::parse($batch->as_of_date);
        $billingList = $billing === [] ? '0' : implode(',', array_map('intval', $billing));
        $measures = [
            'customer_count' => 'COUNT(*)',
            'debit_count' => 'SUM(CASE WHEN c.balance > 0 THEN 1 ELSE 0 END)',
            'credit_count' => 'SUM(CASE WHEN c.balance < 0 THEN 1 ELSE 0 END)',
            'billed_count' => 'SUM(CASE WHEN c.last_bill_amount > 0 THEN 1 ELSE 0 END)',
            'never_read' => 'SUM(CASE WHEN c.last_read_date IS NULL THEN 1 ELSE 0 END)',
            'never_billed' => 'SUM(CASE WHEN c.last_bill_date IS NULL THEN 1 ELSE 0 END)',
            'never_paid' => 'SUM(CASE WHEN c.last_paid_date IS NULL THEN 1 ELSE 0 END)',
            'zero_bill_count' => 'SUM(CASE WHEN c.last_bill_date IS NOT NULL AND COALESCE(c.last_bill_amount, 0) = 0 THEN 1 ELSE 0 END)',
            'ghost_count' => $this->ghost($asOf, $billingList),
            'est_count' => 'SUM(CASE WHEN c.estimated_consume > 0 THEN 1 ELSE 0 END)',
            'avg_count' => 'SUM(CASE WHEN c.average_consume > 0 THEN 1 ELSE 0 END)',
            'balance_sum' => 'COALESCE(SUM(c.balance), 0)',
            'debit_sum' => 'COALESCE(SUM(CASE WHEN c.balance > 0 THEN c.balance ELSE 0 END), 0)',
            'credit_sum' => 'COALESCE(SUM(CASE WHEN c.balance < 0 THEN c.balance ELSE 0 END), 0)',
            'billed_sum' => 'COALESCE(SUM(c.last_bill_amount), 0)',
            'paid_sum' => 'COALESCE(SUM(c.last_paid_amount), 0)',
            'est_sum' => 'COALESCE(SUM(c.estimated_consume), 0)',
            'avg_sum' => 'COALESCE(SUM(c.average_consume), 0)',
        ];

        foreach (range(0, 7) as $bucket) {
            $measures['bucket_'.$bucket] = "SUM(CASE WHEN c.arrears_bucket = {$bucket} THEN 1 ELSE 0 END)";
        }

        foreach ([30, 60, 90, 180] as $days) {
            $cut = $asOf->copy()->subDays($days)->format('Y-m-d');
            $measures['no_read_'.$days] = "SUM(CASE WHEN c.last_read_date IS NULL OR c.last_read_date < '{$cut}' THEN 1 ELSE 0 END)";
            $measures['no_bill_'.$days] = "SUM(CASE WHEN c.last_bill_date IS NULL OR c.last_bill_date < '{$cut}' THEN 1 ELSE 0 END)";
            $measures['no_pay_'.$days] = "SUM(CASE WHEN c.last_paid_date IS NULL OR c.last_paid_date < '{$cut}' THEN 1 ELSE 0 END)";
            $measures['billed_unpaid_'.$days] = "SUM(CASE WHEN c.last_bill_amount > 0 AND (c.last_paid_date IS NULL OR c.last_paid_date < '{$cut}') THEN 1 ELSE 0 END)";
        }

        $columns = implode(', ', ['batch_id', 'region_id', 'district_id', 'route_id', 'category_id', 'status_id', 'meter_status_id', ...array_keys($measures)]);
        $select = implode(', ', [(int) $batch->id, (int) $batch->region_id, (int) $batch->district_id, 'c.route_id', 'c.category_id', 'c.status_id', 'c.meter_status_id', ...array_values($measures)]);

        DB::affectingStatement(
            "INSERT INTO commercial_customer_rollups ({$columns}) SELECT {$select} FROM commercial_customers c WHERE c.district_id = ".(int) $batch->district_id.' AND c.missing_since_batch_id IS NULL GROUP BY c.route_id, c.category_id, c.status_id, c.meter_status_id'
        );
    }

    /** A billing-status account with neither a bill nor a read in the last 180 days. */
    protected function ghost(Carbon $asOf, string $billingList): string
    {
        $cut = $asOf->copy()->subDays(180)->format('Y-m-d');

        return "SUM(CASE WHEN c.status_id IN ({$billingList}) AND (c.last_bill_date IS NULL OR c.last_bill_date < '{$cut}') AND (c.last_read_date IS NULL OR c.last_read_date < '{$cut}') THEN 1 ELSE 0 END)";
    }

    /** @param  list<int>  $billing */
    protected function consumption(CommercialCustomerBatch $batch, array $billing): void
    {
        $billingList = $billing === [] ? '0' : implode(',', array_map('intval', $billing));
        $b = (int) $batch->id;
        $d = (int) $batch->district_id;
        $live = "c.district_id = {$d} AND c.missing_since_batch_id IS NULL AND c.status_id IN ({$billingList})";

        $e = self::BIN_EDGES;
        $bins = [
            'bin_0' => 'SUM(CASE WHEN c.average_consume = 0 THEN 1 ELSE 0 END)',
            'bin_1' => "SUM(CASE WHEN c.average_consume > 0 AND c.average_consume <= {$e[0]} THEN 1 ELSE 0 END)",
            'bin_2' => "SUM(CASE WHEN c.average_consume > {$e[0]} AND c.average_consume <= {$e[1]} THEN 1 ELSE 0 END)",
            'bin_3' => "SUM(CASE WHEN c.average_consume > {$e[1]} AND c.average_consume <= {$e[2]} THEN 1 ELSE 0 END)",
            'bin_4' => "SUM(CASE WHEN c.average_consume > {$e[2]} AND c.average_consume <= {$e[3]} THEN 1 ELSE 0 END)",
            'bin_5' => "SUM(CASE WHEN c.average_consume > {$e[3]} THEN 1 ELSE 0 END)",
        ];

        $columns = implode(', ', ['batch_id', 'district_id', 'category_id', 'customers', 'zero_consume_billing', 'factor_not_one', 'factor_odd', ...array_keys($bins)]);
        $select = implode(', ', [$b, $d, 'c.category_id', 'SUM(CASE WHEN c.average_consume IS NOT NULL THEN 1 ELSE 0 END)', 'SUM(CASE WHEN c.average_consume = 0 THEN 1 ELSE 0 END)',
            'SUM(CASE WHEN c.meter_factor IS NOT NULL AND c.meter_factor <> 1 THEN 1 ELSE 0 END)',
            'SUM(CASE WHEN c.meter_factor IS NOT NULL AND (c.meter_factor < 0.1 OR c.meter_factor > 100) THEN 1 ELSE 0 END)', ...array_values($bins)]);

        DB::affectingStatement("INSERT INTO commercial_customer_consumption ({$columns}) SELECT {$select} FROM commercial_customers c WHERE {$live} AND c.average_consume IS NOT NULL GROUP BY c.category_id");

        // The median of each category, then how many sit above the configured multiple of it. Done per category (a handful of
        // rows), each a sorted slice of one district: cheap, portable, and never loads the customers into PHP.
        $multiple = (float) config('gwl.commercial_customer_outlier_multiple', 5);

        foreach (DB::table('commercial_customer_consumption')->where('batch_id', $b)->get(['id', 'category_id', 'customers']) as $row) {
            $middle = intdiv(max(1, (int) $row->customers) - 1, 2);
            $median = DB::table('commercial_customers AS c')
                ->whereRaw($live)
                ->where('c.category_id', $row->category_id)
                ->whereNotNull('c.average_consume')
                ->orderBy('c.average_consume')
                ->offset($middle)->limit(1)
                ->value('c.average_consume');

            $outliers = $median !== null && (float) $median > 0
                ? (int) DB::table('commercial_customers AS c')->whereRaw($live)->where('c.category_id', $row->category_id)->where('c.average_consume', '>', (float) $median * $multiple)->count()
                : 0;

            DB::table('commercial_customer_consumption')->where('id', $row->id)->update(['median_average' => $median, 'outliers' => $outliers]);
        }
    }

    /**
     * Debt concentration and medians for the district, taken from the (district_id, balance) index: how much the top 1 / 5 /
     * 10 percent of debtors owe, and the median balance. Done at import so no screen ever sorts millions of rows.
     */
    protected function debt(CommercialCustomerBatch $batch): void
    {
        $d = (int) $batch->district_id;
        $live = 'district_id = ? AND missing_since_batch_id IS NULL';
        $customers = (int) DB::table('commercial_customers')->whereRaw($live, [$d])->count();
        $debtors = (int) DB::table('commercial_customers')->whereRaw($live.' AND balance > 0', [$d])->count();
        $total = (int) DB::table('commercial_customers')->whereRaw($live.' AND balance > 0', [$d])->sum('balance');

        $median = fn (string $extra, int $count) => $count === 0 ? null : DB::table('commercial_customers')->whereRaw($live.$extra, [$d])->orderBy('balance')->offset(intdiv($count - 1, 2))->limit(1)->value('balance');
        $top = function (float $share) use ($d, $debtors): int {
            $k = max(1, (int) ceil($debtors * $share));

            return $debtors === 0 ? 0 : (int) DB::selectOne("SELECT COALESCE(SUM(t.balance), 0) AS s FROM (SELECT balance FROM commercial_customers WHERE district_id = ? AND missing_since_batch_id IS NULL AND balance > 0 ORDER BY balance DESC LIMIT {$k}) t", [$d])->s;
        };

        DB::table('commercial_customer_debt_stats')->insert([
            'batch_id' => (int) $batch->id, 'district_id' => $d, 'customers' => $customers, 'debtors' => $debtors, 'total_debit' => $total,
            'median_balance' => $median('', $customers), 'median_debit' => $median(' AND balance > 0', $debtors),
            'top1_sum' => $top(0.01), 'top5_sum' => $top(0.05), 'top10_sum' => $top(0.10),
        ]);
    }

    /** @param  list<int>  $billing */
    protected function connections(CommercialCustomerBatch $batch, array $billing): void
    {
        $billingList = $billing === [] ? '0' : implode(',', array_map('intval', $billing));
        $start = Carbon::parse($batch->as_of_date)->startOfMonth()->subMonths(23)->format('Y-m-d');
        $month = CustomerSql::monthStart('c.connect_date');

        DB::affectingStatement(
            'INSERT INTO commercial_customer_connections (batch_id, district_id, category_id, connect_month, customers, billing_customers) '.
            "SELECT ".(int) $batch->id.', '.(int) $batch->district_id.", c.category_id, {$month}, COUNT(*), SUM(CASE WHEN c.status_id IN ({$billingList}) THEN 1 ELSE 0 END) ".
            'FROM commercial_customers c WHERE c.district_id = '.(int) $batch->district_id." AND c.missing_since_batch_id IS NULL AND c.connect_date >= '{$start}' GROUP BY c.category_id, {$month}"
        );
    }

    /** @return array<string, int> how many staged rows carry each quality bit, in one pass over the staging rows */
    protected function flagCounts(int $batchId): array
    {
        $bits = [
            'missing_mobile' => CustomerRecordBuilder::MISSING_MOBILE,
            'missing_email' => CustomerRecordBuilder::MISSING_EMAIL,
            'missing_address' => CustomerRecordBuilder::MISSING_ADDRESS,
            'invalid_phone' => CustomerRecordBuilder::INVALID_PHONE,
            'future_date' => CustomerRecordBuilder::FUTURE_DATE,
            'implausible_date' => CustomerRecordBuilder::IMPLAUSIBLE_DATE,
            'missing_name' => CustomerRecordBuilder::MISSING_NAME,
            'multiple_phones' => CustomerRecordBuilder::MULTIPLE_PHONES,
        ];

        $select = implode(', ', array_map(fn (string $issue, int $bit) => "COALESCE(SUM(CASE WHEN (quality_flags & {$bit}) = {$bit} THEN 1 ELSE 0 END), 0) AS {$issue}", array_keys($bits), $bits)).', COUNT(*) AS staged_rows';
        $row = (array) DB::table('commercial_customer_staging')->where('batch_id', $batchId)->selectRaw($select)->first();

        return array_map(fn ($issue) => (int) ($row[$issue] ?? 0), array_combine([...array_keys($bits), 'staged_rows'], [...array_keys($bits), 'staged_rows']));
    }

    /**
     * Data-quality issue counts for the district. The row-level ones come from the quality bits staged with the file (so the
     * staging rows must still exist); the rest from the customers. Zero counts are stored too, so a trend can show "fixed".
     */
    protected function quality(CommercialCustomerBatch $batch): void
    {
        $b = (int) $batch->id;
        $d = (int) $batch->district_id;

        $counts = $this->flagCounts($b);
        $counts['reachable'] = max(0, $counts['staged_rows'] - $counts['missing_mobile']);   // customers with at least one valid mobile
        unset($counts['staged_rows']);

        $live = "c.district_id = {$d} AND c.missing_since_batch_id IS NULL";

        $counts['unknown_category'] = (int) DB::table('commercial_customers AS c')->join('commercial_customer_categories AS k', 'k.id', '=', 'c.category_id')->whereRaw($live)->where('k.is_unknown', true)->count();
        $counts['pending_category'] = (int) DB::table('commercial_customers AS c')->join('commercial_customer_categories AS k', 'k.id', '=', 'c.category_id')->whereRaw($live)->where('k.is_pending', true)->count();
        $counts['unconfirmed_status'] = (int) DB::table('commercial_customers AS c')->join('commercial_customer_statuses AS st', 'st.id', '=', 'c.status_id')->whereRaw($live)->where('st.meaning_confirmed', false)->count();

        foreach (['shared_meter', 'shared_mobile', 'shared_email'] as $issue) {
            $counts[$issue] = (int) DB::table('commercial_customer_issue_accounts')->where('batch_id', $b)->where('issue', $issue)->count();
        }

        $counts['not_in_file'] = (int) $batch->rows_missing;

        DB::table('commercial_customer_quality')->insert(array_map(
            fn (string $issue, int $count) => ['batch_id' => $b, 'district_id' => $d, 'issue' => $issue, 'issue_count' => $count],
            array_keys($counts),
            $counts
        ));
    }
}
