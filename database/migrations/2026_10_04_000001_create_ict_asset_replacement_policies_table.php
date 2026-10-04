<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_asset_replacement_policies')) {
            Schema::create('ict_asset_replacement_policies', function (Blueprint $table) {
                $table->id();
                // NULL = the default policy for every asset_type without its own row. The unique index cannot stop a
                // second NULL row (NULLs never collide), so the settings screen only ever updates the existing one.
                $table->string('asset_type')->nullable()->unique();
                $table->unsignedSmallInteger('years');
                $table->timestamps();
            });
        }

        // The confirmed ICT policy: replace after 4 years, for every asset type.
        if (! DB::table('ict_asset_replacement_policies')->whereNull('asset_type')->exists()) {
            DB::table('ict_asset_replacement_policies')->insert([
                'asset_type' => null,
                'years' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ict_asset_replacement_policies');
    }
};
