<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health & Safety, Phase 2: fire extinguishers and first aid kits, with their checks and (for extinguishers) service
 * records.
 *
 * Each item sits at a site OR in a vehicle: exactly one of site_id / vehicle_id is set. SQLite has no portable CHECK to
 * rely on, so the services enforce and the tests prove it. region_id and district_id are copied onto the item from its
 * site when it is saved (vehicles carry neither), which is what the register scope filters on.
 *
 * Nothing is deleted: items are decommissioned with a date and a reason. Checks and services are immutable rows.
 * vehicles.id is the numeric key; Vehicle soft-deletes, so a removed vehicle keeps its row and the link stays (the
 * nullOnDelete only fires on a force delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hs_fire_extinguishers')) {
            Schema::create('hs_fire_extinguishers', function (Blueprint $table) {
                $table->id();
                $table->string('asset_code', 40)->unique();
                $table->string('serial_number', 100)->nullable();
                // water | foam | dry_powder | co2 | wet_chemical
                $table->string('extinguisher_type', 20);
                $table->string('capacity', 50)->nullable();
                $table->string('manufacturer', 100)->nullable();
                $table->date('manufactured_on')->nullable();

                $table->foreignId('site_id')->nullable()->constrained('hs_sites')->restrictOnDelete();
                $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->string('location_detail')->nullable();
                $table->foreignId('responsible_employee_id')->nullable()->constrained('employees')->restrictOnDelete();

                // in_service | out_for_service | discharged | decommissioned (changed by hand, never computed)
                $table->string('status', 20)->default('in_service');

                // The dates that drive the computed state.
                $table->date('expiry_date')->nullable();
                $table->date('last_serviced_on')->nullable();
                $table->date('next_service_due')->nullable();
                $table->date('last_hydro_test_on')->nullable();
                $table->date('next_hydro_test_due')->nullable();
                $table->date('last_checked_on')->nullable();
                // pass | fail: the result of the latest check, kept here so lists and counts need no join.
                $table->string('last_check_result', 10)->nullable();

                $table->date('decommissioned_on')->nullable();
                $table->text('decommission_reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['region_id', 'status']);
                $table->index('expiry_date');
                $table->index('next_service_due');
                $table->index('site_id');
                $table->index('vehicle_id');
            });
        }

        if (! Schema::hasTable('hs_extinguisher_checks')) {
            Schema::create('hs_extinguisher_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('extinguisher_id')->constrained('hs_fire_extinguishers')->cascadeOnDelete();
                $table->date('checked_on');
                $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
                $table->boolean('in_place');
                $table->boolean('accessible');
                $table->boolean('seal_intact');
                $table->boolean('pressure_ok');
                $table->boolean('no_damage');
                $table->boolean('signage_ok');
                // pass when all six are yes, otherwise fail
                $table->string('result', 10);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['extinguisher_id', 'checked_on']);
            });
        }

        if (! Schema::hasTable('hs_extinguisher_services')) {
            Schema::create('hs_extinguisher_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('extinguisher_id')->constrained('hs_fire_extinguishers')->cascadeOnDelete();
                $table->date('serviced_on');
                // inspection | refill | recharge | hydro_test
                $table->string('service_type', 20);
                $table->string('vendor')->nullable();
                // On the private disk, never a public URL.
                $table->string('certificate_path')->nullable();
                $table->string('certificate_name')->nullable();
                $table->string('certificate_mime', 100)->nullable();
                $table->date('new_expiry_date')->nullable();
                $table->date('next_service_due')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['extinguisher_id', 'serviced_on']);
            });
        }

        if (! Schema::hasTable('hs_first_aid_kits')) {
            Schema::create('hs_first_aid_kits', function (Blueprint $table) {
                $table->id();
                $table->string('asset_code', 40)->unique();
                // small | medium | large | vehicle
                $table->string('kit_type', 20);

                $table->foreignId('site_id')->nullable()->constrained('hs_sites')->restrictOnDelete();
                $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->string('location_detail')->nullable();
                $table->foreignId('responsible_employee_id')->nullable()->constrained('employees')->restrictOnDelete();

                // in_service | missing | decommissioned
                $table->string('status', 20)->default('in_service');
                $table->date('last_checked_on')->nullable();
                $table->string('last_check_result', 10)->nullable();

                $table->date('decommissioned_on')->nullable();
                $table->text('decommission_reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['region_id', 'status']);
                $table->index('site_id');
                $table->index('vehicle_id');
            });
        }

        // What a new kit of a type starts with. A kit COPIES these when it is created, so editing a template later never
        // changes a kit that already exists. No rows are seeded: the contents are for EHS or a first aid trainer to confirm.
        if (! Schema::hasTable('hs_first_aid_item_templates')) {
            Schema::create('hs_first_aid_item_templates', function (Blueprint $table) {
                $table->id();
                $table->string('kit_type', 20);
                $table->string('item_name', 150);
                $table->unsignedSmallInteger('required_qty')->default(1);
                $table->boolean('has_expiry')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['kit_type', 'item_name']);
            });
        }

        if (! Schema::hasTable('hs_first_aid_kit_items')) {
            Schema::create('hs_first_aid_kit_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kit_id')->constrained('hs_first_aid_kits')->cascadeOnDelete();
                $table->string('item_name', 150);
                $table->unsignedSmallInteger('required_qty')->default(1);
                $table->unsignedSmallInteger('current_qty')->default(0);
                // Whether the item has a shelf life; copied from the template, so the screen knows to ask for a date.
                $table->boolean('has_expiry')->default(false);
                $table->date('expiry_date')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['kit_id', 'expiry_date']);
            });
        }

        if (! Schema::hasTable('hs_first_aid_kit_checks')) {
            Schema::create('hs_first_aid_kit_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kit_id')->constrained('hs_first_aid_kits')->cascadeOnDelete();
                $table->date('checked_on');
                $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
                // pass when, after the check, nothing is missing or expired; otherwise fail
                $table->string('result', 10);
                $table->boolean('restocked')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['kit_id', 'checked_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_first_aid_kit_checks');
        Schema::dropIfExists('hs_first_aid_kit_items');
        Schema::dropIfExists('hs_first_aid_item_templates');
        Schema::dropIfExists('hs_first_aid_kits');
        Schema::dropIfExists('hs_extinguisher_services');
        Schema::dropIfExists('hs_extinguisher_checks');
        Schema::dropIfExists('hs_fire_extinguishers');
    }
};
