<?php

namespace App\Services\Commercial\Customers;

use Illuminate\Support\Facades\DB;

/**
 * The few places the customer list needs SQL that differs between database drivers. Everything else is portable
 * (INSERT ... SELECT, GROUP BY, CASE, upsert-free merges), which is why the merge is written as separate UPDATE and
 * INSERT statements instead of MySQL's ON DUPLICATE KEY or SQLite's ON CONFLICT.
 *
 *   * a multi-table UPDATE:  MySQL / MariaDB  UPDATE t AS c INNER JOIN s ON .. SET c.x = s.x
 *                            SQLite / Postgres UPDATE t AS c SET x = s.x FROM s WHERE ..
 *   * month truncation:      DATE_FORMAT vs strftime
 *
 * Production is expected to be MySQL; tests and local development run SQLite, so both branches are exercised by the suite.
 */
final class CustomerSql
{
    /** The columns that make up a customer's hot state, besides ids. */
    public const HOT = [
        'region_id', 'district_id', 'route_id', 'category_id', 'status_id', 'meter_status_id', 'meter_no', 'connect_date', 'balance',
        'last_read_date', 'last_reading', 'last_bill_date', 'last_bill_amount', 'last_paid_date', 'last_paid_amount',
        'estimated_consume', 'average_consume', 'meter_factor', 'arrears_bucket', 'attributes_hash',
    ];

    /** HOT without the two columns that are constant for a batch (they are not stored in staging). */
    public const STAGED = [
        'route_id', 'category_id', 'status_id', 'meter_status_id', 'meter_no', 'connect_date', 'balance',
        'last_read_date', 'last_reading', 'last_bill_date', 'last_bill_amount', 'last_paid_date', 'last_paid_amount',
        'estimated_consume', 'average_consume', 'meter_factor', 'arrears_bucket', 'attributes_hash',
    ];

    public const CONTACT = ['account_name', 'address', 'mobiles', 'phone_primary', 'email', 'email_lower', 'name_search', 'contact_hash'];

    public static function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * UPDATE customers c from the diff rows d and their staging rows s: $set maps a customers column to an SQL expression
     * (written with the aliases c / d / s), $where is the condition on all three.
     *
     * @param  array<string, string>  $set
     */
    public static function updateCustomersFromDiff(array $set, string $where): int
    {
        if (self::isMysql()) {
            $assignments = implode(', ', array_map(fn (string $column, string $expression) => "c.{$column} = {$expression}", array_keys($set), $set));

            return DB::affectingStatement("UPDATE commercial_customers AS c INNER JOIN commercial_customer_diff AS d ON d.customer_id = c.id INNER JOIN commercial_customer_staging AS s ON s.id = d.staging_id SET {$assignments} WHERE {$where}");
        }

        $assignments = implode(', ', array_map(fn (string $column, string $expression) => "{$column} = {$expression}", array_keys($set), $set));

        return DB::affectingStatement("UPDATE commercial_customers AS c SET {$assignments} FROM commercial_customer_diff AS d, commercial_customer_staging AS s WHERE d.customer_id = c.id AND s.id = d.staging_id AND ({$where})");
    }

    /** UPDATE the existing contact rows named by the diff from their staging rows. */
    public static function updateContactsFromDiff(string $where, int $batchId): int
    {
        $columns = self::CONTACT;
        $bits = CustomerRecordBuilder::CONTACT_BITS;

        if (self::isMysql()) {
            $assignments = implode(', ', array_map(fn (string $column) => "ct.{$column} = s.{$column}", $columns)).", ct.quality_flags = (s.quality_flags & {$bits}), ct.updated_batch_id = {$batchId}";

            return DB::affectingStatement("UPDATE commercial_customer_contacts AS ct INNER JOIN commercial_customer_diff AS d ON d.customer_id = ct.customer_id INNER JOIN commercial_customer_staging AS s ON s.id = d.staging_id SET {$assignments} WHERE {$where}");
        }

        $assignments = implode(', ', array_map(fn (string $column) => "{$column} = s.{$column}", $columns)).", quality_flags = (s.quality_flags & {$bits}), updated_batch_id = {$batchId}";

        return DB::affectingStatement("UPDATE commercial_customer_contacts AS ct SET {$assignments} FROM commercial_customer_diff AS d, commercial_customer_staging AS s WHERE d.customer_id = ct.customer_id AND s.id = d.staging_id AND ({$where})");
    }

    /** UPDATE customers c from the undo rows of a batch (the rollback of its changes). */
    public static function restoreFromUndo(int $batchId, int $fromCustomerId, int $toCustomerId): int
    {
        $columns = [...self::HOT, 'updated_batch_id', 'missing_since_batch_id'];
        $range = "u.batch_id = {$batchId} AND u.customer_id > {$fromCustomerId} AND u.customer_id <= {$toCustomerId}";

        if (self::isMysql()) {
            $assignments = implode(', ', array_map(fn (string $column) => "c.{$column} = u.{$column}", $columns));

            return DB::affectingStatement("UPDATE commercial_customers AS c INNER JOIN commercial_customer_undo AS u ON u.customer_id = c.id SET {$assignments} WHERE {$range}");
        }

        $assignments = implode(', ', array_map(fn (string $column) => "{$column} = u.{$column}", $columns));

        return DB::affectingStatement("UPDATE commercial_customers AS c SET {$assignments} FROM commercial_customer_undo AS u WHERE u.customer_id = c.id AND {$range}");
    }

    /** First day of the month of a date column, as 'YYYY-MM-01'. */
    public static function monthStart(string $column): string
    {
        return self::isMysql() ? "DATE_FORMAT({$column}, '%Y-%m-01')" : "strftime('%Y-%m-01', {$column})";
    }

    /** @param  list<string>  $columns */
    public static function prefixed(array $columns, string $alias): string
    {
        return implode(', ', array_map(fn (string $column) => "{$alias}.{$column}", $columns));
    }
}
