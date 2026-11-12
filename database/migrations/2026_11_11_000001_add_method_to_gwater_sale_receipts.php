<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How money received on a credit sale was paid: cash or MoMo (mobile money). Every earlier payment is cash. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gw_sale_receipts') && ! Schema::hasColumn('gw_sale_receipts', 'method')) {
            Schema::table('gw_sale_receipts', function (Blueprint $table) {
                $table->string('method', 8)->default('cash')->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gw_sale_receipts') && Schema::hasColumn('gw_sale_receipts', 'method')) {
            Schema::table('gw_sale_receipts', function (Blueprint $table) {
                $table->dropColumn('method');
            });
        }
    }
};
