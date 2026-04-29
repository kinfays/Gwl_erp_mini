<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;


class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {   
        $user = User::firstOrCreate(
            ['staff_id' => '21475'],
            [
                'employee_id' => null,
                'email' => 'superadmin@ml.local',
                'password' => Hash::make('Admin@12'),
                'is_active' => true,
            ]
        );

        if ($user->employee_id !== null) {
            $user->forceFill(['employee_id' => null])->save();
        }

        $role = Role::query()->where('name', 'super_admin')->first();

        if ($role && ! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id);
        }
    
    }
}
