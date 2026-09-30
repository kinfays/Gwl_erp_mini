<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is told when leave is finally approved, per HR scope: one row per region, and one row with a NULL
 * region_id for Head Office (Head Office is a district, not a region, so it has no region of its own —
 * staff are Head Office by location_type, whatever region_id the Head Office district sits in).
 *
 * The unique index only guarantees one row per region: SQL lets several NULLs through it, so the single
 * Head Office row is kept unique by LeaveHrContactService.
 *
 * cascadeOnDelete, not nullOnDelete: nulling the region would silently turn a region's contact into the
 * Head Office contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_hr_contacts')) {
            return;
        }

        Schema::create('leave_hr_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('region_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_hr_contacts');
    }
};
