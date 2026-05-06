<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compulsory_leave_deductions', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('year');
            $table->date('end_date')->nullable()->after('start_date');
            $table->index(['start_date', 'end_date'], 'cld_date_range_idx');
        });
    }

    public function down(): void
    {
        Schema::table('compulsory_leave_deductions', function (Blueprint $table) {
            $table->dropIndex('cld_date_range_idx');
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
