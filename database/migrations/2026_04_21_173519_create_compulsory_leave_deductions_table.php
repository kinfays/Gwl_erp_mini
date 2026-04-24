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

            $table->foreignId('applied_by_id')->constrained('employees')->restrictOnDelete();

            // categories that are affected (JSON array)
            $table->json('applies_to_categories');

            // exclude location type (nullable)
            $table->enum('excludes_location_type', ['HeadOffice', 'Region', 'District'])->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('applied_at')->useCurrent();

            $table->timestamps();

            $table->index(['year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compulsory_leave_deductions');
    }
};
