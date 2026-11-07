<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit (docs/20-module-finance-audit.md): ONE payment-voucher workflow shown through two modules.
 *
 *  - fin_pv_categories / fin_pv_types: the two-level catalogue, shared company-wide (not location-scoped), never deleted.
 *  - fin_suppliers: ONE company-wide list (screens and import arrive in phase 2; a voucher freezes the supplier id).
 *  - fin_pvs: the vouchers. A voucher belongs to ONE location (region_id + district_id copied from its creator); pv_no_key
 *    (trimmed, spaces collapsed, upper-cased) is unique across the whole company, voided vouchers included.
 *  - fin_pv_events: the immutable timeline (a model hook refuses update/delete).
 *  - fin_pv_attachments: optional scans / supporting documents on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_pv_categories')) {
            Schema::create('fin_pv_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fin_pv_types')) {
            Schema::create('fin_pv_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('fin_pv_categories')->restrictOnDelete();
                $table->string('name', 120);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['category_id', 'name']);
            });
        }

        if (! Schema::hasTable('fin_suppliers')) {
            Schema::create('fin_suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 200);
                // Trimmed, spaces collapsed, upper-cased: the company-wide uniqueness guard.
                $table->string('name_key', 200)->unique();
                $table->string('code', 40)->nullable();
                // Optional. A blank TIN is NULL, never '' (several NULLs are allowed by the unique index).
                $table->string('tin', 40)->nullable()->unique();
                $table->string('phone', 30)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                // Where it was first added from: information only, it grants no ownership and no reach.
                $table->foreignId('first_region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->foreignId('first_district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fin_pvs')) {
            Schema::create('fin_pvs', function (Blueprint $table) {
                $table->id();
                // The voucher's location: Head Office (its district) OR a region without Head Office.
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();

                $table->date('pv_date')->index();
                $table->string('pv_no', 60);
                $table->string('pv_no_key', 60)->unique();

                // Frozen when saved: who is paid.
                $table->string('payee_kind', 12);
                $table->string('payee_name', 200);
                $table->foreignId('payee_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
                $table->foreignId('payee_district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('fin_suppliers')->restrictOnDelete();

                $table->foreignId('category_id')->constrained('fin_pv_categories')->restrictOnDelete();
                $table->foreignId('type_id')->constrained('fin_pv_types')->restrictOnDelete();
                $table->text('details');
                $table->unsignedBigInteger('amount'); // pesewas
                $table->text('remarks')->nullable(); // Finance's own note, not where queries go

                $table->string('status', 24)->index();
                $table->unsignedSmallInteger('round')->default(1);
                $table->unsignedInteger('lock_version')->default(1);

                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('submitted_at')->nullable();      // first time it went to Audit
                $table->timestamp('last_submitted_at')->nullable(); // latest submit / resubmit / tick withdrawn
                $table->timestamp('completed_at')->nullable();
                // The content Audit last saw, to show "changed since the last query" on a resubmission.
                $table->json('submitted_snapshot')->nullable();

                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('void_reason')->nullable();
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'status']);
                $table->index(['region_id', 'pv_date']);
            });
        }

        if (! Schema::hasTable('fin_pv_events')) {
            Schema::create('fin_pv_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pv_id')->constrained('fin_pvs')->restrictOnDelete();
                $table->unsignedSmallInteger('round');
                $table->string('action', 30);
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->text('comment')->nullable();
                $table->string('query_reason_code', 40)->nullable();
                $table->json('changes')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['pv_id', 'id']);
                $table->index(['pv_id', 'action']);
            });
        }

        if (! Schema::hasTable('fin_pv_attachments')) {
            Schema::create('fin_pv_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pv_id')->constrained('fin_pvs')->restrictOnDelete();
                $table->string('path', 255); // random name on the private disk, never given a URL
                $table->string('display_name', 120);
                $table->string('mime', 60);
                $table->unsignedInteger('size');
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('pv_id');
            });
        }

        $this->seedCatalogue();
    }

    public function down(): void
    {
        foreach (['fin_pv_attachments', 'fin_pv_events', 'fin_pvs', 'fin_suppliers', 'fin_pv_types', 'fin_pv_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function seedCatalogue(): void
    {
        $now = now();

        $catalogue = [
            'Staff Claims' => ['Medical', 'Risk Allowance', 'Travel and Transportation (T&T) Allowance', 'Sitting Allowance', 'Incidental Allowance'],
            'Operations Activities' => ['Operations', 'Stationery', 'New Service', 'Contracts'],
        ];

        $order = 0;

        foreach ($catalogue as $category => $types) {
            DB::table('fin_pv_categories')->insertOrIgnore(['name' => $category, 'sort_order' => ++$order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $categoryId = DB::table('fin_pv_categories')->where('name', $category)->value('id');

            foreach ($types as $index => $type) {
                DB::table('fin_pv_types')->insertOrIgnore([
                    'category_id' => $categoryId,
                    'name' => $type,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
