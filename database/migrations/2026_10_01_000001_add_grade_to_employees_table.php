<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff grade (App\Enums\StaffGrade). The grade fixes the category, so category stays the one stored value for it:
 * Employee::saving derives it from the grade whenever there is one.
 *
 * `category` was a database enum (Senior Staff, Junior Staff, Management, Senior Management, Charwoman). It becomes a plain
 * string, because the allowed values now live in PHP with the grades and a new one ("Contract") would otherwise
 * need a table rebuild each time. The old "Charwoman" category is renamed "Contract". "Senior Management" stays valid
 * for staff who have no grade yet.
 *
 * `grade` is nullable: staff recorded before grades existed keep their current leave entitlement until one is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        if (! Schema::hasColumn('employees', 'grade')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('grade', 30)->nullable()->after('category')->index();
            });
        }

        if (Schema::hasColumn('employees', 'category')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('category', 30)->change();
            });

            DB::table('employees')->where('category', 'Charwoman')->update(['category' => 'Contract']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        if (Schema::hasColumn('employees', 'grade')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex(['grade']);
            });

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('grade');
            });
        }

        // category stays a string and "Contract" stays "Contract": the enum is not put back.
    }
};
