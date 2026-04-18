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
        

        $user = User::query()->updateOrCreate(
            ['email' => 'fewuntomah@gwcl.com.gh'],
            [
                'full_name' => 'Faisal Ewuntomah',
                'staff_id' => '21475',
                'password' => Hash::make('Admin@12'),
                'is_active' => true,
            ]
        );

        $role = Role::query()->where('name', 'super_admin')->first();

        if ($role && ! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id);
        }
    
    }
}