<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A trip no longer carries a supplier reference: the waybill number is the reference. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gw_purchases') && Schema::hasColumn('gw_purchases', 'supplier_ref')) {
            Schema::table('gw_purchases', function (Blueprint $table) {
                $table->dropColumn('supplier_ref');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gw_purchases') && ! Schema::hasColumn('gw_purchases', 'supplier_ref')) {
            Schema::table('gw_purchases', function (Blueprint $table) {
                $table->string('supplier_ref', 60)->nullable();
            });
        }
    }
};
