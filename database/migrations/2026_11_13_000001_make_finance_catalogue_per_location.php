<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit change request, step B (docs/20-module-finance-audit.md): the category / type catalogue becomes PER LOCATION.
 *
 *  - fin_pv_categories / fin_pv_types get a `unit` column: NULL = a SYSTEM DEFAULT row (the seeded ones; only super_admin edits
 *    them), otherwise the location that owns the row ('ho' = Head Office, 'r<region id>' = a region without Head Office: the same
 *    key FinanceBooksVersion uses).
 *  - the global unique indexes on the names are dropped (two locations may each add a "Fuel" type); a name is unique, case
 *    insensitively, within a location's EFFECTIVE list, which PvCatalogService checks.
 *  - fin_catalog_overrides: a location's own display name and / or on-off flag for a system default item. A row exists only when
 *    something differs from the default and is removed when both values are back to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL needs an index on the foreign key column before the composite unique that served it can go.
        if (Schema::hasTable('fin_pv_types') && ! Schema::hasIndex('fin_pv_types', ['category_id'])) {
            Schema::table('fin_pv_types', function (Blueprint $table) {
                $table->index('category_id');
            });
        }

        if (Schema::hasTable('fin_pv_types') && Schema::hasIndex('fin_pv_types', ['category_id', 'name'], 'unique')) {
            Schema::table('fin_pv_types', function (Blueprint $table) {
                $table->dropUnique(['category_id', 'name']);
            });
        }

        if (Schema::hasTable('fin_pv_categories') && Schema::hasIndex('fin_pv_categories', ['name'], 'unique')) {
            Schema::table('fin_pv_categories', function (Blueprint $table) {
                $table->dropUnique(['name']);
            });
        }

        foreach (['fin_pv_categories', 'fin_pv_types'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'unit')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('unit', 20)->nullable()->index();
                });
            }
        }

        if (! Schema::hasTable('fin_catalog_overrides')) {
            Schema::create('fin_catalog_overrides', function (Blueprint $table) {
                $table->id();
                $table->string('unit', 20);
                $table->string('kind', 10); // category | type
                $table->unsignedBigInteger('item_id');
                $table->string('display_name', 120)->nullable();
                $table->boolean('is_active')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['unit', 'kind', 'item_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_catalog_overrides');

        foreach (['fin_pv_categories', 'fin_pv_types'] as $name) {
            if (Schema::hasColumn($name, 'unit')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('unit');
                });
            }
        }
    }
};
