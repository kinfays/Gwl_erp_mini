<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a device sits in a rack: the rack, its starting U position (1 = the bottom) and its height in U. Only the
 * types in IctAsset::RACK_ASSET_TYPES carry them; AssetRecordService clears them for every other device. The foreign
 * key is nullOnDelete as a safety net only: the app refuses to delete a rack that still has devices in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_assets') || ! Schema::hasTable('ict_racks')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('ict_assets', 'rack_id')) {
                $table->foreignId('rack_id')->nullable()->constrained('ict_racks')->nullOnDelete();
            }

            if (! Schema::hasColumn('ict_assets', 'rack_unit')) {
                $table->unsignedTinyInteger('rack_unit')->nullable();
            }

            if (! Schema::hasColumn('ict_assets', 'rack_unit_span')) {
                $table->unsignedTinyInteger('rack_unit_span')->default(1);
            }
        });

        // The occupancy check reads every device of one rack in unit order.
        if (! Schema::hasIndex('ict_assets', 'ict_assets_rack_position_index')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->index(['rack_id', 'rack_unit'], 'ict_assets_rack_position_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ict_assets')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            if (Schema::hasColumn('ict_assets', 'rack_id')) {
                $table->dropForeign(['rack_id']);
            }
        });

        if (Schema::hasIndex('ict_assets', 'ict_assets_rack_position_index')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->dropIndex('ict_assets_rack_position_index');
            });
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            foreach (['rack_id', 'rack_unit', 'rack_unit_span'] as $column) {
                if (Schema::hasColumn('ict_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
