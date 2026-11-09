<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The asset's physical tag number (written GWCL/FOE/YEAR/NUMBER). Optional free text: assets that exist today have none,
 * and a blank is stored as NULL. Indexed because the Assets list searches on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_assets') || Schema::hasColumn('ict_assets', 'tag_no')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            $table->string('tag_no', 100)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ict_assets') && Schema::hasColumn('ict_assets', 'tag_no')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->dropIndex(['tag_no']);
                $table->dropColumn('tag_no');
            });
        }
    }
};
