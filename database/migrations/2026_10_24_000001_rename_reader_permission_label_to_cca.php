<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people who read meters are called CCAs (customer care assistants) in the business. Only the label shown on the roles
 * screen changes; the permission's name (commercial.view_reader_performance) is what roles and code refer to and stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name', 'commercial.view_reader_performance')->update([
            'display_name' => 'View CCA Performance',
            'description' => 'View CCA Performance permission for COMMERCIAL module',
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name', 'commercial.view_reader_performance')->update([
            'display_name' => 'View Reader Performance',
            'description' => 'View Reader Performance permission for COMMERCIAL module',
        ]);
    }
};
