<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UacRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()->pluck('id', 'name');

        $map = [
            User::ROLE_ADMIN => [
                'leave.view_own',
                'leave.apply',
                'leave.view_zone',
                'leave.approve_recommend',
                'leave.approve_final',
                'leave.manage_compulsory',
                'leave.export',
                'leave.delete_own',
                'uac.view_users',
                'uac.create_users',
                'uac.edit_users',
                'uac.assign_roles',
                'uac.manage_roles',
                'uac.manage_permissions',
                'uac.import_data',
            ],
            User::ROLE_ICT_TEAM => [
                'uac.view_users',
                'uac.create_users',
                'uac.edit_users',
                'uac.assign_roles',
            ],
        ];

        foreach ($map as $roleName => $slugs) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $role->permissions()->sync(
                collect($slugs)
                    ->map(fn (string $slug) => $permissions[$slug] ?? null)
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}
