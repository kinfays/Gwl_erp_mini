<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "General use": an asset shared by several people (a printer, a shared PC) that is deliberately not assigned to anyone.
 * Same idea and naming as vehicles.is_pool_car. Existing assets stay false, so nothing changes for them. A general-use
 * asset never counts as "unassigned" on the dashboard (IctAsset::scopeNeedsAssignee).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_assets') || Schema::hasColumn('ict_assets', 'is_general_use')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            $table->boolean('is_general_use')->default(false)->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ict_assets') && Schema::hasColumn('ict_assets', 'is_general_use')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->dropIndex(['is_general_use']);
                $table->dropColumn('is_general_use');
            });
        }
    }
};
