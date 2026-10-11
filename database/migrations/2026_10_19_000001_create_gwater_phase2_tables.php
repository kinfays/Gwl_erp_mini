<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-Water Phase 2 (docs/17-module-g-water.md): what damages, expenses, payments to G Water, the owed balance and the
 * waybill-outstanding view need on top of the Phase 1 tables.
 *
 *  - gw_expenses.vehicle_id          an optional Transport vehicle on a G-Water fuel expense (read through TransportScope);
 *  - gw_supplier_payment_lines       the packs a payment request was raised for, set against the packs delivered on waybills;
 *  - gw_opening_balance_lines         per product of an opening balance: packs, the cost they are valued at in the profit and
 *                                     loss (frozen on the opening date) and the packs paid for but not yet delivered.
 * Opening balances already posted in Phase 1 get their lines from their `opening` ledger rows, valued at the cost price in
 * force on their date (the location's own list, else the placeholder), frozen here once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gw_expenses') && ! Schema::hasColumn('gw_expenses', 'vehicle_id')) {
            Schema::table('gw_expenses', function (Blueprint $table) {
                $table->foreignId('vehicle_id')->nullable()->after('purchase_id')->constrained('vehicles')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('gw_supplier_payment_lines')) {
            Schema::create('gw_supplier_payment_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->constrained('gw_supplier_payments')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->unsignedInteger('packs');
                $table->unsignedInteger('unit_price');
                $table->timestamps();

                $table->index(['payment_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('gw_opening_balance_lines')) {
            Schema::create('gw_opening_balance_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('opening_balance_id')->constrained('gw_opening_balances')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('gw_products')->restrictOnDelete();
                $table->unsignedInteger('packs')->default(0);
                $table->unsignedInteger('unit_cost')->default(0);
                $table->unsignedInteger('outstanding_packs')->default(0);
                $table->timestamps();

                $table->unique(['opening_balance_id', 'product_id']);
            });

            $this->backfillOpeningLines();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gw_opening_balance_lines');
        Schema::dropIfExists('gw_supplier_payment_lines');

        if (Schema::hasTable('gw_expenses') && Schema::hasColumn('gw_expenses', 'vehicle_id')) {
            Schema::table('gw_expenses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('vehicle_id');
            });
        }
    }

    private function backfillOpeningLines(): void
    {
        if (! Schema::hasTable('gw_opening_balances') || ! Schema::hasTable('gw_stock_movements')) {
            return;
        }

        $now = now();

        foreach (DB::table('gw_opening_balances')->get() as $opening) {
            $packs = DB::table('gw_stock_movements')
                ->where('source_type', 'gw_opening_balance')->where('source_id', $opening->id)
                ->select('product_id', DB::raw('SUM(packs) as packs'))->groupBy('product_id')->get();

            foreach ($packs as $row) {
                $price = DB::table('gw_prices')
                    ->where('product_id', $row->product_id)
                    ->whereDate('effective_from', '<=', $opening->as_of_date)
                    ->where(function ($query) use ($opening) {
                        $query->where(fn ($own) => $own->where('region_id', $opening->region_id)
                            ->where(fn ($district) => $opening->district_id === null ? $district->whereNull('district_id') : $district->where('district_id', $opening->district_id)))
                            ->orWhere(fn ($default) => $default->whereNull('region_id')->whereNull('district_id'));
                    })
                    // Own rows (region set) before the default, then the latest date, then the latest row.
                    ->orderByRaw('CASE WHEN region_id IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw('DATE(effective_from) desc')
                    ->orderByDesc('id')
                    ->first();

                DB::table('gw_opening_balance_lines')->insert([
                    'opening_balance_id' => $opening->id,
                    'product_id' => $row->product_id,
                    'packs' => max(0, (int) $row->packs),
                    'unit_cost' => (int) ($price->cost_price ?? 0),
                    'outstanding_packs' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
