<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            // Requester + approval chain all reference employees.id
            $table->foreignId('requester_id')->constrained('employees')->restrictOnDelete();

            $table->enum('leave_type', ['Annual', 'Casual', 'Paternity', 'Maternity', 'Sick']);

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('total_days_applied');

            $table->text('leave_details')->nullable();

            // recommender (manager) and final approver (chief manager)
            $table->foreignId('manager_id')->constrained('employees')->restrictOnDelete();
            $table->text('manager_comments')->nullable();

            $table->enum('manager_recommendation', ['Pending', 'Recommended', 'Rejected'])->default('Pending');

            $table->enum('leave_status', ['Planned', 'Pending Approval', 'Approved', 'Denied'])->default('Planned');

            $table->foreignId('approved_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('chiefManager_comments')->nullable();

            $table->year('request_year');

            $table->foreignId('department_id')->constrained()->restrictOnDelete();

            // store region for scoping (nullable per spec)
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();

            $table->string('file_attachment')->nullable();

            $table->timestamps();

           $table->index(['leave_status', 'manager_recommendation', 'request_year'], 'lr_status_mgrrec_year_idx');
           $table->index(['requester_id', 'request_year'], 'lr_requester_year_idx');
           $table->index(['region_id', 'department_id'], 'lr_region_dept_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};