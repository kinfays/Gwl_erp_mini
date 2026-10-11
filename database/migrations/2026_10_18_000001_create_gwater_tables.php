<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-Water (docs/17-module-g-water.md), Phase 1 tables plus the Phase 2 ones (damages, expenses, supplier payments), whose
 * screens come later. Money is integer pesewas, quantities integer packs, nothing is hard-deleted (a wrong entry is voided
 * with a reason), and every books row carries the location it belongs to: `region_id` + `district_id`, copied from the person
 * recording. Head Office is a district that shares a region id with its staff, so a location is always read as "Head Office or
 * the region without it" (GwaterVisibility), never by region alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gw_products')) {
            Schema::create('gw_products', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name', 100);
                $table->unsignedSmallInteger('units_per_pack');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gw_prices')) {
            Schema::create('gw_prices', function (Blueprint $table) {
                $table->id();
                // Both NULL = the system placeholder default (super_admin only); otherwise the owning location.
                $table->foreignId('region_id')->nullable()->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->date('effective_from');
                $table->unsignedInteger('selling_price');
                $table->unsignedInteger('cost_price');
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['product_id', 'region_id', 'district_id', 'effective_from'], 'gw_prices_lookup');
            });
        }

        if (! Schema::hasTable('gw_purchases')) {
            Schema::create('gw_purchases', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->date('purchase_date')->index();
                $table->string('waybill_no', 30);
                $table->string('supplier_ref', 60)->nullable();
                $table->text('note')->nullable();
                $table->string('status', 12)->default('posted')->index();

                // The Transport link: read-only references, G-Water never writes to those tables. The fuel expense is
                // unique, so it can never be counted by two trips (a voided trip lets go of it).
                $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
                $table->foreignId('driver_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('mileage_log_id')->nullable()->constrained('mileage_logs')->restrictOnDelete();
                $table->foreignId('vehicle_expense_id')->nullable()->unique()->constrained('vehicle_expenses')->restrictOnDelete();

                // Reimbursement of fuel paid out of pocket and linked from Transport (an amount is never copied here).
                $table->foreignId('fuel_paid_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('fuel_reimbursement_status', 16)->default('not_applicable')->index();
                $table->date('fuel_reimbursed_on')->nullable();
                $table->string('fuel_reimbursement_ref', 60)->nullable();
                $table->foreignId('fuel_reimbursed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

                $this->voidAndAuthor($table);
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'waybill_no'], 'gw_purchases_waybill');
            });
        }

        if (! Schema::hasTable('gw_purchase_lines')) {
            Schema::create('gw_purchase_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_id')->constrained('gw_purchases')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->unsignedInteger('packs');
                $table->unsignedInteger('unit_cost');
                $table->timestamps();

                $table->index(['purchase_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('gw_sales')) {
            Schema::create('gw_sales', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->date('sale_date')->index();
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->unsignedInteger('packs');
                $table->unsignedInteger('unit_price');
                $table->unsignedBigInteger('amount');
                $table->string('note', 255)->nullable();
                $table->string('status', 12)->default('posted')->index();
                $this->voidAndAuthor($table);
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'product_id', 'sale_date'], 'gw_sales_lookup');
            });
        }

        if (! Schema::hasTable('gw_stock_movements')) {
            Schema::create('gw_stock_movements', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->date('movement_date');
                $table->string('type', 16);
                $table->integer('packs'); // signed; the ledger is immutable, a correction is an `adjustment` row
                $table->string('source_type', 40)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'product_id', 'movement_date'], 'gw_stock_lookup');
                $table->index(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('gw_opening_balances')) {
            Schema::create('gw_opening_balances', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->date('as_of_date');
                // The packs per product are the `opening` rows of the stock ledger (source = this row); only the money is here.
                $table->unsignedBigInteger('owed_amount')->default(0);
                $table->string('note', 255)->nullable();
                $table->string('status', 12)->default('posted')->index();
                $this->voidAndAuthor($table);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gw_damages')) {
            Schema::create('gw_damages', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->date('damage_date')->index();
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->unsignedInteger('packs');
                $table->string('stage', 16);
                $table->unsignedInteger('unit_cost');
                $table->string('reason', 500);
                $table->foreignId('purchase_id')->nullable()->constrained('gw_purchases')->restrictOnDelete();
                $table->string('status', 12)->default('posted')->index();
                $this->voidAndAuthor($table);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gw_expenses')) {
            Schema::create('gw_expenses', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->date('expense_date')->index();
                $table->string('category', 16);
                $table->unsignedBigInteger('amount');
                $table->foreignId('purchase_id')->nullable()->constrained('gw_purchases')->restrictOnDelete();
                $table->string('payee', 120)->nullable();
                $table->string('receipt_ref', 60)->nullable();
                $table->text('note')->nullable();
                // Only `fuel` rows are ever reimbursable; labour and other rows stay not_applicable.
                $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('reimbursement_status', 16)->default('not_applicable')->index();
                $table->date('reimbursed_on')->nullable();
                $table->string('reimbursement_ref', 60)->nullable();
                $table->foreignId('reimbursed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('status', 12)->default('posted')->index();
                $this->voidAndAuthor($table);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gw_supplier_payments')) {
            Schema::create('gw_supplier_payments', function (Blueprint $table) {
                $table->id();
                $this->location($table);
                $table->date('request_date');
                $table->string('reference', 60);
                $table->unsignedBigInteger('amount');
                $table->date('paid_on')->nullable();
                $table->string('note', 255)->nullable();
                $table->string('status', 12)->default('requested')->index();
                $this->voidAndAuthor($table);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['gw_supplier_payments', 'gw_expenses', 'gw_damages', 'gw_opening_balances', 'gw_stock_movements', 'gw_sales', 'gw_purchase_lines', 'gw_purchases', 'gw_prices', 'gw_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function location(Blueprint $table): void
    {
        $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
        $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
    }

    private function voidAndAuthor(Blueprint $table): void
    {
        $table->timestamp('voided_at')->nullable();
        $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
        $table->string('void_reason', 500)->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
    }
};
