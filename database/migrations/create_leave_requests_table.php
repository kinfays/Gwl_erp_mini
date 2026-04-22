<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('requester_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->string('leave_type'); // PHP enum enforcement

            $table->date('start_date');
            $table->date('end_date');

            $table->unsignedSmallInteger('total_days_applied');

            $table->text('leave_details')->nullable();

            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->text('manager_comments')->nullable();

            $table->string('manager_recommendation')->default('Pending'); // Pending/Recommended/Rejected

            $table->string('leave_status')->default('Planned'); // Planned/Pending Approval/Approved/Denied

            $table->foreignId('approved_by_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->text('chiefManager_comments')->nullable();

            $table->year('request_year');

            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Spec had "Region (nullable)" — using FK region_id is best for scoping
            $table->foreignId('region_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('file_attachment')->nullable();

            $table->timestamps();

            $table->index(['leave_status', 'manager_recommendation'], 'lr_status_reco_idx');
            $table->index(['request_year', 'leave_type'], 'lr_year_type_idx');
            $table->index(['start_date', 'end_date'], 'lr_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};