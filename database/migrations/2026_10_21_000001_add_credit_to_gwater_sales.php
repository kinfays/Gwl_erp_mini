<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-Water credit sales (docs/17-module-g-water.md section 16): a sale may be on credit, for a customer who pays later.
 *
 *  - gw_sales.payment_type          'cash' (the default, and every sale so far) or 'credit';
 *  - gw_sales.customer_name/phone   who owes it; set on credit sales only;
 *  - gw_sale_receipts               the money received against a credit sale, as many as it takes to clear it. Immutable like
 *                                   the rest of the books: a wrong one is voided with a reason.
 * Revenue is the sale's amount whether it was paid at once or not; what is still owed is the amount less the receipts not voided.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gw_sales')) {
            Schema::table('gw_sales', function (Blueprint $table) {
                if (! Schema::hasColumn('gw_sales', 'payment_type')) {
                    $table->string('payment_type', 8)->default('cash')->after('amount')->index();
                }

                if (! Schema::hasColumn('gw_sales', 'customer_name')) {
                    $table->string('customer_name', 120)->nullable()->after('payment_type');
                }

                if (! Schema::hasColumn('gw_sales', 'customer_phone')) {
                    $table->string('customer_phone', 30)->nullable()->after('customer_name');
                }
            });
        }

        if (! Schema::hasTable('gw_sale_receipts')) {
            Schema::create('gw_sale_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->foreignId('sale_id')->constrained('gw_sales')->restrictOnDelete();
                $table->date('received_on')->index();
                $table->unsignedBigInteger('amount');
                $table->string('reference', 60)->nullable();
                $table->string('note', 255)->nullable();
                $table->string('status', 12)->default('posted')->index();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('void_reason', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'received_on'], 'gw_sale_receipts_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gw_sale_receipts');

        if (Schema::hasTable('gw_sales')) {
            Schema::table('gw_sales', function (Blueprint $table) {
                foreach (['customer_phone', 'customer_name', 'payment_type'] as $column) {
                    if (Schema::hasColumn('gw_sales', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
