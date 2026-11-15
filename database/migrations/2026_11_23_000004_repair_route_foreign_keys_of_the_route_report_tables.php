<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs a MySQL / MariaDB database in which the Phase 8c and 8d report tables were created WITHOUT their route foreign keys.
 *
 * What went wrong: `commercial_routes.id` is an INT UNSIGNED (Phase 1), but 2026_11_22_000001 (reading by route) and 2026_11_23_000003
 * (detailed billing) declared `route_id` with foreignId(), a BIGINT UNSIGNED, and MySQL refuses a foreign key between the two (error 3780).
 * SQLite does not check, so the tests could not see it. Both migrations are now written correctly (an INT UNSIGNED column), but every
 * migration here skips a table that already exists, so a database that ran the old versions and was migrated again was left with the
 * tables, a BIGINT `route_id` and none of the foreign keys that came after it in the same table (route, district, employee).
 *
 * This migration looks at what is actually there and fixes only what is wrong: it makes `route_id` the same type as `commercial_routes.id`
 * and adds each missing foreign key (ON DELETE SET NULL, the same as the originals). A table that is already right is left alone, so it is
 * safe to run again and does nothing on a fresh install or on SQLite (which has no type rule to repair). It deletes nothing and changes no
 * row; a foreign key that cannot be added because a row points at a route that does not exist fails loudly rather than silently nulling it.
 */
return new class extends Migration
{
    /** table => [column => referenced table] */
    private const KEYS = [
        'commercial_reading_route_stats' => ['route_id' => 'commercial_routes', 'district_id' => 'districts', 'employee_id' => 'employees'],
        'commercial_billing_detail_rollups' => ['route_id' => 'commercial_routes', 'district_id' => 'districts'],
        'commercial_billing_detail_issues' => ['route_id' => 'commercial_routes', 'district_id' => 'districts'],
        'commercial_billing_detail_bands' => ['district_id' => 'districts'],
    ];

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::KEYS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $referenced) {
                if (! Schema::hasColumn($table, $column) || ! Schema::hasTable($referenced)) {
                    continue;
                }

                $this->matchTheReferencedType($table, $column, $referenced);

                if (! $this->hasForeignKey($table, $column, $referenced)) {
                    DB::statement(sprintf(
                        'ALTER TABLE `%1$s` ADD CONSTRAINT `%1$s_%2$s_foreign` FOREIGN KEY (`%2$s`) REFERENCES `%3$s` (`id`) ON DELETE SET NULL',
                        $table, $column, $referenced
                    ));
                }
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the repair only brings a table to the shape its migration describes.
    }

    /** Makes the column the same type as the column it references (nullable, as all of these are). */
    private function matchTheReferencedType(string $table, string $column, string $referenced): void
    {
        $types = DB::select(
            'SELECT table_name AS t, column_type AS ct FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = ? AND column_name = ?) OR (table_name = ? AND column_name = ?))',
            [$table, $column, $referenced, 'id']
        );

        $own = null;
        $parent = null;

        foreach ($types as $row) {
            $row = (array) array_change_key_case((array) $row, CASE_LOWER);

            if ($row['t'] === $table) {
                $own = $row['ct'];
            } elseif ($row['t'] === $referenced) {
                $parent = $row['ct'];
            }
        }

        if ($own !== null && $parent !== null && strtolower((string) $own) !== strtolower((string) $parent)) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s NULL', $table, $column, $parent));
        }
    }

    private function hasForeignKey(string $table, string $column, string $referenced): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('referenced_table_name', $referenced)
            ->exists();
    }
};
