<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A maintenance record gets a real "reported" date (when the fault was raised), so the completion date can be checked
 * against it. Existing rows are backfilled from the day they were created, or from the completion date when that is
 * earlier (a record back-dated at entry), so no stored record becomes invalid. The old `location` column stays as it is
 * (it is nullable already and no longer written); the screens now show the linked asset's location.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_asset_maintenances') || Schema::hasColumn('ict_asset_maintenances', 'reported_date')) {
            return;
        }

        Schema::table('ict_asset_maintenances', function (Blueprint $table) {
            $table->date('reported_date')->nullable()->after('status')->index();
        });

        DB::table('ict_asset_maintenances')->update([
            'reported_date' => DB::raw('CASE WHEN completion_date IS NOT NULL AND completion_date < DATE(created_at) THEN completion_date ELSE DATE(created_at) END'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('ict_asset_maintenances') && Schema::hasColumn('ict_asset_maintenances', 'reported_date')) {
            Schema::table('ict_asset_maintenances', function (Blueprint $table) {
                $table->dropIndex(['reported_date']);
                $table->dropColumn('reported_date');
            });
        }
    }
};
