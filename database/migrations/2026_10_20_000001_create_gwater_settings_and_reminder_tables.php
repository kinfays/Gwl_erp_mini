<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-Water Phase 4 (docs/17-module-g-water.md): per-location settings and the state of the reminders.
 *
 *  - gw_settings         an OVERRIDE of a config/gwl.php default for one location (Head Office, or a region without it):
 *                        `key` is 'low_stock_<product code>', 'payment_overdue_days' or 'reminder_repeat_days'. A value equal to
 *                        its default is stored as no row. Read only through GwaterSettings.
 *  - gw_reminder_states  when an item (a low-stock product, an overdue payment request) was last reminded, per location, so a
 *                        reminder is sent at most once per repeat interval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gw_settings')) {
            Schema::create('gw_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->string('key', 60);
                $table->unsignedInteger('value');
                $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'key'], 'gw_settings_lookup');
            });
        }

        if (! Schema::hasTable('gw_reminder_states')) {
            Schema::create('gw_reminder_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->string('kind', 24);
                $table->unsignedBigInteger('subject_id');
                $table->timestamp('last_reminded_at');
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'kind', 'subject_id'], 'gw_reminder_states_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gw_reminder_states');
        Schema::dropIfExists('gw_settings');
    }
};
