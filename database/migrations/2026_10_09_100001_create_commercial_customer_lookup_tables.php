<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup tables for the Commercial customer list. The fact tables store these small integer ids, never the strings.
 *
 * The category grouping is PROPOSED (group_is_proposed) until the Commercial team confirms it; unknown codes found in a
 * file become *pending* rows (is_pending) that are surfaced for review instead of failing an import. Status codes whose
 * meaning has not been confirmed (TRFR, VACN, DISO, NFLO) are seeded with meaning_confirmed = false and are shown by their
 * raw code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_categories')) {
            Schema::create('commercial_customer_categories', function (Blueprint $table): void {
                $table->unsignedSmallInteger('id')->autoIncrement();
                $table->string('code', 12)->nullable()->unique();   // null only for the UNKNOWN row
                $table->string('name', 120);
                $table->string('category_group', 30)->default('unknown');
                $table->boolean('group_is_proposed')->default(true);
                $table->boolean('is_unknown')->default(false);
                $table->boolean('is_pending')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('commercial_customer_statuses')) {
            Schema::create('commercial_customer_statuses', function (Blueprint $table): void {
                $table->unsignedSmallInteger('id')->autoIncrement();
                $table->string('code', 12)->unique();
                $table->string('label', 80);
                $table->boolean('is_active')->default(false);
                $table->boolean('is_billing')->default(false);
                $table->boolean('meaning_confirmed')->default(false);
                $table->boolean('is_pending')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('commercial_meter_statuses')) {
            Schema::create('commercial_meter_statuses', function (Blueprint $table): void {
                $table->unsignedSmallInteger('id')->autoIncrement();
                $table->string('code', 12)->unique();
                $table->string('label', 80);
                $table->boolean('is_pending')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('commercial_routes')) {
            Schema::create('commercial_routes', function (Blueprint $table): void {
                $table->unsignedInteger('id')->autoIncrement();
                $table->foreignId('district_id')->constrained('districts')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('name', 80);
                $table->timestamps();

                $table->unique(['district_id', 'name']);
            });
        }

        $now = now();

        $categories = [
            ['611', 'DOMESTIC METERED', 'domestic'],
            ['612', 'COMMERCIAL', 'commercial'],
            ['610', 'COMMERCIAL WATER SALE', 'commercial'],
            ['609', 'NON RESIDENTIAL', 'commercial'],
            ['615', 'SACHET WATER', 'commercial'],
            ['630', 'BOTTLED WATER AND DRINKS', 'commercial'],
            ['627', 'POST & TELCOM', 'commercial'],
            ['613', 'INDUSTRIAL', 'industrial'],
            ['618', 'INDUSTRIAL SEWER', 'industrial'],
            ['623', '1ST CYCLE INSTITUTION', 'institutions'],
            ['624', '2ND CYCLE INSTITUTION', 'institutions'],
            ['629', '3RD CYCLE INSTITUTION', 'institutions'],
            ['614', 'PRIVATE INSTITUTION', 'institutions'],
            ['621', 'MINISTRIES\\DEPARTMENTS\\AGENCIES', 'institutions'],
            ['622', 'METROPOLITAN\\MUNICIPAL\\DISTRICT ASSEMBLIES', 'institutions'],
            ['601', 'BULK WATER SALES', 'bulk_tanker'],
            ['605', 'TANKER SERVICE', 'bulk_tanker'],
            ['635', 'TANKER OWNERS', 'bulk_tanker'],
            ['643', 'METERED STANDPIPES', 'standpipe'],
            ['652', 'NEW SERVICE CONNECTION FEE', 'non_water'],
            ['651', 'RECONNECTION FEE', 'non_water'],
            ['662', 'RENTAL FROM G. HOUSE', 'non_water'],
            ['672', 'TRANSPORT HIRE', 'non_water'],
            ['654', 'TRANSPORT CHARGES', 'non_water'],
            ['620', 'SECURITY SERVICES', 'non_water'],
            ['674', 'OTHER REVENUE', 'non_water'],
            ['631', 'INTRA COMP SALES', 'non_water'],
        ];

        foreach ($categories as [$code, $name, $group]) {
            DB::table('commercial_customer_categories')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'category_group' => $group, 'group_is_proposed' => true, 'is_unknown' => false, 'is_pending' => false, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        if (! DB::table('commercial_customer_categories')->where('is_unknown', true)->exists()) {
            DB::table('commercial_customer_categories')->insert(['code' => null, 'name' => 'UNKNOWN', 'category_group' => 'unknown', 'group_is_proposed' => true, 'is_unknown' => true, 'is_pending' => false, 'created_at' => $now, 'updated_at' => $now]);
        }

        // [code, label, is_active, is_billing, meaning_confirmed]
        $statuses = [
            ['ACTN', 'Active (non-billing)', true, false, true],
            ['ACTB', 'Active (billing)', true, true, true],
            ['DISC', 'Disconnected', false, false, true],
            ['SUSP', 'Suspended', false, false, true],
            ['TRFR', 'TRFR', false, false, false],
            ['VACN', 'VACN', false, false, false],
            ['DISO', 'DISO', false, false, false],
            ['NFLO', 'NFLO', false, false, false],
        ];

        foreach ($statuses as [$code, $label, $active, $billing, $confirmed]) {
            DB::table('commercial_customer_statuses')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'is_active' => $active, 'is_billing' => $billing, 'meaning_confirmed' => $confirmed, 'is_pending' => false, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        foreach ([['W', 'Working'], ['F', 'Faulty'], ['N', 'No meter']] as [$code, $label]) {
            DB::table('commercial_meter_statuses')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'is_pending' => false, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_routes');
        Schema::dropIfExists('commercial_meter_statuses');
        Schema::dropIfExists('commercial_customer_statuses');
        Schema::dropIfExists('commercial_customer_categories');
    }
};
