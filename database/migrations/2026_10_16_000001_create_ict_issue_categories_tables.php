<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-level issue taxonomy (category -> subtype) for ICT issue reports.
 *
 * ict_asset_issue_reports.issue_type is NOT touched: it keeps holding the chosen category's plain label, so existing
 * filters, reports and historical rows keep working. Only a nullable issue_subtype string is added next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_issue_categories')) {
            Schema::create('ict_issue_categories', function (Blueprint $table) {
                $table->id();
                $table->string('label')->unique();
                $table->boolean('requires_linked_asset')->default(false);
                $table->string('maps_to_device_category')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ict_issue_subtypes')) {
            Schema::create('ict_issue_subtypes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ict_issue_category_id')->constrained('ict_issue_categories')->cascadeOnDelete();
                $table->string('label');
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['ict_issue_category_id', 'label']);
            });
        }

        if (Schema::hasTable('ict_asset_issue_reports') && ! Schema::hasColumn('ict_asset_issue_reports', 'issue_subtype')) {
            Schema::table('ict_asset_issue_reports', function (Blueprint $table) {
                $table->string('issue_subtype')->nullable()->after('issue_type');
            });
        }

        $this->seed();
    }

    public function down(): void
    {
        if (Schema::hasTable('ict_asset_issue_reports') && Schema::hasColumn('ict_asset_issue_reports', 'issue_subtype')) {
            Schema::table('ict_asset_issue_reports', function (Blueprint $table) {
                $table->dropColumn('issue_subtype');
            });
        }

        Schema::dropIfExists('ict_issue_subtypes');
        Schema::dropIfExists('ict_issue_categories');
    }

    /** label => [requires_linked_asset, maps_to_device_category, subtypes] */
    private function seed(): void
    {
        $now = now();

        $categories = [
            'Computer' => [true, 'asset', ["Won't boot", 'Blue screen / crash', 'Slow performance', 'Keyboard', 'Display/Screen', 'Battery', 'Overheating', 'Virus/Malware', 'Software install', 'Other']],
            'Printer/Photocopier' => [true, 'asset', ['Paper jam', 'Toner/Ink', 'Connectivity', 'Driver', 'Print quality', 'Other']],
            'Phone' => [true, 'phone', ['Screen', 'Power button', 'Battery', 'Camera', 'Speaker/Mic', 'Charging port', 'SIM', 'Software/App', 'Other']],
            'Network/Internet' => [false, 'network', ['Internal', 'ISP', 'Hardware (router/switch/AP)', 'Wi-Fi coverage', 'Other']],
            'Password Reset' => [false, null, []],
            'BitLocker' => [false, null, []],
            'Software' => [false, null, []],
            'Other' => [false, null, []],
        ];

        $order = 0;

        foreach ($categories as $label => [$requiresAsset, $maps, $subtypes]) {
            $order += 10;

            // Insert-if-missing keyed on the label, so a re-run never duplicates or undoes an admin's later edits.
            $existing = DB::table('ict_issue_categories')->where('label', $label)->first();

            if ($existing) {
                $categoryId = $existing->id;
            } else {
                $categoryId = DB::table('ict_issue_categories')->insertGetId([
                    'label' => $label,
                    'requires_linked_asset' => $requiresAsset,
                    'maps_to_device_category' => $maps,
                    'is_active' => true,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $subOrder = 0;

            foreach ($subtypes as $subtype) {
                $subOrder += 10;

                if (! DB::table('ict_issue_subtypes')->where('ict_issue_category_id', $categoryId)->where('label', $subtype)->exists()) {
                    DB::table('ict_issue_subtypes')->insert([
                        'ict_issue_category_id' => $categoryId,
                        'label' => $subtype,
                        'sort_order' => $subOrder,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
