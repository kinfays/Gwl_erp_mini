<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `admin` role is shown as "Global Admin". Only the display name and description change: the slug stays
 * `admin`, so every role check, route middleware and permission grant that names it keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'display_name')) {
            return;
        }

        DB::table('roles')->where('name', 'admin')->update([
            'display_name' => 'Global Admin',
            'description' => 'Global Admin system role',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'display_name')) {
            return;
        }

        DB::table('roles')->where('name', 'admin')->update([
            'display_name' => 'Admin',
            'description' => 'Admin system role',
            'updated_at' => now(),
        ]);
    }
};
