<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturers become their own catalogue (Assets > Settings > Manufacturers)
 * instead of free text typed on each model. Existing
 * ict_asset_models.manufacturer strings are folded into the new table
 * (case-insensitively, first spelling wins) before that column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_asset_manufacturers')) {
            Schema::create('ict_asset_manufacturers', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('ict_asset_models', 'ict_asset_manufacturer_id')) {
            Schema::table('ict_asset_models', function (Blueprint $table) {
                $table->foreignId('ict_asset_manufacturer_id')
                    ->nullable()
                    ->after('category')
                    ->constrained('ict_asset_manufacturers')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('ict_asset_models', 'manufacturer')) {
            $this->backfillManufacturers();

            if (Schema::hasIndex('ict_asset_models', ['manufacturer'])) {
                Schema::table('ict_asset_models', function (Blueprint $table) {
                    $table->dropIndex(['manufacturer']);
                });
            }

            Schema::table('ict_asset_models', function (Blueprint $table) {
                $table->dropColumn('manufacturer');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ict_asset_models', 'manufacturer')) {
            Schema::table('ict_asset_models', function (Blueprint $table) {
                $table->string('manufacturer')->nullable()->index()->after('category');
            });
        }

        if (Schema::hasColumn('ict_asset_models', 'ict_asset_manufacturer_id')) {
            $names = DB::table('ict_asset_manufacturers')->pluck('name', 'id');

            DB::table('ict_asset_models')
                ->whereNotNull('ict_asset_manufacturer_id')
                ->get(['id', 'ict_asset_manufacturer_id'])
                ->each(fn ($model) => DB::table('ict_asset_models')
                    ->where('id', $model->id)
                    ->update(['manufacturer' => $names[$model->ict_asset_manufacturer_id] ?? null]));

            Schema::table('ict_asset_models', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ict_asset_manufacturer_id');
            });
        }

        Schema::dropIfExists('ict_asset_manufacturers');
    }

    protected function backfillManufacturers(): void
    {
        $now = now();
        $manufacturerIds = [];

        DB::table('ict_asset_models')
            ->whereNotNull('manufacturer')
            ->whereNull('ict_asset_manufacturer_id')
            ->orderBy('id')
            ->get(['id', 'manufacturer'])
            ->each(function ($model) use (&$manufacturerIds, $now) {
                $name = trim((string) $model->manufacturer);

                if ($name === '') {
                    return;
                }

                $key = mb_strtolower($name);

                $manufacturerIds[$key] ??= DB::table('ict_asset_manufacturers')
                    ->whereRaw('LOWER(name) = ?', [$key])
                    ->value('id')
                    ?? DB::table('ict_asset_manufacturers')->insertGetId([
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                DB::table('ict_asset_models')
                    ->where('id', $model->id)
                    ->update(['ict_asset_manufacturer_id' => $manufacturerIds[$key]]);
            });
    }
};
