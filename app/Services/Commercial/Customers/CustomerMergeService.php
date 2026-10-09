<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use Illuminate\Support\Facades\DB;

/**
 * Merges the staged rows of a batch into commercial_customers with set-based SQL: no Eloquent per-row saves, no per-row
 * queries. For each chunk of staging ids, in ONE transaction that also advances the batch's merge_cursor (so a killed job
 * resumes at the next chunk and never repeats one):
 *
 *   1. the pre-image of every existing row that is about to change goes to commercial_customer_undo;
 *   2. one change-log row per tracked field that differs (status, category, meter status, route, district, arrears bucket),
 *      plus "returned" for an account that had been missing;
 *   3. the changed rows are updated; a row whose attributes_hash is unchanged is NOT written at all, so re-importing an
 *      unchanged file writes (almost) nothing;
 *   4. unknown accounts are inserted, with a "new" change-log row;
 *   5. contacts (PII) are inserted or updated only where their own hash differs.
 *
 * Afterwards markMissing() flags the accounts of the district that this file no longer lists. They are never deleted.
 */
class CustomerMergeService
{
    /** Diff key of an account the file no longer lists = this + its customer id (2^62: far above any staging id, fits an unsigned or signed BIGINT). */
    public const MISSING_KEY_OFFSET = 4611686018427387904;

    /** change-log field codes */
    public const FIELD_STATUS = 1;

    public const FIELD_CATEGORY = 2;

    public const FIELD_METER_STATUS = 3;

    public const FIELD_ROUTE = 4;

    public const FIELD_DISTRICT = 5;

    public const FIELD_BUCKET = 6;

    /** Not written any more: an account's first appearance is customers.first_seen_batch_id (one whole insert saved per new account). */
    public const FIELD_NEW = 7;

    public const FIELD_MISSING = 8;

    public const FIELD_RETURNED = 9;

    /** Rows of the district's current customers before this file, and how far the count moved. */
    public function prepare(CommercialCustomerBatch $batch): void
    {
        if ($batch->previous_count !== null) {
            return;
        }

        $previous = (int) DB::table('commercial_customers')->where('district_id', $batch->district_id)->whereNull('missing_since_batch_id')->count();
        $previousBatch = CommercialCustomerBatch::query()->live()->where('district_id', $batch->district_id)->where('id', '!=', $batch->id)->orderByDesc('as_of_date')->orderByDesc('id')->value('id');
        $pct = $previous > 0 ? round(($batch->rows_read - $previous) / $previous * 100, 2) : null;

        $warnings = $batch->warnings ?? [];
        $limit = (float) config('gwl.commercial_customer_count_change_warn_pct', 20);

        if ($pct !== null && $limit > 0 && abs($pct) > $limit) {
            $warnings[] = ['row' => 'File', 'message' => 'The customer count moved by '.($pct > 0 ? '+' : '').$pct.'% ('.number_format($previous).' before, '.number_format($batch->rows_read).' now). A partial export would look like this: check the file is complete.'];
        }

        $batch->forceFill(['previous_count' => $previous, 'previous_batch_id' => $previousBatch, 'count_change_pct' => $pct, 'warnings' => $warnings])->save();
    }

    /** Merges every remaining chunk. $tick is called after each (the job uses it to extend its lock and report progress). */
    public function merge(CommercialCustomerBatch $batch, ?callable $tick = null): void
    {
        $size = max(1000, (int) config('gwl.commercial_customer_merge_chunk', 50000));
        $maxId = (int) DB::table('commercial_customer_staging')->where('batch_id', $batch->id)->max('id');

        while ((int) $batch->merge_cursor < $maxId) {
            $from = (int) $batch->merge_cursor;
            $to = DB::table('commercial_customer_staging')->where('batch_id', $batch->id)->where('id', '>', $from)->orderBy('id')->offset($size - 1)->limit(1)->value('id');
            $to = $to === null ? $maxId : (int) $to;

            $this->mergeChunk($batch, $from, $to);
            $batch->refresh();

            if ($tick) {
                $tick($batch);
            }
        }
    }

    protected function mergeChunk(CommercialCustomerBatch $batch, int $from, int $to): void
    {
        $b = (int) $batch->id;
        $r = (int) $batch->region_id;
        $d = (int) $batch->district_id;
        $staged = "s.batch_id = {$b} AND s.id > {$from} AND s.id <= {$to}";
        $diff = "d.batch_id = {$b} AND d.staging_id > {$from} AND d.staging_id <= {$to}";
        $hot = '(d.flags & 1) = 1';
        $anyContact = '(s.account_name IS NOT NULL OR s.address IS NOT NULL OR s.mobiles IS NOT NULL OR s.email IS NOT NULL OR (s.quality_flags & '.CustomerRecordBuilder::INVALID_PHONE.') <> 0)';

        DB::transaction(function () use ($b, $r, $d, $from, $to, $staged, $diff, $hot, $anyContact): void {
            $rows = (int) DB::table('commercial_customer_staging')->where('batch_id', $b)->where('id', '>', $from)->where('id', '<=', $to)->count();

            // 0. ONE pass: which staged rows need any work (a new account, a changed or returning one, a contact to write).
            $needContact = "((ct.customer_id IS NULL AND {$anyContact}) OR (ct.customer_id IS NOT NULL AND ct.contact_hash <> s.contact_hash))";
            $worked = DB::affectingStatement(
                "INSERT INTO commercial_customer_diff (batch_id, staging_id, customer_id, flags) SELECT {$b}, s.id, c.id, "
                ."(CASE WHEN c.id IS NULL THEN 4 ELSE 0 END) + (CASE WHEN c.id IS NOT NULL AND (c.attributes_hash <> s.attributes_hash OR c.missing_since_batch_id IS NOT NULL) THEN 1 ELSE 0 END) + (CASE WHEN {$needContact} THEN 2 ELSE 0 END) "
                .'FROM commercial_customer_staging s LEFT JOIN commercial_customers c ON c.account_no = s.account_no LEFT JOIN commercial_customer_contacts ct ON ct.customer_id = c.id '
                ."WHERE {$staged} AND (c.id IS NULL OR c.attributes_hash <> s.attributes_hash OR c.missing_since_batch_id IS NOT NULL OR {$needContact})"
            );

            $changed = 0;
            $moved = 0;
            $new = 0;

            if ($worked > 0) {
                $undoColumns = implode(', ', [...CustomerSql::HOT, 'updated_batch_id', 'missing_since_batch_id']);
                $join = 'FROM commercial_customer_diff d INNER JOIN commercial_customers c ON c.id = d.customer_id INNER JOIN commercial_customer_staging s ON s.id = d.staging_id';

                // 1. pre-images of the rows about to be rewritten
                $changed = DB::affectingStatement(
                    "INSERT INTO commercial_customer_undo (batch_id, customer_id, {$undoColumns}) SELECT {$b}, c.id, ".CustomerSql::prefixed([...CustomerSql::HOT, 'updated_batch_id', 'missing_since_batch_id'], 'c')." FROM commercial_customer_diff d INNER JOIN commercial_customers c ON c.id = d.customer_id WHERE {$diff} AND {$hot}"
                );

                // 2. change log, only for fields that differ
                if ($changed > 0) {
                    foreach ([
                        [self::FIELD_STATUS, 'status_id', 's.status_id'],
                        [self::FIELD_CATEGORY, 'category_id', 's.category_id'],
                        [self::FIELD_METER_STATUS, 'meter_status_id', 's.meter_status_id'],
                        [self::FIELD_ROUTE, 'route_id', 's.route_id'],
                        [self::FIELD_DISTRICT, 'district_id', (string) $d],
                        [self::FIELD_BUCKET, 'arrears_bucket', 's.arrears_bucket'],
                    ] as [$field, $column, $value]) {
                        $count = DB::affectingStatement(
                            "INSERT INTO commercial_customer_changes (customer_id, batch_id, field, old_value, new_value) SELECT c.id, {$b}, {$field}, c.{$column}, {$value} {$join} WHERE {$diff} AND {$hot} AND c.attributes_hash <> s.attributes_hash AND c.{$column} <> {$value}"
                        );

                        if ($field === self::FIELD_DISTRICT) {
                            $moved = $count;
                        }
                    }

                    DB::affectingStatement(
                        "INSERT INTO commercial_customer_changes (customer_id, batch_id, field, old_value, new_value) SELECT c.id, {$b}, ".self::FIELD_RETURNED.", c.missing_since_batch_id, {$b} {$join} WHERE {$diff} AND {$hot} AND c.missing_since_batch_id IS NOT NULL"
                    );

                    // 3. the rows themselves (an unchanged row is never touched)
                    $set = ['region_id' => (string) $r, 'district_id' => (string) $d];

                    foreach (CustomerSql::STAGED as $column) {
                        $set[$column] = "s.{$column}";
                    }

                    $set['updated_batch_id'] = (string) $b;
                    $set['missing_since_batch_id'] = 'NULL';
                    CustomerSql::updateCustomersFromDiff($set, "{$diff} AND {$hot}");
                }

                // 4. new accounts
                $insertColumns = implode(', ', ['account_no', 'region_id', 'district_id', ...CustomerSql::STAGED, 'first_seen_batch_id', 'updated_batch_id']);
                $selected = implode(', ', ['s.account_no', (string) $r, (string) $d, ...array_map(fn (string $column) => "s.{$column}", CustomerSql::STAGED), (string) $b, (string) $b]);
                $new = DB::affectingStatement(
                    "INSERT INTO commercial_customers ({$insertColumns}) SELECT {$selected} FROM commercial_customer_diff d INNER JOIN commercial_customer_staging s ON s.id = d.staging_id WHERE {$diff} AND (d.flags & 4) = 4"
                );

                // 5. contacts: rewrite the ones that exist, add the ones that do not (new accounts included)
                CustomerSql::updateContactsFromDiff("{$diff} AND (d.flags & 2) = 2", $b);

                $contactColumns = implode(', ', ['customer_id', ...CustomerSql::CONTACT, 'quality_flags', 'updated_batch_id']);
                $contactSelect = implode(', ', ['c.id', ...array_map(fn (string $column) => "s.{$column}", CustomerSql::CONTACT), '(s.quality_flags & '.CustomerRecordBuilder::CONTACT_BITS.')', (string) $b]);
                DB::affectingStatement(
                    "INSERT INTO commercial_customer_contacts ({$contactColumns}) SELECT {$contactSelect} FROM commercial_customer_diff d INNER JOIN commercial_customer_staging s ON s.id = d.staging_id INNER JOIN commercial_customers c ON c.account_no = s.account_no "
                    ."LEFT JOIN commercial_customer_contacts ct ON ct.customer_id = c.id WHERE {$diff} AND (d.flags & 2) = 2 AND ct.customer_id IS NULL AND {$anyContact}"
                );
            }

            DB::table('commercial_customer_batches')->where('id', $b)->update([
                'merge_cursor' => $to,
                'rows_new' => DB::raw("rows_new + {$new}"),
                'rows_changed' => DB::raw("rows_changed + {$changed}"),
                'rows_moved' => DB::raw("rows_moved + {$moved}"),
                'rows_unchanged' => DB::raw('rows_unchanged + '.max(0, $rows - $new - $changed)),
            ]);
        });
    }

    /**
     * Flags the district's accounts that this file no longer lists ("not in latest file"). They stay in the table with
     * missing_since_batch_id set; they come back (and the change log says so) the day a file lists them again.
     */
    public function markMissing(CommercialCustomerBatch $batch): int
    {
        $b = (int) $batch->id;
        $d = (int) $batch->district_id;
        $bounds = DB::table('commercial_customers')->where('district_id', $d)->selectRaw('MIN(id) AS lo, MAX(id) AS hi')->first();

        if (! $bounds || $bounds->lo === null) {
            return 0;
        }

        $absent = "c.district_id = {$d} AND c.missing_since_batch_id IS NULL AND NOT EXISTS (SELECT 1 FROM commercial_customer_staging s WHERE s.batch_id = {$b} AND s.account_no = c.account_no)";
        $undoColumns = implode(', ', [...CustomerSql::HOT, 'updated_batch_id', 'missing_since_batch_id']);
        $step = 200000;

        for ($from = (int) $bounds->lo - 1; $from < (int) $bounds->hi; $from += $step) {
            $to = $from + $step;

            DB::transaction(function () use ($b, $absent, $undoColumns, $from, $to): void {
                // ONE anti-join pass finds the accounts the file no longer lists (flag 8, keyed by MISSING_KEY_OFFSET + the customer id:
                // staging_id is unsigned, so it cannot be negative, and it must not clash with a real staged row); the pre-images, the change-log rows and the update then read only those.
                $found = DB::affectingStatement(
                    "INSERT INTO commercial_customer_diff (batch_id, staging_id, customer_id, flags) SELECT {$b}, ".self::MISSING_KEY_OFFSET." + c.id, c.id, 8 FROM commercial_customers c WHERE c.id > {$from} AND c.id <= {$to} AND {$absent}"
                );

                if ($found === 0) {
                    return;
                }

                $rows = "FROM commercial_customer_diff d INNER JOIN commercial_customers c ON c.id = d.customer_id WHERE d.batch_id = {$b} AND d.flags = 8 AND d.customer_id > {$from} AND d.customer_id <= {$to}";

                DB::affectingStatement("INSERT INTO commercial_customer_undo (batch_id, customer_id, {$undoColumns}) SELECT {$b}, c.id, ".CustomerSql::prefixed([...CustomerSql::HOT, 'updated_batch_id', 'missing_since_batch_id'], 'c')." {$rows}");
                DB::affectingStatement('INSERT INTO commercial_customer_changes (customer_id, batch_id, field, old_value, new_value) SELECT c.id, '.$b.', '.self::FIELD_MISSING.", NULL, {$b} {$rows}");
                DB::affectingStatement("UPDATE commercial_customers AS c SET missing_since_batch_id = {$b}, updated_batch_id = {$b} WHERE c.id IN (SELECT d.customer_id FROM commercial_customer_diff d WHERE d.batch_id = {$b} AND d.flags = 8 AND d.customer_id > {$from} AND d.customer_id <= {$to})");
            });
        }

        $missing = (int) DB::table('commercial_customers')->where('district_id', $d)->where('missing_since_batch_id', $b)->count();
        $batch->forceFill(['rows_missing' => $missing])->save();

        return $missing;
    }

    /**
     * Removes the batch's staging rows (personal data). When no other batch has rows there the whole table is emptied with
     * TRUNCATE, which is instant; otherwise the rows go in id ranges, so no single statement is huge.
     */
    public static function clearStaging(int $batchId): void
    {
        DB::table('commercial_customer_diff')->where('batch_id', $batchId)->delete();

        if (! DB::table('commercial_customer_staging')->where('batch_id', '!=', $batchId)->exists()) {
            DB::table('commercial_customer_staging')->truncate();

            return;
        }

        $bounds = DB::table('commercial_customer_staging')->where('batch_id', $batchId)->selectRaw('MIN(id) AS lo, MAX(id) AS hi')->first();

        if (! $bounds || $bounds->lo === null) {
            return;
        }

        for ($from = (int) $bounds->lo - 1; $from < (int) $bounds->hi; $from += 100000) {
            DB::table('commercial_customer_staging')->where('batch_id', $batchId)->where('id', '>', $from)->where('id', '<=', $from + 100000)->delete();
        }
    }
}
