<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $superAdminUserIds = DB::table('users')
            ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->pluck('users.id');

        if ($superAdminUserIds->isEmpty()) {
            return;
        }

        DB::table('users')
            ->whereIn('id', $superAdminUserIds)
            ->update(['employee_id' => null]);
    }

    public function down(): void
    {
        //
    }
};
