<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `commercial_routes.id` is an INT UNSIGNED (Phase 1, `unsignedInteger('id')->autoIncrement()`), so a column holding a foreign key to it must be
 * an INT UNSIGNED too. Laravel's foreignId() is a BIGINT UNSIGNED, and MySQL refuses that pair (error 3780: "Referencing column and referenced
 * column are incompatible"). SQLite does not check column types, so a migration written that way passes every test here and fails on the real
 * server: that is exactly how the Phase 8c and 8d report tables first reached MySQL. These tests are the guard SQLite cannot give.
 */
class RouteForeignKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_migration_declares_a_bigint_foreign_key_to_commercial_routes(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            // foreignId('route_id') ... constrained('commercial_routes')   and   unsignedBigInteger('x') ... references('id')->on('commercial_routes')
            if (preg_match('/foreignId\([^)]*\)[^;]*constrained\(\s*[\'"]commercial_routes[\'"]/s', $source)
                || preg_match('/(unsignedBigInteger|bigInteger)\([^)]*\)[^;]*;\s*\$table->foreign\([^)]*\)->references\(\s*[\'"]id[\'"]\s*\)->on\(\s*[\'"]commercial_routes[\'"]/s', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'a BIGINT column cannot hold a foreign key to commercial_routes.id (INT UNSIGNED) on MySQL: use unsignedInteger() and foreign()');
    }

    public function test_the_route_report_tables_have_a_route_column_and_a_working_foreign_key(): void
    {
        foreach (['commercial_reading_route_stats', 'commercial_billing_detail_rollups', 'commercial_billing_detail_issues'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'route_id'), $table);
        }

        // on SQLite the foreign keys are enforced: a row pointing at a route that does not exist is refused
        $region = DB::table('regions')->insertGetId(['region_name' => 'Fk Region', 'created_at' => now(), 'updated_at' => now()]);
        $district = DB::table('districts')->insertGetId(['region_id' => $region, 'district_name' => 'Fk District', 'created_at' => now(), 'updated_at' => now()]);
        $batch = DB::table('commercial_import_batches')->insertGetId([
            'report_type' => 'billing_details', 'region_id' => $region, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'source_filename' => 'x.xlsx', 'file_hash' => str_repeat('a', 64),
            'status' => 'imported', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $route = DB::table('commercial_routes')->insertGetId(['district_id' => $district, 'region_id' => $region, 'name' => '4101', 'code' => '4101', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('commercial_billing_detail_issues')->insert(['batch_id' => $batch, 'kind' => 'top_debtor', 'account_no' => '100000000001', 'route_id' => $route, 'district_id' => $district, 'amount' => 1]);
        $this->assertSame(1, DB::table('commercial_billing_detail_issues')->count());

        // deleting the route sets the column to null (ON DELETE SET NULL), it does not delete the row
        DB::table('commercial_routes')->where('id', $route)->delete();
        $this->assertNull(DB::table('commercial_billing_detail_issues')->value('route_id'));
        $this->assertSame(1, DB::table('commercial_billing_detail_issues')->count());
    }

    public function test_the_repair_migration_does_nothing_on_a_database_that_is_already_right_and_can_run_again(): void
    {
        $migration = require base_path('database/migrations/2026_11_23_000004_repair_route_foreign_keys_of_the_route_report_tables.php');
        $before = array_map(fn ($table) => Schema::getColumns($table), ['commercial_reading_route_stats', 'commercial_billing_detail_rollups', 'commercial_billing_detail_issues', 'commercial_billing_detail_bands']);

        $migration->up();
        $migration->up();
        $migration->down();

        $after = array_map(fn ($table) => Schema::getColumns($table), ['commercial_reading_route_stats', 'commercial_billing_detail_rollups', 'commercial_billing_detail_issues', 'commercial_billing_detail_bands']);
        $this->assertEquals($before, $after, 'nothing changed: SQLite has no column-type rule to repair, and a correct MySQL table is left alone');
    }
}
