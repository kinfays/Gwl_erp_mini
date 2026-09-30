<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-region "HR Email" is gone from Staff → Regions and the region import; HR emails for leave are set
 * on the Leave module's HR Contacts screen (`leave_hr_contacts`). Nothing reads this column any more.
 *
 * Dropping it discards whatever addresses were stored: down() restores the column, not the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('regions') && Schema::hasColumn('regions', 'hr_email')) {
            Schema::table('regions', function (Blueprint $table) {
                $table->dropColumn('hr_email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('regions') && ! Schema::hasColumn('regions', 'hr_email')) {
            Schema::table('regions', function (Blueprint $table) {
                $table->string('hr_email')->nullable()->after('region_name');
            });
        }
    }
};
