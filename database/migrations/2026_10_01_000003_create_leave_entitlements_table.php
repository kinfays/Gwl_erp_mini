<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee per leave year: how many Annual days the person is entitled to and how that is made up.
 * It is the source of the year's Annual leave_balances.entitle_days (LeaveBalanceService reads it), not a second
 * balance: days taken are still counted only on the balance.
 *
 *   gross_days       from the grade and years of service (LeaveEntitlementCalculator)
 *   compulsory_days  taken off for compulsory leave (compulsory_leave_periods)
 *   net_days         gross - compulsory: the days actually available to take
 *
 * grade_snapshot / tenure_years record what the figures were worked out from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_entitlements')) {
            return;
        }

        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('grade_snapshot', 30)->nullable();
            $table->unsignedSmallInteger('tenure_years')->nullable();
            $table->unsignedSmallInteger('gross_days')->default(0);
            $table->unsignedSmallInteger('compulsory_days')->default(0);
            $table->unsignedSmallInteger('net_days')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'year']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
    }
};
