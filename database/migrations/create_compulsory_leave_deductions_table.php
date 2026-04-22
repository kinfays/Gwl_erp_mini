<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('compulsory_leave_deductions', function (Blueprint $table) {
            $table->id();

            $table->year('year');
            $table->unsignedTinyInteger('deduction_days');

            $table->foreignId('applied_by_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->json('applies_to_categories');

            $table->string('excludes_location_type')->nullable(); // HeadOffice/Region/District

            $table->text('notes')->nullable();

            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index('year', 'cld_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compulsory_leave_deductions');
    }
};