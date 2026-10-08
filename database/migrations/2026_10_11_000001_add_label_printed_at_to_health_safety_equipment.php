<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3b: when a QR label was last PRINTED for an item (not when it was stuck on). Lets the officer filter "no label
 * yet" while the stickers are rolled out.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['hs_fire_extinguishers', 'hs_first_aid_kits'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'label_printed_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->timestamp('label_printed_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['hs_fire_extinguishers', 'hs_first_aid_kits'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'label_printed_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('label_printed_at');
                });
            }
        }
    }
};
