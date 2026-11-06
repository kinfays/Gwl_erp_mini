<?php

namespace App\Console\Commands\Assets;

use App\Models\IctAsset;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists assets that share a name (ignoring case and surrounding spaces) and, once there are none, adds the unique
 * index the migration may have skipped. Read-only unless --add-index is given; it never renames or deletes anything.
 */
class DuplicateAssetNames extends Command
{
    protected $signature = 'assets:duplicate-names {--add-index : Add the unique index on ict_assets.asset_name_key when no duplicates remain}';

    protected $description = 'List assets that share a name, and optionally add the unique name index once they are fixed';

    public function handle(): int
    {
        $groups = DB::table('ict_assets')
            ->select('id', 'asset_name', 'serial_number', 'device_category')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => IctAsset::nameKey((string) $row->asset_name))
            ->filter(fn ($rows) => $rows->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate asset names.');
        } else {
            $this->warn($groups->count().' name(s) are used more than once:');

            foreach ($groups as $rows) {
                $this->line('');
                $this->line('"'.trim((string) $rows->first()->asset_name).'"');
                $this->table(
                    ['ID', 'Name as stored', 'Serial', 'Category'],
                    $rows->map(fn ($row) => [$row->id, $row->asset_name, $row->serial_number, $row->device_category])->all()
                );
            }
        }

        if (! $this->option('add-index')) {
            return $groups->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        if ($groups->isNotEmpty()) {
            $this->error('Rename the assets above first, then run this again with --add-index.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('ict_assets', 'asset_name_key')) {
            $this->error('Run "php artisan migrate" first: the asset_name_key column does not exist yet.');

            return self::FAILURE;
        }

        // Rows saved while the index was missing may have a stale or empty key.
        DB::table('ict_assets')->select('id', 'asset_name')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('ict_assets')->where('id', $row->id)->update(['asset_name_key' => IctAsset::nameKey((string) $row->asset_name)]);
            }
        });

        if (collect(Schema::getIndexes('ict_assets'))->contains(fn ($index) => $index['name'] === 'ict_assets_asset_name_key_unique')) {
            $this->info('The unique index is already in place.');

            return self::SUCCESS;
        }

        Schema::table('ict_assets', function (Blueprint $table) {
            $table->unique('asset_name_key', 'ict_assets_asset_name_key_unique');
        });

        $this->info('Unique index added.');

        return self::SUCCESS;
    }
}
