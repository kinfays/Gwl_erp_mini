<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Targets and thresholds an administrator has changed on the Commercial settings screen. A row is an OVERRIDE: with
        // none, config/gwl.php (and so .env) supplies the value. Deleting a row is "reset to default".
        if (! Schema::hasTable('commercial_settings')) {
            Schema::create('commercial_settings', function (Blueprint $table) {
                $table->id();
                // The config key under gwl.* (a dot reaches into an array, e.g. commercial_scorecard_weights.volume).
                $table->string('name')->unique();
                $table->string('value');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // When an "upload overdue" reminder last went out for a region and report type, so a daily scheduler run does not
        // nag every day.
        if (! Schema::hasTable('commercial_reminder_states')) {
            Schema::create('commercial_reminder_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
                $table->string('report_type');
                $table->timestamp('last_reminded_at')->nullable();
                $table->foreignId('last_batch_id')->nullable()->constrained('commercial_import_batches')->nullOnDelete();
                $table->timestamps();

                $table->unique(['region_id', 'report_type'], 'comm_reminder_region_type_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_reminder_states');
        Schema::dropIfExists('commercial_settings');
    }
};
