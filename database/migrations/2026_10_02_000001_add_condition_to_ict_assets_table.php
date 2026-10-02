<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ict_assets') && ! Schema::hasColumn('ict_assets', 'condition')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->string('condition')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ict_assets') && Schema::hasColumn('ict_assets', 'condition')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->dropColumn('condition');
            });
        }
    }
};
