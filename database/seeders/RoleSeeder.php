<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super_admin',
            'admin',
            'hr_headoffice',
            'manager',
            'departmental_manager',
            'hr_region',
            'district_manager',
            'chief_manager',
            'regional_chief_manager',
            'employee',
            'receptionist',
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role],
                [
                    'display_name' => Str::of($role)->replace('_', ' ')->title()->toString(),
                    'description' => Str::of($role)->replace('_', ' ')->title()->append(' system role')->toString(),
                    'is_system' => true,
                ]
            );
        }
    }
}