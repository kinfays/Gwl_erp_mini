<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The consumption history behind the "against the account's own history" analysis (docs/commercial-module-design.md, 8.26).
 *
 * commercial_customers keeps only the current average consumption, so every upload leaves one narrow row per BILLING account and
 * calendar month in commercial_customer_consumption_history: the file's average consumption at that upload. The latest upload of
 * a month replaces that month's rows, so weekly uploads do not multiply the storage. What is stored is the file's AVERAGE
 * consumption (a figure the billing system has already smoothed), not each month's metered consumption: a baseline made of
 * it is the account's own typical average, not its month-by-month use. A real monthly extract would replace it.
 *
 * The baseline an account is compared with is the mean of its stored months in the `window` months BEFORE the upload's month, and
 * it exists only from `min_months` of them. Nothing here holds a name, address, phone or e-mail.
 */
class CustomerConsumptionHistory
{
    /** Customers are copied in id ranges of this size, so a district of millions is never one statement. */
    protected const CHUNK = 200000;

    public function month(CommercialCustomerBatch $batch): Carbon
    {
        return Carbon::parse($batch->as_of_date)->startOfMonth();
    }

    /**
     * Writes the district's billing accounts as they stand now into the batch's month. Idempotent (the month's rows of the district
     * are replaced), so a resumed import or a second call changes nothing. A file that is not the district's newest by date is
     * skipped: the customers table holds the newest state, which must not be filed under an older month.
     *
     * @param  list<int>  $billingStatusIds
     * @return int rows written
     */
    public function record(CommercialCustomerBatch $batch, array $billingStatusIds): int
    {
        $d = (int) $batch->district_id;
        $month = $this->month($batch)->format('Y-m-d');

        $newer = CommercialCustomerBatch::query()->live()->where('district_id', $d)->whereDate('as_of_date', '>', Carbon::parse($batch->as_of_date)->format('Y-m-d'))->exists();

        if ($newer) {
            return 0;
        }

        $list = $billingStatusIds === [] ? '0' : implode(',', array_map('intval', $billingStatusIds));
        $b = (int) $batch->id;

        DB::table('commercial_customer_consumption_history')->where('district_id', $d)->where('period_month', $month)->delete();

        $bounds = DB::table('commercial_customers')->where('district_id', $d)->selectRaw('MIN(id) AS lo, MAX(id) AS hi')->first();
        $written = 0;

        if ($bounds && $bounds->lo !== null) {
            for ($from = (int) $bounds->lo - 1; $from < (int) $bounds->hi; $from += self::CHUNK) {
                $to = $from + self::CHUNK;

                $written += DB::affectingStatement(
                    'INSERT INTO commercial_customer_consumption_history (customer_id, period_month, district_id, batch_id, average_consume) '
                    ."SELECT c.id, '{$month}', c.district_id, {$b}, c.average_consume FROM commercial_customers c "
                    ."WHERE c.district_id = {$d} AND c.id > {$from} AND c.id <= {$to} AND c.missing_since_batch_id IS NULL AND c.status_id IN ({$list}) AND c.average_consume IS NOT NULL "
                    .$this->onConflict()
                );
            }
        }

        $months = $this->monthsBefore($batch);
        DB::table('commercial_customer_batches')->where('id', $b)->update(['history_months' => $months]);
        $batch->history_months = $months;

        return $written;
    }

    /** A customer who moved district keeps ONE row for the month: the newest write wins. */
    protected function onConflict(): string
    {
        if (CustomerSql::isMysql()) {
            return 'ON DUPLICATE KEY UPDATE district_id = VALUES(district_id), batch_id = VALUES(batch_id), average_consume = VALUES(average_consume)';
        }

        return 'ON CONFLICT (customer_id, period_month) DO UPDATE SET district_id = excluded.district_id, batch_id = excluded.batch_id, average_consume = excluded.average_consume';
    }

    /** First day of the oldest month in the baseline window. */
    public function windowStart(CommercialCustomerBatch $batch): string
    {
        return $this->month($batch)->subMonths(max(1, (int) config('gwl.commercial_customer_history_window_months', 6)))->format('Y-m-d');
    }

    /** How many EARLIER months the district has stored inside the baseline window. */
    public function monthsBefore(CommercialCustomerBatch $batch): int
    {
        return (int) DB::table('commercial_customer_consumption_history')
            ->where('district_id', (int) $batch->district_id)
            ->where('period_month', '>=', $this->windowStart($batch))
            ->where('period_month', '<', $this->month($batch)->format('Y-m-d'))
            ->distinct()->count('period_month');
    }

    public function minMonths(): int
    {
        return max(2, (int) config('gwl.commercial_customer_history_min_months', 3));
    }

    /**
     * The derived table of baselines: one row per account of the district that has at least min_months earlier months in the
     * window and a baseline above zero. Columns customer_id, base. The only place the baseline is defined.
     */
    public function baselineSql(CommercialCustomerBatch $batch): string
    {
        return 'SELECT customer_id, AVG(average_consume) AS base FROM commercial_customer_consumption_history '
            .'WHERE district_id = '.(int) $batch->district_id." AND period_month >= '".$this->windowStart($batch)."' AND period_month < '".$this->month($batch)->format('Y-m-d')."' "
            .'GROUP BY customer_id HAVING COUNT(*) >= '.$this->minMonths().' AND AVG(average_consume) > 0';
    }

    /** The batch's rows go with it when it is voided (the month then simply lacks this upload's figures). */
    public function forgetBatch(int $batchId): void
    {
        DB::table('commercial_customer_consumption_history')->where('batch_id', $batchId)->delete();
    }

    /**
     * Drops months past the retention. Chunked by month, so a big table is never one statement.
     *
     * @return int rows deleted (or that would be, with $dry)
     */
    public function prune(bool $dry = false): int
    {
        $keep = max(3, (int) config('gwl.commercial_customer_history_keep_months', 24));
        $cutoff = now()->startOfMonth()->subMonths($keep)->format('Y-m-d');
        $total = 0;

        foreach (DB::table('commercial_customer_consumption_history')->where('period_month', '<', $cutoff)->distinct()->pluck('period_month') as $month) {
            $query = DB::table('commercial_customer_consumption_history')->where('period_month', $month);

            if ($dry) {
                $total += (clone $query)->count();

                continue;
            }

            do {
                $deleted = DB::affectingStatement('DELETE FROM commercial_customer_consumption_history WHERE period_month = ? AND customer_id IN (SELECT customer_id FROM (SELECT customer_id FROM commercial_customer_consumption_history WHERE period_month = ? LIMIT 100000) t)', [$month, $month]);
                $total += $deleted;
            } while ($deleted > 0);
        }

        return $total;
    }
}
