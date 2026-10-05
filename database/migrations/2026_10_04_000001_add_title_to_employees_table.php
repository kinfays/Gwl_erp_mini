<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The honorific on an employee (Mr., Mrs., Ms., Miss, Dr., Prof., Ing., ...): printed in front of their name on leave
 * approval letters and used to choose "Dear Sir" or "Dear Madam". Nullable: staff recorded before it keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'title')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('title', 20)->nullable()->after('full_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'title')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }
    }
};
