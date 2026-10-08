<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: the editable Health & Safety settings (an override of the config default, like the Commercial module's) and the
 * alert log that lets the daily command tell people about each item once per threshold.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hs_settings')) {
            Schema::create('hs_settings', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->text('value')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hs_alert_log')) {
            Schema::create('hs_alert_log', function (Blueprint $table) {
                $table->id();
                // What was alerted about: extinguisher_expiry, extinguisher_service, extinguisher_hydro, kit_item_expiry,
                // ppe_replacement, action_due, incident_ack, incident_investigation.
                $table->string('alert_type', 40);
                $table->unsignedBigInteger('item_id');
                // The due date (Y-m-d) the alert was about, or "n/a": a string rather than a nullable date so the unique index
                // works on MySQL (NULLs are distinct there) and a renewed date starts a fresh cycle.
                $table->string('due_key', 20);
                // Days relative to the due date (negative = overdue), or the escalation tier for incidents.
                $table->smallInteger('threshold');
                $table->timestamp('sent_at')->useCurrent();

                $table->unique(['alert_type', 'item_id', 'due_key', 'threshold'], 'hs_alert_log_unique');
                $table->index('sent_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_alert_log');
        Schema::dropIfExists('hs_settings');
    }
};
