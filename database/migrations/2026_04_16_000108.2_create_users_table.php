<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->foreignId('employee_id')
        ->nullable()
        ->constrained('employees')
        ->nullOnDelete();
    $table->string('staff_id')->index();
    $table->string('email')->unique();
    $table->string('password');
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_login_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};