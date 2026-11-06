<?php

use App\Models\IctAsset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Asset names must be unique, ignoring case and surrounding spaces. `asset_name_key` holds that comparable form
 * (IctAsset keeps it in step on every save) and carries the unique index, which works the same on MySQL and SQLite.
 *
 * Existing data is never renamed or deleted. The key is backfilled for every row; the unique index is added only when
 * no two assets share a key. If some do, the migration still succeeds, logs and prints the clash, and leaves the index
 * off (the form and AssetRecordService already refuse new duplicates). Find them with `php artisan assets:duplicate-names`,
 * fix them by hand, then run `php artisan assets:duplicate-names --add-index`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_assets')) {
            return;
        }

        if (! Schema::hasColumn('ict_assets', 'asset_name_key')) {
            Schema::table('ict_assets', function (Blueprint $table) {
                $table->string('asset_name_key')->nullable()->after('asset_name');
            });
        }

        DB::table('ict_assets')->select('id', 'asset_name')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('ict_assets')->where('id', $row->id)->update([
                    'asset_name_key' => IctAsset::nameKey((string) $row->asset_name),
                ]);
            }
        });

        $duplicates = DB::table('ict_assets')
            ->select('asset_name_key', DB::raw('COUNT(*) as total'))
            ->whereNotNull('asset_name_key')
            ->groupBy('asset_name_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $message = $duplicates->count().' asset name(s) are used by more than one asset, so the unique index on ict_assets.asset_name_key was NOT added. '
                .'List them with "php artisan assets:duplicate-names", rename the extras, then run "php artisan assets:duplicate-names --add-index".';

            Log::warning($message);
            fwrite(STDERR, PHP_EOL.'WARNING: '.$message.PHP_EOL);

            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            $table->unique('asset_name_key', 'ict_assets_asset_name_key_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ict_assets') || ! Schema::hasColumn('ict_assets', 'asset_name_key')) {
            return;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            try {
                $table->dropUnique('ict_assets_asset_name_key_unique');
            } catch (Throwable $e) {
                // The index was never added (duplicates existed).
            }
        });

        Schema::table('ict_assets', function (Blueprint $table) {
            $table->dropColumn('asset_name_key');
        });
    }
};
