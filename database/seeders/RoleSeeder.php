<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /**
     * Roles the ICT team may assign (Role::$ict_assignable), always within their own location scope. Everything
     * else — admin, super_admin, managing_director, ict_team, credit_union_* — is Global Admin / super_admin only.
     * The same list is applied to existing databases by migration 2026_09_30_000001.
     */
    public const ICT_ASSIGNABLE = [
        'employee',
        'manager',
        'departmental_manager',
        'district_manager',
        'chief_manager',
        'regional_chief_manager',
        'hr_region',
        'hr_headoffice',
        'secretary',
        'receptionist',
        'transport_manager',
        'driver',
    ];

    /** Roles whose definition only a super_admin may change and that can never be deleted (Role::$is_protected). */
    public const PROTECTED = ['super_admin', 'admin'];

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
            'managing_director',
            'employee',
            'ict_team',
            'transport_manager',
            'driver',
            'credit_union_officer',
            'credit_union_committee',
            'secretary',
            'receptionist',
        ];

        foreach ($roles as $role) {
            $displayName = match ($role) {
                'admin' => 'Global Admin',
                'ict_team' => 'ICT Team',
                'transport_manager' => 'Transport Manager',
                'credit_union_officer' => 'Credit Union Officer',
                'credit_union_committee' => 'Credit Union Committee',
                default => Str::of($role)->replace('_', ' ')->title()->toString(),
            };

            Role::query()->updateOrCreate(
                ['name' => $role],
                [
                    'display_name' => $displayName,
                    'description' => $displayName.' system role',
                    'is_system' => true,
                    'ict_assignable' => in_array($role, self::ICT_ASSIGNABLE, true),
                    'is_protected' => in_array($role, self::PROTECTED, true),
                ]
            );
        }
    }
}
