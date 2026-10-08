<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health & Safety, Phase 3: PPE types, the stock ledger, issues to staff, entitlements by job title and reorder levels.
 *
 * Stock is a LEDGER: hs_ppe_stock_movements holds signed quantities that are never edited or deleted, and a balance is
 * their sum. Issues record who holds what; a closed issue (returned, worn out, damaged, lost) keeps its row. Nothing in
 * the PPE tables is ever deleted: types are deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hs_ppe_types')) {
            Schema::create('hs_ppe_types', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150)->unique();
                // head | eye_face | hearing | respiratory | hand | foot | body | fall_water
                $table->string('category', 20);
                $table->boolean('has_sizes')->default(false);
                // ["S","M","L"] when has_sizes
                $table->json('sizes')->nullable();
                // Service life: months from issue to replacement. Null: no replacement date is set.
                $table->unsignedSmallInteger('replacement_months')->nullable();
                // An item with its own expiry date (a respirator filter): the date is asked for when it is issued.
                $table->boolean('has_expiry')->default(false);
                $table->string('unit', 30)->default('each');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hs_ppe_issues')) {
            Schema::create('hs_ppe_issues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
                $table->foreignId('ppe_type_id')->constrained('hs_ppe_types')->restrictOnDelete();
                $table->string('size', 30)->nullable();
                $table->unsignedSmallInteger('quantity');
                $table->date('issued_on');
                $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
                // The earlier of issued_on + the type's replacement months and expires_on; null when neither applies.
                $table->date('replace_due_on')->nullable();
                $table->date('expires_on')->nullable();
                // "Already held": records what someone holds and since when, with no stock taken from a store.
                $table->boolean('is_historic')->default(false);
                // issued | returned | worn_out | damaged | lost
                $table->string('status', 20)->default('issued');
                $table->date('closed_on')->nullable();
                $table->text('close_note')->nullable();
                // The issue that replaced this one, when it was closed as part of a replacement.
                $table->foreignId('replaced_by_issue_id')->nullable()->constrained('hs_ppe_issues')->nullOnDelete();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'ppe_type_id', 'status']);
                $table->index('replace_due_on');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('hs_ppe_stock_movements')) {
            Schema::create('hs_ppe_stock_movements', function (Blueprint $table) {
                $table->id();
                // The store the stock is in (a site flagged is_ppe_store).
                $table->foreignId('site_id')->constrained('hs_sites')->restrictOnDelete();
                $table->foreignId('ppe_type_id')->constrained('hs_ppe_types')->restrictOnDelete();
                $table->string('size', 30)->nullable();
                // receipt | issue | return | write_off | adjustment | transfer_in | transfer_out
                $table->string('movement_type', 20);
                // SIGNED: receipt, return and transfer_in are positive; issue, write_off and transfer_out negative;
                // an adjustment is either way but never zero. A balance is the sum of these.
                $table->integer('quantity');
                // A delivery note number, or the reference shared by the two halves of a transfer.
                $table->string('reference', 100)->nullable();
                $table->foreignId('issue_id')->nullable()->constrained('hs_ppe_issues')->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->date('occurred_on');
                $table->timestamps();

                $table->index(['site_id', 'ppe_type_id', 'size']);
                $table->index('issue_id');
                $table->index('occurred_on');
            });
        }

        if (! Schema::hasTable('hs_ppe_reorder_levels')) {
            Schema::create('hs_ppe_reorder_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained('hs_sites')->cascadeOnDelete();
                $table->foreignId('ppe_type_id')->constrained('hs_ppe_types')->cascadeOnDelete();
                // Stock at or below this (the total across sizes) is flagged low.
                $table->unsignedInteger('level');
                $table->timestamps();

                $table->unique(['site_id', 'ppe_type_id']);
            });
        }

        if (! Schema::hasTable('hs_ppe_entitlements')) {
            Schema::create('hs_ppe_entitlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_title_id')->constrained('job_titles')->restrictOnDelete();
                $table->foreignId('ppe_type_id')->constrained('hs_ppe_types')->restrictOnDelete();
                // How many of this item staff with this job title should hold. At least one: no row means no entitlement.
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->timestamps();

                $table->unique(['job_title_id', 'ppe_type_id']);
            });
        }

        // Which sites are PPE stores. Separate from the create above so a database that has the tables still gets the column.
        if (Schema::hasTable('hs_sites') && ! Schema::hasColumn('hs_sites', 'is_ppe_store')) {
            Schema::table('hs_sites', function (Blueprint $table) {
                $table->boolean('is_ppe_store')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hs_sites') && Schema::hasColumn('hs_sites', 'is_ppe_store')) {
            Schema::table('hs_sites', function (Blueprint $table) {
                $table->dropColumn('is_ppe_store');
            });
        }

        Schema::dropIfExists('hs_ppe_entitlements');
        Schema::dropIfExists('hs_ppe_reorder_levels');
        Schema::dropIfExists('hs_ppe_stock_movements');
        Schema::dropIfExists('hs_ppe_issues');
        Schema::dropIfExists('hs_ppe_types');
    }
};
