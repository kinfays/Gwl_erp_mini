<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS terminals are general use. Existing POS terminals that nobody holds are marked general use so they read as shared
 * (a POS that has a holder is left exactly as it is; nothing is unassigned or reassigned here). Needs the
 * is_general_use column from 2026_11_06_000001. Not reversed: after this it is impossible to tell which rows were changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_assets') || ! Schema::hasColumn('ict_assets', 'is_general_use')) {
            return;
        }

        DB::table('ict_assets')
            ->where('asset_type', 'POS')
            ->whereNull('assigned_to_employee_id')
            ->where(fn ($query) => $query->where('is_general_use', false)->orWhereNull('is_general_use'))
            ->update(['is_general_use' => true]);
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
