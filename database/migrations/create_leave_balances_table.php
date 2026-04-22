<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->string('leave_type'); // enforced via PHP enum in model layer

            $table->unsignedSmallInteger('entitle_days')->default(0);
            $table->unsignedSmallInteger('used_days')->default(0);
            $table->unsignedSmallInteger('remaining_days')->default(0);

            $table->unsignedSmallInteger('carry_over_days')->default(0);
            $table->date('carry_over_expired_date')->nullable();

            $table->year('current_year');

            $table->foreignId('district_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('region_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamps();

            // One balance per staff, type, year
            $table->unique(['staff_id', 'leave_type', 'current_year'], 'lb_staff_type_year_unique');

            $table->index(['current_year', 'leave_type'], 'lb_year_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};