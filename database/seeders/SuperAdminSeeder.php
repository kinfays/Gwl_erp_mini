<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Region;
use App\Models\District;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;


class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {   
           $region = Region::firstOrCreate(
            ['region_name' => 'Accra West']
        );

         $district = District::firstOrCreate(
            ['district_name' => 'Accra West Regional Office', 'region_id' => 1]
        );

        $employee = Employee::firstOrCreate(
            ['staff_id' => '21475'],
            [
                'full_name' => 'Super Admin',
                'email' => 'superadmin@ml.local',
                'gender' => 'Male',
                'category' => 'Senior Staff',
            //    'job_title_id' => 1,
            //    'department_id' => 1,
            //    'region_id' => 1,
           //     'district_id' => 1,
                'location_type' => 'Region',
                'is_active' => true,
            ]
        );

       

        $user = User::firstOrCreate(
            ['staff_id' => '21475'],
            [
                'employee_id' => $employee->id,
                'email' => 'superadmin@ml.local',
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