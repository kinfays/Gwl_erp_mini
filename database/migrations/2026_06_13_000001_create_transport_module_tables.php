<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('type')->index();
                $table->string('brand');
                $table->string('model');
                $table->string('color')->nullable();
                $table->string('number_plate')->unique();
                $table->unsignedSmallInteger('year_purchased')->nullable();
                $table->boolean('is_pool_car')->default(true)->index();
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->string('driver_type')->default('self_drive')->index();
                $table->foreignId('assigned_driver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('current_mileage')->default(0);
                $table->unsignedInteger('maintenance_interval_km')->default(5000);
                $table->date('insurance_expiry_date')->nullable()->index();
                $table->date('road_worthiness_expiry_date')->nullable()->index();
                $table->string('status')->default('active')->index();
                $table->string('photo_path')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['assigned_user_id', 'status']);
                $table->index(['assigned_driver_id', 'status']);
            });
        }

        if (! Schema::hasTable('vehicle_assignment_histories')) {
            Schema::create('vehicle_assignment_histories', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable()->index();
                $table->timestamp('unassigned_at')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['vehicle_id', 'unassigned_at']);
                $table->index(['user_id', 'unassigned_at']);
                $table->index(['driver_id', 'unassigned_at']);
            });
        }

        if (! Schema::hasTable('mileage_logs')) {
            Schema::create('mileage_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('mileage_before');
                $table->unsignedInteger('mileage_after');
                $table->unsignedInteger('distance_driven')->default(0);
                $table->date('trip_date')->index();
                $table->string('trip_purpose');
                $table->timestamp('recorded_at')->nullable()->index();
                $table->timestamps();

                $table->index(['vehicle_id', 'trip_date']);
                $table->index(['driver_id', 'trip_date']);
            });
        }

        if (! Schema::hasTable('vehicle_issues')) {
            Schema::create('vehicle_issues', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('issue_types');
                $table->text('description');
                $table->string('severity')->default('low')->index();
                $table->string('status')->default('open')->index();
                $table->string('photo_path')->nullable();
                $table->timestamp('reported_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamps();

                $table->index(['vehicle_id', 'status']);
                $table->index(['reported_by', 'status']);
            });
        }

        if (! Schema::hasTable('maintenance_records')) {
            Schema::create('maintenance_records', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->string('maintenance_type')->index();
                $table->text('description')->nullable();
                $table->string('performed_by')->nullable();
                $table->unsignedInteger('mileage_at_service')->nullable();
                $table->decimal('cost', 15, 2)->default(0);
                $table->string('receipt_photo_path')->nullable();
                $table->date('service_date')->index();
                $table->date('next_service_date')->nullable()->index();
                $table->unsignedInteger('next_service_mileage')->nullable()->index();
                $table->timestamps();

                $table->index(['vehicle_id', 'service_date']);
            });
        }

        if (! Schema::hasTable('vehicle_expenses')) {
            Schema::create('vehicle_expenses', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->string('expense_type')->index();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('GHS');
                $table->text('description')->nullable();
                $table->string('receipt_photo_path')->nullable();
                $table->date('expense_date')->index();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['vehicle_id', 'expense_date']);
                $table->index(['recorded_by', 'expense_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_expenses');
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('vehicle_issues');
        Schema::dropIfExists('mileage_logs');
        Schema::dropIfExists('vehicle_assignment_histories');
        Schema::dropIfExists('vehicles');
    }
};
