<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Android Enterprise (Android Management API) device management for the Assets module.
 *
 * Device identity (serial, IMEI, model, region, district, assignee) is NOT duplicated here: an mdm_devices
 * row points at the phone's ict_assets record. The human action trail lives in the existing audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mdm_policies')) {
            Schema::create('mdm_policies', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->text('description')->nullable();
                // enterprises/{enterprise}/policies/{id}; null until the first publish.
                $table->string('google_policy_name')->nullable()->unique();

                $table->string('play_store_mode')->default('WHITELIST');
                $table->boolean('install_apps_disabled')->default(true);
                $table->boolean('uninstall_apps_disabled')->default(true);
                $table->boolean('factory_reset_disabled')->default(true);
                $table->boolean('add_user_disabled')->default(true);
                $table->boolean('screen_capture_disabled')->default(false);
                $table->string('camera_access')->default('CAMERA_ACCESS_USER_CHOICE');
                $table->string('usb_data_access')->default('DISALLOW_USB_FILE_TRANSFER');
                $table->string('untrusted_apps_policy')->default('DISALLOW_INSTALL');
                $table->string('developer_settings')->default('DEVELOPER_SETTINGS_DISABLED');
                $table->unsignedTinyInteger('password_min_length')->nullable();
                $table->string('password_quality')->nullable();
                $table->string('system_update_type')->default('AUTOMATIC');
                $table->unsignedSmallInteger('system_update_start_minutes')->nullable();
                $table->unsignedSmallInteger('system_update_end_minutes')->nullable();
                // Factory reset protection: a wiped phone still needs one of these Google accounts.
                $table->json('frp_admin_emails')->nullable();

                // The exact AMAPI payload last accepted by Google, so "Publish" can show a diff.
                $table->json('last_published_payload')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('version')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mdm_policy_apps')) {
            Schema::create('mdm_policy_apps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mdm_policy_id')->constrained('mdm_policies')->cascadeOnDelete();
                $table->string('package_name');
                $table->string('app_name')->nullable();
                $table->string('install_type')->default('FORCE_INSTALLED');
                $table->string('default_permission_policy')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['mdm_policy_id', 'package_name']);
            });
        }

        if (! Schema::hasTable('mdm_devices')) {
            Schema::create('mdm_devices', function (Blueprint $table) {
                $table->id();
                // Nullable on purpose: an ENROLLMENT event whose token carried no (or an unknown) asset id still
                // needs a row. Such devices are flagged needs_review and only admins can see them.
                $table->foreignId('ict_asset_id')->nullable()->unique()->constrained('ict_assets')->restrictOnDelete();
                $table->foreignId('mdm_policy_id')->nullable()->constrained('mdm_policies')->nullOnDelete();
                $table->string('google_device_name')->unique();
                $table->string('enrollment_token_name')->nullable();

                $table->string('management_mode')->nullable();
                $table->string('state')->nullable();
                $table->string('applied_state')->nullable();
                // Tri-state: null until the device has reported at least once.
                $table->boolean('policy_compliant')->nullable();
                $table->json('non_compliance')->nullable();
                $table->string('android_version')->nullable();
                $table->string('security_patch_level')->nullable();
                $table->json('hardware_info')->nullable();
                $table->json('application_reports')->nullable();

                $table->timestamp('last_status_report_at')->nullable()->index();
                $table->timestamp('last_policy_sync_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamp('enrolled_at')->nullable();

                $table->boolean('is_lost')->default(false)->index();
                $table->timestamp('lost_at')->nullable();
                $table->boolean('needs_review')->default(false)->index();
                $table->text('review_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mdm_enrollment_tokens')) {
            Schema::create('mdm_enrollment_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
                $table->foreignId('mdm_policy_id')->nullable()->constrained('mdm_policies')->nullOnDelete();
                // Only the token's resource name is kept. The token value / QR payload is a live credential for
                // 60 minutes and is shown once, never stored.
                $table->string('google_token_name')->unique();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mdm_device_commands')) {
            Schema::create('mdm_device_commands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mdm_device_id')->constrained('mdm_devices')->cascadeOnDelete();
                // LOCK | REBOOT | RESET_PASSWORD | START_LOST_MODE | STOP_LOST_MODE | WIPE
                $table->string('type');
                // Encrypted at rest (RESET_PASSWORD carries a passcode); secrets are blanked once delivered.
                $table->text('payload')->nullable();
                // requested -> sent -> acknowledged | failed
                $table->string('status')->default('requested')->index();
                $table->string('google_operation_name')->nullable()->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mdm_events')) {
            Schema::create('mdm_events', function (Blueprint $table) {
                $table->id();
                // The dedupe key: push redelivery, pull redelivery and a push/pull cutover all collapse to one row.
                $table->string('pubsub_message_id')->unique();
                $table->string('delivery_mode', 8);
                $table->string('notification_type')->nullable()->index();
                $table->string('google_device_name')->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamp('received_at')->index();
                $table->timestamp('processed_at')->nullable()->index();
                $table->text('error')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_events');
        Schema::dropIfExists('mdm_device_commands');
        Schema::dropIfExists('mdm_enrollment_tokens');
        Schema::dropIfExists('mdm_devices');
        Schema::dropIfExists('mdm_policy_apps');
        Schema::dropIfExists('mdm_policies');
    }
};
