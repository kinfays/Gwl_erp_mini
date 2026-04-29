<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'full_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('full_name')->nullable()->after('staff_id');
            });
        }

        $users = DB::table('users')
            ->whereNull('full_name')
            ->get(['id', 'employee_id', 'staff_id']);

        foreach ($users as $user) {
            $fullName = null;

            if ($user->employee_id) {
                $fullName = DB::table('employees')->where('id', $user->employee_id)->value('full_name');
            }

            if (! $fullName && $user->staff_id) {
                $fullName = DB::table('employees')->where('staff_id', $user->staff_id)->value('full_name');
            }

            if ($fullName) {
                DB::table('users')->where('id', $user->id)->update([
                    'full_name' => $fullName,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'full_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('full_name');
            });
        }
    }
};
