<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ict_asset_transfers')) {
            return;
        }

        Schema::create('ict_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
            $table->string('transfer_type')->index();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('from_district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignId('to_district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignId('related_asset_id')->nullable()->constrained('ict_assets')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['ict_asset_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ict_asset_transfers');
    }
};
