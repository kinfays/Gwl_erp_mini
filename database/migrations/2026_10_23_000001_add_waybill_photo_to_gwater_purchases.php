<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The picture of the paper waybill, attached when a trip is recorded. The file lives on the private disk (never given a URL)
 * and is served only by the gwater.purchases.waybill route after the same location check as the trip itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gw_purchases')) {
            return;
        }

        Schema::table('gw_purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('gw_purchases', 'waybill_photo_path')) {
                $table->string('waybill_photo_path', 255)->nullable();
            }

            if (! Schema::hasColumn('gw_purchases', 'waybill_photo_mime')) {
                $table->string('waybill_photo_mime', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('gw_purchases')) {
            Schema::table('gw_purchases', function (Blueprint $table) {
                foreach (['waybill_photo_path', 'waybill_photo_mime'] as $column) {
                    if (Schema::hasColumn('gw_purchases', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
