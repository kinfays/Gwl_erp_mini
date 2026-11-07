<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per spell an employee holds a device (the Assets twin of vehicle_assignment_histories). The current holder is
 * the row with is_current = 1; "previous assigned to" is derived from the closed rows, never stored on the asset.
 *
 * is_current is 1 while a row is open and NULL once it is closed, with a unique index on (ict_asset_id, is_current): a
 * unique index treats NULLs as distinct on both SQLite and MySQL, so an asset can have any number of closed rows but at
 * most ONE open row, enforced by the database as well as by AssetAssignmentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ict_asset_assignments')) {
            return;
        }

        Schema::create('ict_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // Null only on rows rebuilt from the old columns where the date was never recorded (is_backfilled).
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            // reassigned | unassigned | corrected; null while the row is open.
            $table->string('end_reason', 20)->nullable();
            // Set when a row is marked corrected after the fact by the admin action (a fixed list of codes, no free text).
            $table->string('correction_code', 40)->nullable();
            $table->boolean('is_current')->nullable();
            $table->boolean('is_backfilled')->default(false);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ict_asset_id', 'is_current'], 'ict_asset_assignments_one_current');
            $table->index(['ict_asset_id', 'id'], 'ict_asset_assignments_asset_idx');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ict_asset_assignments');
    }
};
