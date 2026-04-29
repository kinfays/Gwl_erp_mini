<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_name');
            $table->string('phone')->nullable();
            $table->foreignId('staff_id')->constrained('employees')->restrictOnDelete();
            $table->text('purpose')->nullable();
            $table->text('signature');
            $table->string('checkout_code', 3)->index();
            $table->timestamp('check_in_at')->useCurrent();
            $table->timestamp('check_out_at')->nullable();
            $table->enum('checked_out_by', ['self', 'receptionist', 'auto'])->nullable();
            $table->timestamps();

            $table->index(['check_in_at', 'check_out_at']);
            $table->index(['visitor_name', 'check_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
