<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a PC / AIO / server / printer / photocopier sits on a UPS, and if so whether that UPS still works. Plain
 * columns on the asset (no UPS inventory record): the form only offers them for those five types.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_assets')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('ict_assets', 'has_ups')) {
                $table->boolean('has_ups')->nullable()->default(false)->after('status_reason');
            }

            if (! Schema::hasColumn('ict_assets', 'ups_status')) {
                $table->string('ups_status')->nullable()->after('has_ups');
            }

            if (! Schema::hasColumn('ict_assets', 'ups_notes')) {
                $table->text('ups_notes')->nullable()->after('ups_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ict_assets')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            foreach (['ups_notes', 'ups_status', 'has_ups'] as $column) {
                if (Schema::hasColumn('ict_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
