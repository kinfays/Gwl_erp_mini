<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network cabinets / server racks (Assets > Racks). A rack is its own record, NOT an asset type: devices point at it
 * (ict_assets.rack_id, added by the next migration), never the other way round.
 *
 * Scoped like an asset: region_id is stamped from the actor's own region and district_id is a district of it (Head
 * Office is a district that shares its region's id, so the region column is what separates "own region" from "other
 * regions", exactly as on ict_assets). The name is unique company-wide, as asset names are; the code is optional and
 * unique when given (a blank is stored as NULL, never ''). total_units is the height in U.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ict_racks')) {
            return;
        }

        Schema::create('ict_racks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 60)->nullable()->unique();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('location')->nullable();
            $table->unsignedTinyInteger('total_units')->default(42);
            $table->string('status', 40)->default('Active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ict_racks');
    }
};
