<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly targets per district for the three KPIs the Commercial team reports against: sales (volume, m3 = thousand litres),
 * billing and collection (GH¢). A target applies from its month until a later row for the same district and KPI replaces it, so a
 * new year's targets are new rows, never edits of old ones (older months keep the target they were judged against).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_district_targets')) {
            Schema::create('commercial_district_targets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
                $table->string('metric', 12);                 // sales | billing | collection
                $table->date('effective_from');               // the first day of the first month it applies to
                $table->decimal('value', 16, 2);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['district_id', 'metric', 'effective_from'], 'commercial_targets_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_district_targets');
    }
};
