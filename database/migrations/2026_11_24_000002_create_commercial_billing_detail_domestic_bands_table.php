<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8h: the domestic (category 611) band table of the Billing Summary Report (customers, volume and amount at or under and over 5 thousand
 * litres) needs a per-customer split the rollups do not hold, so the Detailed Billing Report scan counts it for category 611 only: one row per
 * (batch, district, band). `consume` is in the unit of the summary (the detailed Consume column times commercial_details_consume_factor at the time
 * of the upload) and `amount` is the month total in cedis. Rows follow their batch (cascade). A detailed batch loaded before this phase has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_billing_detail_domestic_bands')) {
            return;
        }

        Schema::create('commercial_billing_detail_domestic_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('commercial_import_batches')->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('district_key', 125);                    // the district id as text, or "l:<label printed>" while the district is unknown
            $table->string('band', 12);                              // '<=5' or '>5' (thousand litres)
            $table->unsignedInteger('customers')->default(0);
            $table->decimal('consume', 15, 3)->default(0);
            $table->decimal('amount', 15, 2)->default(0);

            $table->unique(['batch_id', 'district_key', 'band'], 'comm_bd_dom_bands_key_unique');
            $table->index(['batch_id', 'district_id'], 'comm_bd_dom_bands_batch_district_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_billing_detail_domestic_bands');
    }
};
