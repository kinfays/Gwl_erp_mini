<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fully anonymous report (design section 10, question 1): nothing in the database links it to the person who filed it.
 * The flag tells such a report apart from one recorded for someone with no login (reporter_name_raw) and from older rows
 * with no reporter, so the screens can say "Anonymous" and the workflow knows there is nobody to give feedback to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hs_incidents') && ! Schema::hasColumn('hs_incidents', 'is_anonymous')) {
            Schema::table('hs_incidents', function (Blueprint $table) {
                $table->boolean('is_anonymous')->default(false)->after('is_urgent');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hs_incidents') && Schema::hasColumn('hs_incidents', 'is_anonymous')) {
            Schema::table('hs_incidents', function (Blueprint $table) {
                $table->dropColumn('is_anonymous');
            });
        }
    }
};
