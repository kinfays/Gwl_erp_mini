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
            'ict_team',
            'transport_manager',
            'driver',
            'secretary',
            'receptionist',
        ];

        foreach ($roles as $role) {
            $displayName = match ($role) {
                'ict_team' => 'ICT Team',
                'transport_manager' => 'Transport Manager',
                default => Str::of($role)->replace('_', ' ')->title()->toString(),
            };

            Role::query()->updateOrCreate(
                ['name' => $role],
                [
                    'display_name' => $displayName,
                    'description' => $displayName.' system role',
                    'is_system' => true,
                ]
            );
        }
    }
}
