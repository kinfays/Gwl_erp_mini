<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8a, the route master (design 8.30). `commercial_routes` was a list of (district, name) pairs made by the customer list; it
 * becomes the ONE place every report resolves a route to:
 *
 *   region_id           the region the route is in (nullable until known)
 *   code                the route code as printed ("0000" kept; text, never a number)
 *   canonical_route_id  null = this IS the route; otherwise this row is an alias of the canonical one (the same code printed under
 *                       another district by another system)
 *   source              which report first showed it: reading | billing | customer_list | manual
 *   active              false hides a route that no longer exists
 *   is_pending          created by an import from a label nobody has confirmed yet
 *   pinned              a Commercial Manager decided this route by hand (pinned to its district, or marked as an alias): the
 *                       authority setting never overrides it. `source` stays the system the route was first seen in
 *
 * commercial_customers.ops_route_id (the canonical route the analytics and the district scope will use) is added empty: later
 * phases fill it, nothing reads it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_routes')) {
            Schema::table('commercial_routes', function (Blueprint $table): void {
                if (! Schema::hasColumn('commercial_routes', 'region_id')) {
                    $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnUpdate()->nullOnDelete();
                }

                if (! Schema::hasColumn('commercial_routes', 'code')) {
                    $table->string('code', 12)->nullable()->index();
                }

                if (! Schema::hasColumn('commercial_routes', 'canonical_route_id')) {
                    $table->unsignedInteger('canonical_route_id')->nullable()->index();
                }

                if (! Schema::hasColumn('commercial_routes', 'source')) {
                    $table->string('source', 20)->default('customer_list');
                }

                if (! Schema::hasColumn('commercial_routes', 'active')) {
                    $table->boolean('active')->default(true);
                }

                if (! Schema::hasColumn('commercial_routes', 'is_pending')) {
                    $table->boolean('is_pending')->default(false);
                }

                if (! Schema::hasColumn('commercial_routes', 'pinned')) {
                    $table->boolean('pinned')->default(false);
                }
            });

            // Back-fill: the region of the district, and the code where the name is (or ends in) the route code.
            $regions = DB::table('districts')->pluck('region_id', 'id');

            foreach (DB::table('commercial_routes')->whereNull('code')->get(['id', 'district_id', 'name', 'region_id']) as $route) {
                $update = [];
                $name = trim((string) $route->name);

                if (preg_match('/^(\d{1,12})$/', $name, $m) || preg_match('/\s(\d{1,12})$/', $name, $m) || preg_match('/^(\d{1,12})\s*,/', $name, $m)) {
                    $update['code'] = $m[1];
                }

                if ($route->region_id === null && isset($regions[$route->district_id])) {
                    $update['region_id'] = $regions[$route->district_id];
                }

                if ($update !== []) {
                    DB::table('commercial_routes')->where('id', $route->id)->update($update);
                }
            }
        }

        if (Schema::hasTable('commercial_customers') && ! Schema::hasColumn('commercial_customers', 'ops_route_id')) {
            Schema::table('commercial_customers', function (Blueprint $table): void {
                $table->unsignedInteger('ops_route_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commercial_customers', 'ops_route_id')) {
            Schema::table('commercial_customers', fn (Blueprint $table) => $table->dropColumn('ops_route_id'));
        }

        if (Schema::hasTable('commercial_routes')) {
            Schema::table('commercial_routes', function (Blueprint $table): void {
                foreach (['region_id'] as $fk) {
                    if (Schema::hasColumn('commercial_routes', $fk)) {
                        $table->dropConstrainedForeignId($fk);
                    }
                }

                $drop = array_values(array_filter(['code', 'canonical_route_id', 'source', 'active', 'is_pending', 'pinned'], fn (string $c) => Schema::hasColumn('commercial_routes', $c)));
                $drop === [] || $table->dropColumn($drop);
            });
        }
    }
};
