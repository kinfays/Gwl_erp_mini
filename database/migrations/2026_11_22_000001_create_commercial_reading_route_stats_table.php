<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8c: the reading report BY ROUTE ("Customer Meter Reading Report - CCA Summary - By Route", rptReadingSumm; report type
 * reading_route_summary in commercial_import_batches). One row per CCA per route per upload, tied to its batch, immutable.
 *
 *   * route_id is the CANONICAL route of the route master (RouteResolver, source "reading"); it is NULL only for a CCA with no route
 *     rows at all (a finding, kept) and for a route whose district nobody could place yet (route_label_raw says what was printed; the
 *     batch page resolves it).
 *   * verified_strength is PER ROUTE. A route can appear under two CCAs in one period (one row with the strength and no visits, one with
 *     the visits), so the strength counts once: the OWNER row (the CCA with the most visits; is_route_owner). The other rows are kept.
 *   * the source's percentages and its Unvisited column are NOT stored: they are measured against the whole region. Unvisited is
 *     strength - visited, recomputed.
 *   * a row whose own relations fail (read + skipped = visited, strength - visited = the source's unvisited) is flagged and kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_reading_route_stats')) {
            return;
        }

        Schema::create('commercial_reading_route_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
            // commercial_routes.id is an INT UNSIGNED (Phase 1), so this column must be too: a BIGINT column cannot hold a foreign key to it on MySQL
            $table->unsignedInteger('route_id')->nullable();
            $table->foreign('route_id')->references('id')->on('commercial_routes')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('cca_staff_id', 50);
            $table->string('cca_name_raw', 191)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('route_label_raw', 120)->nullable();       // as printed ("AMASAMAN 4601"); null for a CCA with no routes
            $table->string('district_label_raw', 120)->nullable();
            $table->unsignedInteger('verified_strength')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('visited_count')->default(0);
            $table->boolean('is_route_owner')->default(false);
            $table->boolean('is_flagged')->default(false);            // the row's own relations failed
            $table->string('flag_reason', 60)->nullable();
            $table->string('match_status', 20)->default('unmatched');  // matched | unmatched (the staff directory)
            $table->timestamps();

            $table->unique(['batch_id', 'route_id', 'cca_staff_id'], 'comm_route_stats_batch_route_cca_unique');
            $table->index(['batch_id', 'district_id'], 'comm_route_stats_batch_district_idx');
            $table->index(['cca_staff_id', 'batch_id'], 'comm_route_stats_cca_batch_idx');
            $table->index(['route_id', 'batch_id'], 'comm_route_stats_route_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_reading_route_stats');
    }
};
