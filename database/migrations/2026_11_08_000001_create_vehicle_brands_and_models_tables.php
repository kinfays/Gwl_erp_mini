<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle brands and models become a small catalogue (like the Assets manufacturers / models) so the vehicle form can
 * offer a brand and then only that brand's models. vehicles.brand / vehicles.model stay as plain text (reports, exports,
 * search and import keep reading them); the form writes the chosen names into them. Existing vehicles' spellings are
 * folded into the catalogue (case-insensitively, first spelling wins).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_brands')) {
            Schema::create('vehicle_brands', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_models')) {
            Schema::create('vehicle_models', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_brand_id')->constrained('vehicle_brands')->restrictOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['vehicle_brand_id', 'name']);
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_brands');
    }

    private function backfill(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        $now = now();
        $brandIds = [];
        $modelKeys = [];

        // Raw table on purpose: soft-deleted vehicles keep their spelling in the catalogue too.
        foreach (DB::table('vehicles')->select('brand', 'model')->orderBy('id')->get() as $row) {
            $brand = trim((string) $row->brand);

            if ($brand === '') {
                continue;
            }

            $brandKey = mb_strtolower($brand);

            if (! isset($brandIds[$brandKey])) {
                $existing = DB::table('vehicle_brands')->whereRaw('LOWER(name) = ?', [$brandKey])->value('id');
                $brandIds[$brandKey] = $existing ?: DB::table('vehicle_brands')->insertGetId([
                    'name' => $brand,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $model = trim((string) $row->model);

            if ($model === '') {
                continue;
            }

            $modelKey = $brandKey.'|'.mb_strtolower($model);

            if (isset($modelKeys[$modelKey])) {
                continue;
            }

            $modelKeys[$modelKey] = true;

            $exists = DB::table('vehicle_models')
                ->where('vehicle_brand_id', $brandIds[$brandKey])
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($model)])
                ->exists();

            if (! $exists) {
                DB::table('vehicle_models')->insert([
                    'vehicle_brand_id' => $brandIds[$brandKey],
                    'name' => $model,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
