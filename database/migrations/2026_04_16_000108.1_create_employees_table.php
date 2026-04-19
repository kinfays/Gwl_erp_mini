<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id')->unique();
            $table->string('full_name');
            $table->enum('gender', ['Male', 'Female']);
            $table->enum('category', [
                'Senior Staff',
                'Junior Staff',
                'Management',
                'Senior Management',
                'Charwoman'
            ]);
            $table->string('email')->unique();

         //   $table->foreignId('job_title_id')->constrained();
         //   $table->foreignId('department_id')->constrained();
         //   $table->foreignId('region_id')->constrained();
         //   $table->foreignId('district_id')->constrained();

            $table->enum('location_type', ['HeadOffice', 'Region', 'District']);
            $table->date('date_of_birth')->nullable();
            $table->date('date_joined')->nullable();
            $table->string('present_appointment')->nullable();
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