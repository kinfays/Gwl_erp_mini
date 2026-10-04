<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ict_assets') && ! Schema::hasColumn('ict_assets', 'status_reason')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->text('status_reason')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ict_assets') && Schema::hasColumn('ict_assets', 'status_reason')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->dropColumn('status_reason');
            });
        }
    }
};
