<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id')->unique();
            $table->string('full_name');
            $table->string('gender');
            $table->string('category');
            $table->string('email')->unique();
            $table->foreignId('job_title_id')->constrained('job_titles')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('location_type');
            $table->date('date_of_birth')->nullable();
            $table->date('date_joined')->nullable();
            $table->string('present_appointment')->nullable();
            $table->string('role');
            $table->foreignId('department_id')->constrained('departments')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('unit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};