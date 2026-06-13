<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ict_asset_models')) {
            Schema::create('ict_asset_models', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('category')->index();
                $table->string('manufacturer')->nullable()->index();
                $table->string('image_path')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ict_assets')) {
            Schema::create('ict_assets', function (Blueprint $table) {
                $table->id();
                $table->string('asset_name');
                $table->string('serial_number')->nullable()->unique();
                $table->string('asset_type')->index();
                $table->foreignId('ict_asset_model_id')->nullable()->constrained('ict_asset_models')->nullOnDelete();
                $table->string('status')->default('Active')->index();

                $table->foreignId('assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('previous_assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();

                $table->string('device_ip')->nullable();
                $table->string('mac_address')->nullable()->index();
                $table->string('hostname')->nullable()->index();

                $table->string('os_name')->nullable();
                $table->string('os_version')->nullable();
                $table->string('cpu_name')->nullable();
                $table->decimal('ram_gb', 6, 2)->nullable();
                $table->string('bios_version')->nullable();
                $table->string('manufacturer')->nullable();
                $table->string('model_name')->nullable();
                $table->timestamp('last_boot_at')->nullable();

                $table->date('purchased_at')->nullable();
                $table->date('warranty_expires_at')->nullable();
                $table->text('notes')->nullable();

                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('agent_last_report_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ict_asset_maintenances')) {
            Schema::create('ict_asset_maintenances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
                $table->string('maintenance_type')->index();
                $table->string('status')->default('Open')->index();
                $table->date('completion_date')->nullable();
                $table->string('technician')->nullable();
                $table->string('location')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ict_asset_issue_reports')) {
            Schema::create('ict_asset_issue_reports', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('issue_type')->index();
                $table->text('reason')->nullable();
                $table->string('status')->default('Open')->index();
                $table->date('date_solved')->nullable();

                $table->foreignId('linked_asset_id')->nullable()->constrained('ict_assets')->nullOnDelete();
                $table->foreignId('reporting_region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->foreignId('reporting_district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_reports')) {
            Schema::create('agent_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('ict_asset_id')->nullable()->constrained('ict_assets')->nullOnDelete();
                $table->foreignId('reporting_region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->boolean('matched')->default(false)->index();
                $table->string('hostname')->nullable()->index();
                $table->string('serial_number')->nullable()->index();
                $table->string('mac_address')->nullable()->index();
                $table->string('os_name')->nullable();
                $table->string('os_version')->nullable();
                $table->string('cpu_name')->nullable();
                $table->decimal('ram_gb', 6, 2)->nullable();
                $table->string('logged_on_user')->nullable();
                $table->string('last_boot_time')->nullable();
                $table->string('manufacturer')->nullable();
                $table->string('model')->nullable();
                $table->string('bios_version')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('reported_at')->nullable()->index();
                $table->timestamp('linked_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_reports');
        Schema::dropIfExists('ict_asset_issue_reports');
        Schema::dropIfExists('ict_asset_maintenances');
        Schema::dropIfExists('ict_assets');
        Schema::dropIfExists('ict_asset_models');
    }
};

