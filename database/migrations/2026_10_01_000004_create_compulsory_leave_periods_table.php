<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compulsory leave of one year: how many days come off the gross annual entitlement of Head Office and regional
 * office staff (days, default 11), and — for information, and to keep people from booking leave across it — when it
 * starts and when staff return. It is not a leave request. A year with no row uses gwl.leave_compulsory_default_days.
 *
 * Replaces the older compulsory_leave_deductions, which charged the days to used_days for chosen categories; that
 * table stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compulsory_leave_periods')) {
            return;
        }

        Schema::create('compulsory_leave_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedTinyInteger('days')->default(11);
            $table->date('start_date')->nullable();
            $table->date('resume_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compulsory_leave_periods');
    }
};
