<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            // Safer FK: link by employees.id
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->enum('leave_type', ['Annual', 'Casual', 'Paternity', 'Maternity', 'Sick']);

            $table->unsignedSmallInteger('entitle_days');
            $table->unsignedSmallInteger('used_days')->default(0);
            $table->unsignedSmallInteger('remaining_days')->default(0);

            $table->unsignedSmallInteger('carry_over_days')->default(0);
            $table->date('carry_over_expired_date')->nullable();

            $table->year('current_year');

            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();

            $table->timestamps();

            // One balance per employee per type per year
            $table->unique(['employee_id', 'leave_type', 'current_year']);
            $table->index(['region_id', 'district_id', 'current_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};